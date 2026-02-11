<?php
declare(strict_types=1);

/**
 * Tiny Notes Wall (password-only)
 * - No usernames
 * - Notes stored in ./data/notes.json
 * - Search + delete (optional)
 */

session_start();

$APP_TITLE = 'Tiny Notes';
$DATA_DIR  = __DIR__ . '/data';
$DATA_FILE = $DATA_DIR . '/notes.json';

// Set this in Coolify env: APP_PASSWORD=somepassword
$APP_PASSWORD = getenv('APP_PASSWORD') ?: '';

if ($APP_PASSWORD === '') {
  http_response_code(500);
  echo "APP_PASSWORD is not set.";
  exit;
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function ensure_data_file(string $dir, string $file): void {
  if (!is_dir($dir)) { mkdir($dir, 0775, true); }
  if (!file_exists($file)) {
    file_put_contents($file, json_encode(["notes" => []], JSON_PRETTY_PRINT));
  }
}
ensure_data_file($DATA_DIR, $DATA_FILE);

function load_notes(string $file): array {
  $raw = file_get_contents($file);
  $data = json_decode($raw ?: '', true);
  if (!is_array($data) || !isset($data['notes']) || !is_array($data['notes'])) {
    return ["notes" => []];
  }
  return $data;
}

function save_notes(string $file, array $data): void {
  $json = json_encode($data, JSON_PRETTY_PRINT);
  if ($json === false) { throw new RuntimeException("Failed to encode JSON."); }

  $fp = fopen($file, 'c+');
  if (!$fp) { throw new RuntimeException("Failed to open data file."); }
  if (!flock($fp, LOCK_EX)) { fclose($fp); throw new RuntimeException("Failed to lock data file."); }

  ftruncate($fp, 0);
  rewind($fp);
  fwrite($fp, $json);
  fflush($fp);
  flock($fp, LOCK_UN);
  fclose($fp);
}

function is_authed(): bool {
  return isset($_SESSION['authed']) && $_SESSION['authed'] === true;
}

function require_post(): void {
  if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
  }
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action === 'logout') {
  $_SESSION = [];
  session_destroy();
  header('Location: /');
  exit;
}

if ($action === 'login') {
  require_post();
  $pw = (string)($_POST['password'] ?? '');
  if (hash_equals($GLOBALS['APP_PASSWORD'], $pw)) {
    $_SESSION['authed'] = true;
    header('Location: /');
    exit;
  }
  $_SESSION['authed'] = false;
  $login_error = "Wrong password.";
}

