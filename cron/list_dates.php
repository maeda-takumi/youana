<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Tokyo');

$baseDir = dirname(__DIR__);
$cfgPath = $baseDir . '/config/sheets.json';
$keyPath = $baseDir . '/oauth/service_account.json';

if (php_sapi_name() !== 'cli') {
  header('Content-Type: text/plain; charset=UTF-8');
}

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

/** Service Account JWT Bearer */
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
    throw new RuntimeException("openssl_sign failed");
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

/** Sheets Values API */
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
 * "m月d日" 形式だけ抽出（例: 1月7日, 01月07日）
 * - 全角数字も許容（→半角化）
 * - 前後に余計な文字があるものは除外（必要なら緩められる）
 */
function extractMonthDay(?string $s): ?array {
  if ($s === null) return null;
  $s = trim($s);
  if ($s === '') return null;

  // 全角→半角
  $s = mb_convert_kana($s, 'n', 'UTF-8');

  if (!preg_match('/^(\d{1,2})\s*月\s*(\d{1,2})\s*日$/u', $s, $m)) return null;

  $mo = (int)$m[1];
  $d  = (int)$m[2];

  // 年は仮で2000年として妥当性チェック
  if (!checkdate($mo, $d, 2000)) return null;

  // 表示統一（m月d日）
  return [$mo, $d, $mo . '月' . $d . '日'];
}

function listMonthDayInSheet(string $accessToken, string $spreadsheetId, string $sheetName): array {
  $range = $sheetName . '!A:ZZ';
  $data = sheetsValuesGet($accessToken, $spreadsheetId, $range);
  $rows = $data['values'] ?? [];

  $uniq = []; // key = "m-d" , val = "m月d日"

  foreach ($rows as $row) {
    if (!is_array($row)) continue;
    foreach ($row as $cell) {
      if (!is_string($cell)) continue;

      $hit = extractMonthDay($cell);
      if ($hit) {
        [$m, $d, $label] = $hit;
        $uniq[sprintf('%02d-%02d', $m, $d)] = $label;
      }
    }
  }

  // 月→日でソート
  ksort($uniq);
  return array_values($uniq);
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

  foreach ($sheetNames as $sheetName) {
    $dates = listMonthDayInSheet($token, $spreadsheetId, $sheetName);

    echo '[' . $sheetName . ']' . PHP_EOL;
    if (count($dates) === 0) {
      echo '・(m月d日形式のセルなし)' . PHP_EOL . PHP_EOL;
      continue;
    }
    foreach ($dates as $d) {
      echo '・' . $d . PHP_EOL;
    }
    echo PHP_EOL;
  }

  exit(0);

} catch (Throwable $e) {
  write_err('ERROR: ' . $e->getMessage());
  exit(1);
}
