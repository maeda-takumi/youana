<?php
declare(strict_types=1);

/**
 * check_missing_and_notify.php
 * - data/date_blocks.json を読み込み
 * - 今日(Asia/Tokyo) - 3日 以下（<=）の日付のデータを全てチェック
 * - metrics のうち（動画タイトル / ビデオID）を除く項目で空があれば未入力扱い
 * - 通知は「未入力があった日付（シート名 + 日付）」のみ（項目名は出さない）
 */

date_default_timezone_set('Asia/Tokyo');

$baseDir = dirname(__DIR__);
$jsonPath = $baseDir . '/data/date_blocks.json';
$chatworkCfgPath = $baseDir . '/config/chatwork.php';

if (php_sapi_name() !== 'cli') {
  header('Content-Type: text/plain; charset=UTF-8');
}

/** 未入力判定から除外するキー */
const IGNORE_EMPTY_KEYS = [
  '動画タイトル',
  'ビデオID',
  '編集担当',
  '今回の改善箇所',
  '改善の成否/次回の改善',
];

function write_err(string $msg): void {
  if (defined('STDERR')) fwrite(STDERR, $msg . PHP_EOL);
  else { error_log($msg); echo $msg . PHP_EOL; }
}

function loadJson(string $path): array {
  if (!file_exists($path)) throw new RuntimeException("JSON not found: {$path}");
  $raw = file_get_contents($path);
  if ($raw === false) throw new RuntimeException("Failed to read: {$path}");
  $data = json_decode($raw, true);
  if (!is_array($data)) throw new RuntimeException("Invalid JSON: {$path}");
  return $data;
}

function getChatworkConfig(string $cfgPath): array {
  $token = '';
  $roomId = '';

  if (file_exists($cfgPath)) {
    $cfg = require $cfgPath;
    if (is_array($cfg)) {
      $token  = (string)($cfg['TOKEN'] ?? '');
      $roomId = (string)($cfg['ROOM_ID'] ?? '');
    }
  }

  if ($token === '')  $token = (string)getenv('CHATWORK_TOKEN');
  if ($roomId === '') $roomId = (string)getenv('CHATWORK_ROOM_ID');

  if ($token === '' || $roomId === '') {
    throw new RuntimeException("ChatWork設定がありません。config/chatwork.php か環境変数 CHATWORK_TOKEN/CHATWORK_ROOM_ID を設定してください。");
  }

  return ['TOKEN' => $token, 'ROOM_ID' => $roomId];
}

/**
 * 今日-2日（境界日）を DateTimeImmutable で返す（00:00基準）
 */
function cutoffDate(): DateTimeImmutable {
  return (new DateTimeImmutable('today'))->modify('-3 day');
}

/**
 * "m月d日" 文字列を、今年の DateTime に変換（00:00）
 * - jsonには年が無いので「今年」として扱う（運用が跨年するなら後で改善）
 */
function dateLabelToDate(string $label, int $year): ?DateTimeImmutable {
  if (!preg_match('/^\s*(\d{1,2})\s*月\s*(\d{1,2})\s*日\s*$/u', $label, $m)) return null;
  $mo = (int)$m[1];
  $d  = (int)$m[2];
  if (!checkdate($mo, $d, $year)) return null;
  return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $mo, $d));
}

/**
 * 値が空か判定（null/""/空白のみ を空扱い）
 */
function isEmptyValue(mixed $v): bool {
  if ($v === null) return true;
  if (is_string($v)) return trim($v) === '';
  return false;
}

/**
 * ChatWork送信
 */
function sendChatwork(string $token, string $roomId, string $message): void {
  $url = "https://api.chatwork.com/v2/rooms/" . rawurlencode($roomId) . "/messages";
  $postFields = http_build_query(['body' => $message]);

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $postFields,
    CURLOPT_HTTPHEADER     => [
      "X-ChatWorkToken: {$token}",
      "Content-Type: application/x-www-form-urlencoded",
    ],
    CURLOPT_TIMEOUT        => 30,
  ]);

  $resp = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err  = curl_error($ch);
  curl_close($ch);

  if ($resp === false) throw new RuntimeException("ChatWork curl error: {$err}");
  if ($code < 200 || $code >= 300) {
    throw new RuntimeException("ChatWork API failed (HTTP {$code}): {$resp}");
  }
}

try {
  $data = loadJson($jsonPath);
  $cw = getChatworkConfig($chatworkCfgPath);

  $sheets = $data['sheets'] ?? null;
  if (!is_array($sheets)) throw new RuntimeException("date_blocks.json の形式が不正です（sheetsがありません）");

  $cutoff = cutoffDate();
  $year = (int)(new DateTimeImmutable('today'))->format('Y');

  // 未入力があった (sheet => [dateLabel => true]) を集める
  $missingMap = [];

  foreach ($sheets as $sheetName => $blocks) {
    if (!is_array($blocks)) continue;

    foreach ($blocks as $b) {
      if (!is_array($b)) continue;

      $label = (string)($b['date'] ?? '');
      if ($label === '') continue;

      $dt = dateLabelToDate($label, $year);
      if (!$dt) continue;

      // 対象: cutoff 以下（<=）
      if ($dt > $cutoff) continue;

      $metrics = $b['metrics'] ?? null;
      if (!is_array($metrics)) continue;

      $hasMissing = false;
      foreach ($metrics as $k => $v) {
        $k = (string)$k;

        // 除外キーは未入力判定しない
        if (in_array($k, IGNORE_EMPTY_KEYS, true)) continue;

        if (isEmptyValue($v)) {
          $hasMissing = true;
          break; // 項目名は不要なので見つけたら即OK
        }
      }

      if ($hasMissing) {
        if (!isset($missingMap[$sheetName])) $missingMap[$sheetName] = [];
        $missingMap[$sheetName][$label] = true;
      }
    }
  }

  if (count($missingMap) === 0) {
    echo "OK: 未入力なし（対象: {$cutoff->format('Y-m-d')} 以下）" . PHP_EOL;
    exit(0);
  }

  // メッセージ整形（シートごとに日付だけ）
  $lines = [];
  $lines[] = "[info][title]未入力チェック（対象: {$cutoff->format('Y-m-d')} 以下）[/title]";
  $lines[] = "未入力がある日付が見つかりました。";
  $lines[] = "※「動画タイトル」「ビデオID」は未入力判定から除外しています。";
  $lines[] = "";

  foreach ($missingMap as $sheetName => $dateSet) {
    $dates = array_keys($dateSet);

    // 月日ソート
    usort($dates, function(string $a, string $b): int {
      preg_match('/(\d{1,2})月(\d{1,2})日/u', $a, $am);
      preg_match('/(\d{1,2})月(\d{1,2})日/u', $b, $bm);
      $aM = (int)($am[1] ?? 0); $aD = (int)($am[2] ?? 0);
      $bM = (int)($bm[1] ?? 0); $bD = (int)($bm[2] ?? 0);
      return ($aM <=> $bM) ?: ($aD <=> $bD);
    });

    $lines[] = "■ {$sheetName}";
    foreach ($dates as $d) {
      $lines[] = "・{$d}";
    }
    $lines[] = "";
  }

  $lines[] = "[/info]";
  $msg = implode("\n", $lines);

  sendChatwork($cw['TOKEN'], $cw['ROOM_ID'], $msg);

  echo "NOTIFIED: sheets=" . count($missingMap) . PHP_EOL;
  exit(0);

} catch (Throwable $e) {
  write_err('ERROR: ' . $e->getMessage());
  exit(1);
}