if (!is_authed()) {
  $login_error = $login_error ?? null;
  ?>
  <!doctype html>
  <html>
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= h($APP_TITLE) ?></title>
    <style>
      body{font-family:system-ui,Arial,sans-serif;max-width:780px;margin:40px auto;padding:0 16px;}
      .card{border:1px solid #ddd;border-radius:12px;padding:18px;}
      input{width:100%;padding:12px;border:1px solid #ccc;border-radius:10px;font-size:16px;}
      button{padding:10px 14px;border:1px solid #222;border-radius:10px;background:#222;color:#fff;font-size:15px;cursor:pointer;}
      .err{color:#b00020;margin:10px 0 0 0;}
      .hint{color:#666;margin-top:10px;}
    </style>
  </head>
  <body>
    <h1><?= h($APP_TITLE) ?></h1>
    <div class="card">
      <form method="post">
        <input type="hidden" name="action" value="login">
        <label for="pw">Password</label>
        <input id="pw" name="password" type="password" autocomplete="current-password" autofocus>
        <div style="margin-top:12px;">
          <button type="submit">Enter</button>
        </div>
        <?php if ($login_error): ?>
          <div class="err"><?= h($login_error) ?></div>
        <?php endif; ?>
        <div class="hint">No username. One shared password.</div>
      </form>
    </div>
  </body>
  </html>
  <?php
  exit;
}

// Authed area
$data = load_notes($DATA_FILE);

if ($action === 'add') {
  require_post();
  $text = trim((string)($_POST['text'] ?? ''));
  if ($text !== '') {
    $note = [
      "id" => bin2hex(random_bytes(8)),
      "ts" => time(),
      "text" => $text
    ];
    array_unshift($data['notes'], $note);
    save_notes($DATA_FILE, $data);
  }
  header('Location: /');
  exit;
}

if ($action === 'delete') {
  require_post();
  $id = (string)($_POST['id'] ?? '');
  if ($id !== '') {
    $data['notes'] = array_values(array_filter($data['notes'], fn($n) => ($n['id'] ?? '') !== $id));
    save_notes($DATA_FILE, $data);
  }
  header('Location: /');
  exit;
}

$q = trim((string)($_GET['q'] ?? ''));
$notes = $data['notes'];

if ($q !== '') {
  $qq = mb_strtolower($q);
  $notes = array_values(array_filter($notes, function ($n) use ($qq) {
    $t = mb_strtolower((string)($n['text'] ?? ''));
    return str_contains($t, $qq);
  }));
}

function fmt_ts(int $ts): string {
  return date('Y-m-d H:i', $ts);
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= h($APP_TITLE) ?></title>
  <style>
    body{font-family:system-ui,Arial,sans-serif;max-width:980px;margin:30px auto;padding:0 16px;}
    header{display:flex;gap:12px;align-items:center;justify-content:space-between;flex-wrap:wrap;}
    .row{display:flex;gap:10px;flex-wrap:wrap;}
    textarea{width:100%;min-height:88px;padding:12px;border:1px solid #ccc;border-radius:12px;font-size:15px;}
    input[type="text"]{padding:10px;border:1px solid #ccc;border-radius:10px;font-size:14px;}
    button{padding:10px 14px;border:1px solid #222;border-radius:10px;background:#222;color:#fff;font-size:14px;cursor:pointer;}
    .ghost{background:#fff;color:#222;}
    .note{border:1px solid #e4e4e4;border-radius:14px;padding:14px;margin-top:12px;}
    .meta{color:#666;font-size:12px;display:flex;justify-content:space-between;gap:10px;align-items:center;flex-wrap:wrap;}
    .text{white-space:pre-wrap;margin-top:8px;font-size:15px;line-height:1.35;}
    form{margin:0;}
    .smallbtn{padding:6px 10px;font-size:12px;border-radius:10px;}
  </style>
</head>
<body>
  <header>
    <div>
      <h1 style="margin:0;"><?= h($APP_TITLE) ?></h1>
      <div style="color:#666;margin-top:4px;">Quick notes for sales, repairs, reminders.</div>
    </div>
    <div class="row">
      <form method="get" class="row">
        <input type="text" name="q" value="<?= h($q) ?>" placeholder="Search notes">
        <button class="ghost" type="submit">Search</button>
      </form>
      <form method="post">
        <input type="hidden" name="action" value="logout">
        <button class="ghost" type="submit">Log out</button>
      </form>
    </div>
  </header>

  <div style="margin-top:16px;">
    <form method="post">
      <input type="hidden" name="action" value="add">
      <textarea name="text" placeholder="Type a note. Example: #pricecheck Lowe’s 40-gal heater $498 as of 2/11"></textarea>
      <div style="margin-top:10px;">
        <button type="submit">Add note</button>
      </div>
    </form>
  </div>

  <div style="margin-top:18px;">
    <?php if (count($notes) === 0): ?>
      <div style="color:#666;">No notes found.</div>
    <?php endif; ?>

    <?php foreach ($notes as $n): ?>
      <div class="note">
        <div class="meta">
          <div><?= h(fmt_ts((int)$n['ts'])) ?></div>
          <form method="post" onsubmit="return confirm('Delete this note?');">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= h((string)$n['id']) ?>">
            <button class="ghost smallbtn" type="submit">Delete</button>
          </form>
        </div>
        <div class="text"><?= h((string)$n['text']) ?></div>
      </div>
    <?php endforeach; ?>
  </div>
</body>
</html>
