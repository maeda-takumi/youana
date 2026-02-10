<?php
declare(strict_types=1);

/**
 * check_below_month_avg_and_notify.php
 * - data/date_blocks.json を読み込み
 * - 各シートの「直近日付（最大 m月d日）」のデータを対象
 * - 同じ月の全データを母集団にして項目ごとの月平均を計算（空/非数値は除外）
 * - 直近値 < 月平均 の項目を通知
 * - 通知済みキー（sheetName + date）を data/notified_below_month_avg.json で永続管理
 */

date_default_timezone_set('Asia/Tokyo');

$baseDir = dirname(__DIR__);
$jsonPath = $baseDir . '/data/date_blocks.json';
$chatworkCfgPath = $baseDir . '/config/chatwork.php';
$notifiedPath = $baseDir . '/data/notified_below_month_avg.json';

if (php_sapi_name() !== 'cli') {
  header('Content-Type: text/plain; charset=UTF-8');
}

const IGNORE_KEYS = [
  '動画タイトル',
  'ビデオID',
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
  if ($raw === false) throw new RuntimeException("Failed to read: {$path}");
  $data = json_decode($raw, true);
  if (!is_array($data)) return [];
  return $data;
}

function saveJson(string $path, array $data): void {
  $dir = dirname($path);
  if (!is_dir($dir)) {
    if (!mkdir($dir, 0775, true)) {
      throw new RuntimeException("Failed to create dir: {$dir}");
    }
  }
  $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
  if ($json === false) throw new RuntimeException('Failed to encode JSON');
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
    throw new RuntimeException("ChatWork設定がありません。config/chatwork.php か環境変数 CHATWORK_TOKEN/CHATWORK_ROOM_ID を設定してください。");
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

function parseMetricNumber(mixed $v): ?float {
  if ($v === null) return null;
  if (is_int($v) || is_float($v)) return (float)$v;
  if (!is_string($v)) return null;

  $s = trim($v);
  if ($s === '') return null;

  $s = mb_convert_kana($s, 'as', 'UTF-8');
  $s = str_replace(',', '', $s);


  // 0:42 / 01:23:45 のような時間表記は秒に正規化
  if (preg_match('/^(\d+):(\d{1,2})(?::(\d{1,2}))?$/', $s, $m)) {
    if (isset($m[3]) && $m[3] !== '') {
      return ((float)$m[1] * 3600.0) + ((float)$m[2] * 60.0) + (float)$m[3];
    }
    return ((float)$m[1] * 60.0) + (float)$m[2];
  }

  // 1時間23分45秒 / 12分34秒 のような時間表記は秒に正規化
  if (preg_match('/^\s*(?:(\d+(?:\.\d+)?)\s*時間)?\s*(?:(\d+(?:\.\d+)?)\s*分)?\s*(?:(\d+(?:\.\d+)?)\s*秒)?\s*$/u', $s, $m)) {
    $h = (isset($m[1]) && $m[1] !== '') ? (float)$m[1] : 0.0;
    $min = (isset($m[2]) && $m[2] !== '') ? (float)$m[2] : 0.0;
    $sec = (isset($m[3]) && $m[3] !== '') ? (float)$m[3] : 0.0;
    if ($h > 0.0 || $min > 0.0 || $sec > 0.0) {
      return ($h * 3600.0) + ($min * 60.0) + $sec;
    }
  }
  if (!preg_match('/-?\d+(?:\.\d+)?/', $s, $m)) {
    return null;
  }

  return (float)$m[0];
}

function formatNum(float $n): string {
  return number_format($n, 2, '.', '');
}

function formatSecondsToHms(float $seconds): string {
  $total = (int)round($seconds);
  if ($total < 0) $total = 0;

  $h = intdiv($total, 3600);
  $m = intdiv($total % 3600, 60);
  $s = $total % 60;

  return sprintf('%02d:%02d:%02d', $h, $m, $s);
}

function formatMetricValue(string $metricName, float $value): string {
  
  $hmsMetrics = [
    '総動画時間',
    '24時間平均視聴時間',
    '48時間平均視聴時間',
  ];

  if (in_array($metricName, $hmsMetrics, true)) {
    return formatSecondsToHms($value);
  }
  $suffix = mb_strpos($metricName, '率') !== false ? '%' : '';
  return formatNum($value) . $suffix;
}

try {
  $data = loadJson($jsonPath);
  $cw = getChatworkConfig($chatworkCfgPath);
  $notified = loadOptionalJson($notifiedPath);

  $sheets = $data['sheets'] ?? null;
  if (!is_array($sheets)) throw new RuntimeException('date_blocks.json の形式が不正です（sheetsがありません）');

  $year = (int)(new DateTimeImmutable('today'))->format('Y');

  $alertsBySheet = [];
  $newNotifiedKeys = [];

  foreach ($sheets as $sheetName => $blocks) {
    if (!is_array($blocks) || count($blocks) === 0) continue;

    // 直近日付（最大）を見つける
    $latestDate = null;
    $latestLabel = null;
    foreach ($blocks as $b) {
      if (!is_array($b)) continue;
      $label = (string)($b['date'] ?? '');
      if ($label === '') continue;
      $dt = dateLabelToDate($label, $year);
      if (!$dt) continue;
      if ($latestDate === null || $dt > $latestDate) {
        $latestDate = $dt;
        $latestLabel = $label;
      }
    }

    if ($latestDate === null || $latestLabel === null) continue;

    $notifyKey = (string)$sheetName . '|' . $latestLabel;
    if (!empty($notified[$notifyKey])) {
      continue;
    }

    $month = (int)$latestDate->format('n');

    // 同月データを収集
    $monthBlocks = [];
    $latestBlocks = [];

    foreach ($blocks as $b) {
      if (!is_array($b)) continue;
      $label = (string)($b['date'] ?? '');
      if ($label === '') continue;
      $dt = dateLabelToDate($label, $year);
      if (!$dt) continue;
      if ((int)$dt->format('n') === $month) {
        $monthBlocks[] = $b;
      }
      if ($label === $latestLabel) {
        $latestBlocks[] = $b; // 同日の複数ブロックは別扱い
      }
    }

    if (count($monthBlocks) === 0 || count($latestBlocks) === 0) continue;

    $sheetAlerts = [];

    foreach ($latestBlocks as $blockIdx => $latestBlock) {
      $metrics = $latestBlock['metrics'] ?? null;
      if (!is_array($metrics)) continue;

      $entry = [
        'date' => $latestLabel,
        'a1'   => (string)($latestBlock['a1'] ?? ''),
        'items' => [],
      ];

      foreach ($metrics as $metricName => $latestRaw) {
        $metricName = (string)$metricName;
        if (in_array($metricName, IGNORE_KEYS, true)) continue;

        $latestVal = parseMetricNumber($latestRaw);
        if ($latestVal === null) continue;

        $sum = 0.0;
        $cnt = 0;
        foreach ($monthBlocks as $mb) {
          $mm = $mb['metrics'] ?? null;
          if (!is_array($mm) || !array_key_exists($metricName, $mm)) continue;
          $v = parseMetricNumber($mm[$metricName]);
          if ($v === null) continue;
          $sum += $v;
          $cnt++;
        }

        if ($cnt === 0) continue;
        $avg = $sum / $cnt;

        if ($latestVal < $avg) {

          $entry['items'][] = [
            'metric' => $metricName,
            'latest' => $latestVal,
            'avg'    => $avg,
          ];
        }
      }

      if (count($entry['items']) > 0) {
        // 同日複数ブロックを区別しやすくする
        $entry['block_index'] = $blockIdx + 1;
        $sheetAlerts[] = $entry;
      }
    }

    if (count($sheetAlerts) > 0) {
      $alertsBySheet[$sheetName] = $sheetAlerts;
      $newNotifiedKeys[$notifyKey] = [
        'sheet' => (string)$sheetName,
        'date'  => $latestLabel,
        'notified_at' => date(DATE_ATOM),
      ];
    }
  }

  if (count($alertsBySheet) === 0) {
    echo 'OK: 条件該当なし（直近値<同月平均）' . PHP_EOL;
    exit(0);
  }

  $lines = [];
  $lines[] = '[info][title]直近値が同月平均を下回った項目通知[/title]';
//   $lines[] = '対象: 各シートの直近日付（最大 m月d日）';
//   $lines[] = '条件: 直近値 < 同月平均（空/非数値は平均計算から除外）';
  $lines[] = '';

  foreach ($alertsBySheet as $sheetName => $entries) {
    $lines[] = '■ ' . $sheetName;
    foreach ($entries as $e) {
      $head = '・' . $e['date'];
    //   if (($e['a1'] ?? '') !== '') {
    //     $head .= ' (A1: ' . $e['a1'] . ')';
    //   }
    //   if (($e['block_index'] ?? 0) > 1) {
    //     $head .= ' [block ' . (int)$e['block_index'] . ']';
    //   }
      $lines[] = $head;

      foreach ($e['items'] as $it) {
        $lines[] = sprintf(
          '  - %s: 実測=%s / 月平均=%s',
          $it['metric'],
          formatMetricValue((string)$it['metric'], (float)$it['latest']),
          formatMetricValue((string)$it['metric'], (float)$it['avg'])
        );
      }
    }
    $lines[] = '';
  }

//   $lines[] = '※通知済みキーは sheetName + date で永続管理';
  $lines[] = '[/info]';

  sendChatwork($cw['TOKEN'], $cw['ROOM_ID'], implode("\n", $lines));

  foreach ($newNotifiedKeys as $k => $v) {
    $notified[$k] = $v;
  }
  saveJson($notifiedPath, $notified);

  echo 'NOTIFIED: sheets=' . count($alertsBySheet) . ' keys=' . count($newNotifiedKeys) . PHP_EOL;
  exit(0);

} catch (Throwable $e) {
  write_err('ERROR: ' . $e->getMessage());
  exit(1);
}
