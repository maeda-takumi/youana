<?php
declare(strict_types=1);

/**
 * manage_sheets.php
 * - config/sheets.json を編集するための簡易管理画面
 * - 追加/削除/並び替え/一覧
 * - PRG(POST→Redirect→GET)でも成功/失敗が分かるように flash を session で保持
 */

date_default_timezone_set('Asia/Tokyo');
session_start();

$baseDir   = dirname(__DIR__);
$jsonPath  = $baseDir . '/config/sheets.json';

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function loadConfig(string $path): array {
  if (!file_exists($path)) {
    return [
      'spreadsheet_id' => '',
      'sheets' => [],
      'updated_at' => date(DATE_ATOM),
    ];
  }
  $raw = file_get_contents($path);
  if ($raw === false) throw new RuntimeException("Failed to read: {$path}");
  $data = json_decode($raw, true);
  if (!is_array($data)) throw new RuntimeException("Invalid JSON: {$path}");

  $data['spreadsheet_id'] = (string)($data['spreadsheet_id'] ?? '');
  $data['sheets'] = array_values(array_filter($data['sheets'] ?? [], fn($v) => is_string($v) && trim($v) !== ''));
  $data['updated_at'] = (string)($data['updated_at'] ?? date(DATE_ATOM));

  return $data;
}

/**
 * 保存処理
 * - config ディレクトリが無ければ作成
 * - 書き込み失敗時に分かりやすいエラーを返す
 */
function saveConfig(string $path, array $data): void {
  $dir = dirname($path);

  if (!is_dir($dir)) {
    if (!mkdir($dir, 0775, true)) {
      throw new RuntimeException("Failed to create dir: {$dir}");
    }
  }

  if (!is_writable($dir)) {
    $perms = substr(sprintf('%o', fileperms($dir)), -4);
    throw new RuntimeException("Config dir is not writable: {$dir} (perms: {$perms})");
  }

  $data['updated_at'] = date(DATE_ATOM);
  $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
  if ($json === false) throw new RuntimeException("Failed to encode JSON.");

  $ok = @file_put_contents($path, $json . PHP_EOL, LOCK_EX);
  if ($ok === false) {
    $perms = substr(sprintf('%o', fileperms($dir)), -4);
    throw new RuntimeException("Failed to write: {$path} (dir perms: {$perms})");
  }
}

function normalizeSheetName(string $name): string {
  // 前後空白除去 + 連続空白を1つに
  $name = trim($name);
  $name = preg_replace('/\s+/u', ' ', $name) ?? $name;
  return $name;
}

$cfg = loadConfig($jsonPath);
$error = '';
$flash = '';

try {
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'set_spreadsheet_id') {
      $sid = trim((string)($_POST['spreadsheet_id'] ?? ''));
      $cfg['spreadsheet_id'] = $sid;
      saveConfig($jsonPath, $cfg);
      $flash = 'スプレッドシートIDを更新しました。';
    }

    if ($action === 'add') {
      $name = normalizeSheetName((string)($_POST['sheet_name'] ?? ''));
      if ($name === '') throw new RuntimeException('シート名が空です。');
      if (in_array($name, $cfg['sheets'], true)) throw new RuntimeException('同名のシートが既に登録されています。');

      $cfg['sheets'][] = $name;
      saveConfig($jsonPath, $cfg);
      $flash = "追加しました: {$name}";
    }

    if ($action === 'delete') {
      $idx = (int)($_POST['idx'] ?? -1);
      if (!isset($cfg['sheets'][$idx])) throw new RuntimeException('削除対象が見つかりません。');

      $removed = $cfg['sheets'][$idx];
      array_splice($cfg['sheets'], $idx, 1);
      saveConfig($jsonPath, $cfg);
      $flash = "削除しました: {$removed}";
    }

    if ($action === 'move') {
      $idx = (int)($_POST['idx'] ?? -1);
      $dir = (string)($_POST['dir'] ?? '');
      if (!isset($cfg['sheets'][$idx])) throw new RuntimeException('移動対象が見つかりません。');

      $newIdx = $idx + ($dir === 'up' ? -1 : 1);
      if ($newIdx < 0 || $newIdx >= count($cfg['sheets'])) {
        // 端なら何もしない
        $flash = '端なので移動できませんでした。';
      } else {
        $tmp = $cfg['sheets'][$idx];
        $cfg['sheets'][$idx] = $cfg['sheets'][$newIdx];
        $cfg['sheets'][$newIdx] = $tmp;
        saveConfig($jsonPath, $cfg);
        $flash = '並び替えしました。';
      }
    }

    // 成功メッセージをリダイレクト後に表示する（PRG対策）
    if ($flash !== '') {
      $_SESSION['flash'] = $flash;
    }

    // PRG（リロードで二重POST防止）
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
  }
} catch (Throwable $e) {
  // エラーもPRG後に見えるように保存
  $_SESSION['error'] = $e->getMessage();
  header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
  exit;
}

