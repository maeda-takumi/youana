<?php
declare(strict_types=1);

/**
 * export_date_blocks.php
 * - config/sheets.json に登録された複数シートを対象
 * - シート全体から「m月d日」形式のセルを探す
 * - 見つけた日付セルの“下23セル(20セル + 3行スキップ + 3セル)”を取得
 * - 23セルを指定の項目名で連想配列化して JSON 保存
 *
 * 依存: vendor不要（Service Account JSON + JWT + curl）
 */

date_default_timezone_set('Asia/Tokyo');

$baseDir   = dirname(__DIR__);
$cfgPath   = $baseDir . '/config/sheets.json';
$keyPath   = $baseDir . '/oauth/service_account.json';

// 保存先（必要に応じて変更OK）
$outDir    = $baseDir . '/data';
$outPath   = $outDir . '/date_blocks.json';

if (php_sapi_name() !== 'cli') {
  header('Content-Type: text/plain; charset=UTF-8');
}

/** 取得セルの項目名（順番固定） */
const METRIC_KEYS = [
  '動画タイトル',
  'ビデオID',
  '総動画時間',
  '24時間平均視聴時間',
  '48時間平均視聴時間',
  '24時間総再生時間',
  '48時間総再生時間',
  '24時間のインプレッション数',
  '48時間のインプレッション数',
  '24時間のクリック率',
  '48時間のクリック率',
  '24時間の再生回数',
  '48時間の再生回数',
  '24時間の視聴維持率',
  '48時間の視聴維持率',
  '48時間の動画内チャンネル登録者数',
  'インプ伸び率',
  'CTR伸び率',
  '視聴回数伸び率',
  '維持率伸び率',
  '編集担当',
  '今回の改善箇所',
  '改善の成否/次回の改善',
];

function loadJson(string $path): array {
  if (!file_exists($path)) throw new RuntimeException("JSON not found: {$path}");
  $raw = file_get_contents($path);
  if ($raw === false) throw new RuntimeException("Failed to read: {$path}");
  $data = json_decode($raw, true);
  if (!is_array($data)) throw new RuntimeException("Invalid JSON: {$path}");
  return $data;
}

function base64url_encode(string $bin): string {
  return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function write_err(string $msg): void {
  if (defined('STDERR')) fwrite(STDERR, $msg . PHP_EOL);
  else { error_log($msg); echo $msg . PHP_EOL; }
}

/**
 * Service Account JWT Bearer で access_token を取得
 */
function getAccessTokenFromServiceAccount(string $serviceAccountJsonPath, string $scope): string {
  $sa = loadJson($serviceAccountJsonPath);

  $clientEmail = (string)($sa['client_email'] ?? '');
  $privateKey  = (string)($sa['private_key'] ?? '');
  $tokenUri    = (string)($sa['token_uri'] ?? 'https://oauth2.googleapis.com/token');

  if ($clientEmail === '' || $privateKey === '') {
    throw new RuntimeException("service_account.json missing client_email/private_key");
  }

  $now = time();
  $header = ['alg' => 'RS256', 'typ' => 'JWT'];
  $claims = [
    'iss'   => $clientEmail,
    'scope' => $scope,
    'aud'   => $tokenUri,
    'iat'   => $now,
    'exp'   => $now + 3600,
  ];

  $jwtHeader = base64url_encode(json_encode($header, JSON_UNESCAPED_SLASHES));
  $jwtClaims = base64url_encode(json_encode($claims, JSON_UNESCAPED_SLASHES));
  $signingInput = $jwtHeader . '.' . $jwtClaims;

  $signature = '';
  if (!openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
    throw new RuntimeException("openssl_sign failed (check openssl enabled)");
  }

  $jwt = $signingInput . '.' . base64url_encode($signature);

  $postFields = http_build_query([
    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
    'assertion'  => $jwt,
  ]);

  $ch = curl_init($tokenUri);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $postFields,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    CURLOPT_TIMEOUT        => 30,
  ]);

  $resp = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err  = curl_error($ch);
  curl_close($ch);

  if ($resp === false) throw new RuntimeException("curl token error: {$err}");
  $data = json_decode($resp, true);

  if ($code !== 200 || !is_array($data) || empty($data['access_token'])) {
    throw new RuntimeException("token request failed (HTTP {$code}): " . $resp);
  }

  return (string)$data['access_token'];
}

