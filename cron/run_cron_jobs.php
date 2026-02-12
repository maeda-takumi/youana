<?php
declare(strict_types=1);

/**
 * run_cron_jobs.php
 *
 * cron からこのファイルを呼ぶことで、指定したバッチを順番に実行します。
 *
 * 実行順:
 * 1. export_date_blocks.php
 * 2. check_missing_and_notify.php
 * 3. check_below_month_avg_and_notify.php
 * 4. check_below_avg_followup_missing_and_notify.php
 */

date_default_timezone_set('Asia/Tokyo');

if (php_sapi_name() !== 'cli') {
  http_response_code(403);
  header('Content-Type: text/plain; charset=UTF-8');
  echo "This script must be run from CLI." . PHP_EOL;
  exit(1);
}

$baseDir = __DIR__;
$phpBin = PHP_BINARY ?: 'php';

$jobs = [
  'export_date_blocks.php',
  'check_missing_and_notify.php',
  'check_below_month_avg_and_notify.php',
  'check_below_avg_followup_missing_and_notify.php',
];

$startedAt = date('Y-m-d H:i:s');
echo "[{$startedAt}] cron batch start" . PHP_EOL;

foreach ($jobs as $job) {
  $scriptPath = $baseDir . DIRECTORY_SEPARATOR . $job;

  if (!is_file($scriptPath)) {
    fwrite(STDERR, "[ERROR] Script not found: {$scriptPath}" . PHP_EOL);
    exit(1);
  }

  echo "[RUN] {$job}" . PHP_EOL;

  $cmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($scriptPath);

  $descriptorspec = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
  ];

  $process = proc_open($cmd, $descriptorspec, $pipes, $baseDir);
  if (!is_resource($process)) {
    fwrite(STDERR, "[ERROR] Failed to start: {$job}" . PHP_EOL);
    exit(1);
  }

  fclose($pipes[0]);
  $stdout = stream_get_contents($pipes[1]);
  fclose($pipes[1]);
  $stderr = stream_get_contents($pipes[2]);
  fclose($pipes[2]);

  $exitCode = proc_close($process);

  if ($stdout !== false && $stdout !== '') {
    echo $stdout;
    if (substr($stdout, -strlen(PHP_EOL)) !== PHP_EOL) {
      echo PHP_EOL;
    }
  }

  if ($stderr !== false && $stderr !== '') {
    fwrite(STDERR, $stderr);
    if (substr($stderr, -strlen(PHP_EOL)) !== PHP_EOL) {
      fwrite(STDERR, PHP_EOL);
    }
  }

  if ($exitCode !== 0) {
    fwrite(STDERR, "[ERROR] {$job} failed with exit code {$exitCode}" . PHP_EOL);
    exit($exitCode);
  }

  echo "[DONE] {$job}" . PHP_EOL;
}

$endedAt = date('Y-m-d H:i:s');
echo "[{$endedAt}] cron batch finished" . PHP_EOL;
exit(0);