// GET表示時：フラッシュ表示（1回だけ）
if (!empty($_SESSION['flash'])) {
  $flash = (string)$_SESSION['flash'];
  unset($_SESSION['flash']);
}
if (!empty($_SESSION['error'])) {
  $error = (string)$_SESSION['error'];
  unset($_SESSION['error']);
}

// 最新状態を読み込み直し
$cfg = loadConfig($jsonPath);

?>
<!doctype html>
<html lang="ja">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="./assets/css/manage.css?v=<?= time() ?>">
  <title>シート管理</title>
</head>
<body>
<div class="wrap">

  <div class="card">
    <h1>監視対象シート管理</h1>
    <div class="muted">保存先: <?=h(str_replace($baseDir.'/', '', $jsonPath))?> / 更新: <?=h($cfg['updated_at'] ?? '')?></div>

    <?php if ($flash): ?>
      <div style="margin-top:10px; background:#dcfce7; color:#166534; padding:10px 12px; border-radius:10px;">
        <?= h($flash) ?>
      </div>
    <?php endif; ?>

    <?php if ($error): ?>
      <div class="err" style="margin-top:10px;"><?=h($error)?></div>
    <?php endif; ?>
  </div>

  <div class="card">
    <h1>スプレッドシートID</h1>
    <form method="post" class="row">
      <input type="hidden" name="action" value="set_spreadsheet_id">
      <input type="text" name="spreadsheet_id" value="<?=h((string)$cfg['spreadsheet_id'])?>" placeholder="例: 1HGz1Jq-...">
      <button class="btn" type="submit">保存</button>
    </form>
    <div class="muted" style="margin-top:8px;">
      ※監視スクリプトはここに設定されたIDを使用します
    </div>
  </div>

  <div class="card">
    <h1>シート追加</h1>
    <form method="post" class="row">
      <input type="hidden" name="action" value="add">
      <input type="text" name="sheet_name" placeholder="追加するシート名（例: 投資顧客管理）">
      <button class="btn" type="submit">追加</button>
    </form>
  </div>

  <div class="card">
    <h1>登録済みシート（<?=count($cfg['sheets'])?>）</h1>
    <table>
      <thead>
        <tr>
          <th style="width:60px;">#</th>
          <th>シート名</th>
          <th style="width:240px;">操作</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($cfg['sheets'] as $i => $name): ?>
        <tr>
          <td><span class="pill"><?=($i+1)?></span></td>
          <td><?=h($name)?></td>
          <td>
            <div class="actions">
              <form method="post">
                <input type="hidden" name="action" value="move">
                <input type="hidden" name="idx" value="<?= (int)$i ?>">
                <input type="hidden" name="dir" value="up">
                <button class="btn2" type="submit">↑</button>
              </form>
              <form method="post">
                <input type="hidden" name="action" value="move">
                <input type="hidden" name="idx" value="<?= (int)$i ?>">
                <input type="hidden" name="dir" value="down">
                <button class="btn2" type="submit">↓</button>
              </form>
              <form method="post" onsubmit="return confirm('削除しますか？');">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="idx" value="<?= (int)$i ?>">
                <button class="btnDel" type="submit">削除</button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <div class="muted" style="margin-top:10px;">
      並び順＝監視の処理順（Cron側で上から順に処理）
    </div>
  </div>

</div>
</body>
</html>