/**
 * Sheets Values API
 */
function sheetsValuesGet(string $accessToken, string $spreadsheetId, string $rangeA1): array {
  $url = 'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode($spreadsheetId)
       . '/values/' . rawurlencode($rangeA1)
       . '?majorDimension=ROWS&valueRenderOption=FORMATTED_VALUE&dateTimeRenderOption=FORMATTED_STRING';

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken],
    CURLOPT_TIMEOUT        => 120,
  ]);

  $resp = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err  = curl_error($ch);
  curl_close($ch);

  if ($resp === false) throw new RuntimeException("curl sheets error: {$err}");
  $data = json_decode($resp, true);

  if ($code !== 200 || !is_array($data)) {
    throw new RuntimeException("Sheets API failed (HTTP {$code}): " . $resp);
  }
  return $data;
}

/**
 * "m月d日" を抽出（セル内に含まれていればOK）
 * 例: "1月7日", "1月7日(火)" などでも拾う
 */
function extractMonthDayFromText(string $s): ?array {
  $s = trim($s);
  if ($s === '') return null;

  // 全角数字→半角数字
  $s = mb_convert_kana($s, 'n', 'UTF-8');

  if (!preg_match('/(\d{1,2})\s*月\s*(\d{1,2})\s*日/u', $s, $m)) return null;

  $mo = (int)$m[1];
  $d  = (int)$m[2];

  // 年は仮で2000年として妥当性チェック
  if (!checkdate($mo, $d, 2000)) return null;

  return [$mo, $d, $mo . '月' . $d . '日'];
}

/**
 * 0-based col index -> A1列文字
 */
function colToA1(int $col0): string {
  $col = $col0 + 1; // 1-based
  $s = '';
  while ($col > 0) {
    $mod = ($col - 1) % 26;
    $s = chr(65 + $mod) . $s;
    $col = intdiv($col - 1, 26);
  }
  return $s;
}

/**
 * シート全体から「m月d日セル」を見つけ、下23セルを項目付きで返す
 */
