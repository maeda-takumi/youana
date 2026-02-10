<?php
declare(strict_types=1);

/**
 * check_below_avg_followup_missing_and_notify.php
 *
 * 目的:
 * - 下回り通知済み（data/notified_below_month_avg.json）のデータについて、
 *   その日付の翌日が today を過ぎたタイミングでフォローアップチェックを行う。
 * - metrics の下記3項目のうち1つでも未入力(null/空文字)なら通知する。
 *   - 編集担当
 *   - 今回の改善箇所
 *   - 改善の成否/次回の改善
 * - 通知済みの {sheet|date} は再通知しない。
 */

date_default_timezone_set('Asia/Tokyo');

$baseDir = dirname(__DIR__);
$jsonPath = $baseDir . '/data/date_blocks.json';
$notifiedBelowPath = $baseDir . '/data/notified_below_month_avg.json';
$followupNotifiedPath = $baseDir . '/data/notified_missing_improvement_after_below_avg.json';
$chatworkCfgPath = $baseDir . '/config/chatwork.php';

if (php_sapi_name() !== 'cli') {
  header('Content-Type: text/plain; charset=UTF-8');
}

const REQUIRED_KEYS = [
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

function loadOptionalJson(string $path): array {
  if (!file_exists($path)) return [];
  $raw = file_get_contents($path);
  if ($raw === false || trim($raw) === '') return [];
  $data = json_decode($raw, true);
  return is_array($data) ? $data : [];
}

function saveJsonPretty(string $path, array $data): void {
  $dir = dirname($path);
  if (!is_dir($dir)) {
    if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
      throw new RuntimeException("Failed to create dir: {$dir}");
    }
  }

  $json = json_encode(
    $data,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
  );
  if ($json === false) {
    throw new RuntimeException('Failed to encode JSON');
  }

  if (file_put_contents($path, $json . PHP_EOL, LOCK_EX) === false) {
    throw new RuntimeException("Failed to write JSON: {$path}");
  }
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
    throw new RuntimeException('ChatWork設定がありません。config/chatwork.php か環境変数 CHATWORK_TOKEN/CHATWORK_ROOM_ID を設定してください。');
  }

  return ['TOKEN' => $token, 'ROOM_ID' => $roomId];
}

function sendChatwork(string $token, string $roomId, string $message): void {
  $url = 'https://api.chatwork.com/v2/rooms/' . rawurlencode($roomId) . '/messages';
  $postFields = http_build_query(['body' => $message]);

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $postFields,
    CURLOPT_HTTPHEADER     => [
      "X-ChatWorkToken: {$token}",
      'Content-Type: application/x-www-form-urlencoded',
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

function dateLabelToDate(string $label, int $year): ?DateTimeImmutable {
  if (!preg_match('/^\s*(\d{1,2})\s*月\s*(\d{1,2})\s*日\s*$/u', $label, $m)) return null;
  $mo = (int)$m[1];
  $d  = (int)$m[2];
  if (!checkdate($mo, $d, $year)) return null;
  return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $mo, $d));
}

function isNullOrBlank(mixed $v): bool {
  if ($v === null) return true;
  if (is_string($v)) return trim($v) === '';
  return false;
}

function parseSheetDateKey(string $key): ?array {
  $pos = mb_strrpos($key, '|', 0, 'UTF-8');
  if ($pos === false) return null;

  $sheet = trim(mb_substr($key, 0, $pos, 'UTF-8'));
  $date = trim(mb_substr($key, $pos + 1, null, 'UTF-8'));

  if ($sheet === '' || $date === '') return null;
  return [$sheet, $date];
}

try {
  $data = loadJson($jsonPath);
  $notifiedBelow = loadOptionalJson($notifiedBelowPath);
  $followupNotified = loadOptionalJson($followupNotifiedPath);

  $sheets = $data['sheets'] ?? null;
  if (!is_array($sheets)) throw new RuntimeException('date_blocks.json の形式が不正です（sheetsがありません）');

  $year = (int)(new DateTimeImmutable('today'))->format('Y');
  $today = new DateTimeImmutable('today');

  $alerts = [];
  $newFollowupNotified = [];

  foreach ($notifiedBelow as $notifyKey => $meta) {
    if (!is_string($notifyKey) || trim($notifyKey) === '') continue;

    if (!empty($followupNotified[$notifyKey])) {
      continue;
    }

    $parsed = parseSheetDateKey($notifyKey);
    if ($parsed === null) continue;

    [$sheetName, $dateLabel] = $parsed;

    $notifiedDate = dateLabelToDate($dateLabel, $year);
    if (!$notifiedDate) continue;

    $nextDate = $notifiedDate->modify('+1 day');
    if ($nextDate >= $today) {
      continue;
    }

    $blocks = $sheets[$sheetName] ?? null;
    if (!is_array($blocks)) continue;

    $hasMissing = false;
    foreach ($blocks as $block) {
      if (!is_array($block)) continue;
      $label = (string)($block['date'] ?? '');
      if ($label !== $dateLabel) continue;

      $metrics = $block['metrics'] ?? null;
      if (!is_array($metrics)) continue;

      foreach (REQUIRED_KEYS as $requiredKey) {
        if (isNullOrBlank($metrics[$requiredKey] ?? null)) {
          $hasMissing = true;
          break 2;
        }
      }
    }

    if (!$hasMissing) {
      continue;
    }

    $alerts[$notifyKey] = [
      'sheet' => $sheetName,
      'date'  => $dateLabel,
    ];

    $newFollowupNotified[$notifyKey] = [
      'sheet' => $sheetName,
      'date' => $dateLabel,
      'notified_at' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
    ];
  }

  if (count($alerts) === 0) {
    echo 'OK: 通知対象なし（下回り通知済みの翌日経過データに未入力なし）' . PHP_EOL;
    exit(0);
  }

  $lines = [];
  $lines[] = '[info][title]下回り通知後の改善項目未入力チェック[/title]';
  $lines[] = '以下のデータで改善入力が未完了です。';
  $lines[] = '（編集担当 / 今回の改善箇所 / 改善の成否/次回の改善 のいずれかが未入力）';
  $lines[] = '';

  $bySheet = [];
  foreach ($alerts as $entry) {
    $sheet = (string)$entry['sheet'];
    $date = (string)$entry['date'];
    if (!isset($bySheet[$sheet])) $bySheet[$sheet] = [];
    $bySheet[$sheet][$date] = true;
  }

  foreach ($bySheet as $sheetName => $dateSet) {
    $dates = array_keys($dateSet);
    usort($dates, function (string $a, string $b) use ($year): int {
      $da = dateLabelToDate($a, $year);
      $db = dateLabelToDate($b, $year);
      if (!$da && !$db) return strcmp($a, $b);
      if (!$da) return 1;
      if (!$db) return -1;
      return $da <=> $db;
    });

    $lines[] = '■ ' . $sheetName;
    foreach ($dates as $d) {
      $lines[] = '・' . $d;
    }
    $lines[] = '';
  }

  $lines[] = '[/info]';

  $cw = getChatworkConfig($chatworkCfgPath);
  $message = implode(PHP_EOL, $lines);
  sendChatwork($cw['TOKEN'], $cw['ROOM_ID'], $message);

  $merged = $followupNotified;
  foreach ($newFollowupNotified as $k => $v) {
    $merged[$k] = $v;
  }
  saveJsonPretty($followupNotifiedPath, $merged);

  echo '通知しました: ' . count($alerts) . '件' . PHP_EOL;
} catch (Throwable $e) {
  write_err('[ERROR] ' . $e->getMessage());
  exit(1);
}