function buildDateBlocksForSheet(string $accessToken, string $spreadsheetId, string $sheetName): array {
  $range = $sheetName . '!A:ZZ';
  $data  = sheetsValuesGet($accessToken, $spreadsheetId, $range);
  $rows  = $data['values'] ?? [];

  // まず日付セルの位置を集める
  $found = [];
  foreach ($rows as $rIdx => $row) {
    if (!is_array($row)) continue;
    foreach ($row as $cIdx => $cell) {
      if (!is_string($cell)) continue;

      $hit = extractMonthDayFromText($cell);
      if (!$hit) continue;

      [$m, $d, $label] = $hit;

      $found[] = [
        'date'  => $label,
        'month' => $m,
        'day'   => $d,
        'row0'  => $rIdx,
        'col0'  => $cIdx,
        'a1'    => colToA1($cIdx) . ($rIdx + 1),
      ];
    }
  }

  // 下23セル（20セル + 3行スキップ + 3セル）を取得し、項目名付きに変換
  $blocks = [];
  foreach ($found as $f) {
    // 取得対象の相対行オフセット:
    // 1..20, 24..26（21..23はスキップ）
    $offsets = array_merge(range(1, 20), range(24, 26));

    // 下23セル raw
    $rawVals = [];
    foreach ($offsets as $offset) {
      $r = $f['row0'] + $offset;
      $c = $f['col0'];

      $v = null;
      // values は行末の空セルは要素自体が無いので array_key_exists で確認
      if (isset($rows[$r]) && is_array($rows[$r]) && array_key_exists($c, $rows[$r])) {
        $cell = $rows[$r][$c];
        if (is_string($cell)) {
          $cell = trim($cell);
          $v = ($cell === '') ? null : $cell;
        } else {
          $v = $cell; // 数値など
        }
      }
      $rawVals[] = $v;
    }

    // 項目名付きにマッピング
    $metrics = [];
    foreach (METRIC_KEYS as $i => $key) {
      $metrics[$key] = $rawVals[$i] ?? null;
    }

    $blocks[] = [
      'date'    => $f['date'],        // "2月10日"
      'a1'      => $f['a1'],          // "F3"
      'row'     => $f['row0'] + 1,    // 1-based
      'col'     => $f['col0'] + 1,    // 1-based
      'metrics' => $metrics,
    ];
  }

  // 安定ソート（month/day → a1）
  usort($blocks, function(array $a, array $b): int {
    // "2月10日" → 2,10
    if (preg_match('/(\d{1,2})月(\d{1,2})日/u', $a['date'], $am) &&
        preg_match('/(\d{1,2})月(\d{1,2})日/u', $b['date'], $bm)) {
      $aM = (int)$am[1]; $aD = (int)$am[2];
      $bM = (int)$bm[1]; $bD = (int)$bm[2];
      $cmp = ($aM <=> $bM) ?: ($aD <=> $bD);
      if ($cmp !== 0) return $cmp;
    }
    return strcmp((string)$a['a1'], (string)$b['a1']);
  });

  return $blocks;
}

/**
 * JSON保存（ディレクトリ作成、LOCK_EX）
 */
function saveJson(string $path, array $data): void {
  $dir = dirname($path);
  if (!is_dir($dir)) {
    if (!mkdir($dir, 0775, true)) {
      throw new RuntimeException("Failed to create dir: {$dir}");
    }
  }
  $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
  if ($json === false) throw new RuntimeException("Failed to encode JSON.");
  if (file_put_contents($path, $json . PHP_EOL, LOCK_EX) === false) {
    throw new RuntimeException("Failed to write JSON: {$path}");
  }
}

// ======================
// 実行
// ======================
try {
  $cfg = loadJson($cfgPath);
  $spreadsheetId = (string)($cfg['spreadsheet_id'] ?? '');
  $sheetNames = $cfg['sheets'] ?? [];

  if ($spreadsheetId === '') throw new RuntimeException("spreadsheet_id is empty in config/sheets.json");
  if (!is_array($sheetNames) || count($sheetNames) === 0) throw new RuntimeException("sheets is empty in config/sheets.json");

  $sheetNames = array_values(array_filter($sheetNames, fn($v) => is_string($v) && trim($v) !== ''));

  $token = getAccessTokenFromServiceAccount($keyPath, 'https://www.googleapis.com/auth/spreadsheets.readonly');

  $result = [
    'updated_at'     => date(DATE_ATOM),
    'spreadsheet_id' => $spreadsheetId,
    'output_file'    => str_replace($baseDir . '/', '', $outPath),
    'sheets'         => [],
  ];

  foreach ($sheetNames as $sheetName) {
    $blocks = buildDateBlocksForSheet($token, $spreadsheetId, $sheetName);
    $result['sheets'][$sheetName] = $blocks;

    // 画面にも軽く出す（Cronログ用）
    echo '[' . $sheetName . ']' . PHP_EOL;
    echo '・blocks: ' . count($blocks) . PHP_EOL . PHP_EOL;
  }

  saveJson($outPath, $result);

  echo 'Saved: ' . $outPath . PHP_EOL;
  exit(0);

} catch (Throwable $e) {
  write_err('ERROR: ' . $e->getMessage());
  exit(1);
}
