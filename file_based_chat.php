<?php
// Simple file-based chat (single PHP file)
// Requirements implemented:
// - File-based "DB" using a text file with chmod 0777
// - Login by nickname only (no password); session-based
// - New users see chat only from their join time
// - Auto-delete chat when the last user leaves
// - Users go inactive after 30 min; considered logged out and their join time is reset when they return
// - Online count + user list shown
// - Smiley conversion (e.g., :) :-) ;-) :D :( )
// - Message input + post + logout button
//
// NOTE: chmod 0777 is insecure in production; requested by user.
//
// www.perplex.click - admin@perplex.click

session_start();

// ---- CONFIG ----
$DATA_DIR = __DIR__ . '/chat_data';
$CHAT_FILE = $DATA_DIR . '/chat.log'; // Each line = JSON {ts, nick, text}
$USERS_FILE = $DATA_DIR . '/users.json'; // JSON array of users [{nick, session, joined_at, last_seen}]
$INACTIVITY_SECONDS = 30 * 60; // 30 minutes
$POLL_LIMIT = 2000; // Max lines to read for performance

// ---- INIT DATA DIR & FILES ----
if (!is_dir($DATA_DIR)) {
    @mkdir($DATA_DIR, 0777, true);
    @chmod($DATA_DIR, 0777);
}
if (!file_exists($CHAT_FILE)) {
    @touch($CHAT_FILE);
}
if (!file_exists($USERS_FILE)) {
    @file_put_contents($USERS_FILE, json_encode([]));
}
@chmod($CHAT_FILE, 0777);
@chmod($USERS_FILE, 0777);

// ---- HELPERS ----
function json_response($data, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($data);
    exit;
}

function load_users() {
    global $USERS_FILE;
    $fp = fopen($USERS_FILE, 'c+');
    if (!$fp) return [];
    flock($fp, LOCK_SH);
    $content = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    $arr = json_decode($content ?: '[]', true);
    return is_array($arr) ? $arr : [];
}

function save_users($users) {
    global $USERS_FILE;
    $fp = fopen($USERS_FILE, 'c+');
    if (!$fp) return false;
    flock($fp, LOCK_EX);
    ftruncate($fp, 0);
    fwrite($fp, json_encode(array_values($users)));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    @chmod($USERS_FILE, 0777);
    return true;
}

function prune_inactive(&$users) {
    global $INACTIVITY_SECONDS;
    $now = time();
    $changed = false;
    foreach ($users as $i => $u) {
        if (!isset($u['last_seen']) || ($now - (int)$u['last_seen']) > $INACTIVITY_SECONDS) {
            unset($users[$i]);
            $changed = true;
        }
    }
    if ($changed) $users = array_values($users);
    return $changed;
}

function get_online_users() {
    $users = load_users();
    $changed = prune_inactive($users);
    if ($changed) save_users($users);
    return $users;
}

function truncate_chat_if_empty() {
    global $CHAT_FILE;
    $users = get_online_users();
    if (count($users) === 0) {
        $fp = fopen($CHAT_FILE, 'c+');
        if ($fp) {
            flock($fp, LOCK_EX);
            ftruncate($fp, 0);
            fflush($fp);
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }
}

function smileys_to_emoji($text) {
    $map = [
        ':-)' => '😊', ':)' => '😊',
        ';-)' => '😉', ';)' => '😉',
        ':-D' => '😄', ':D' => '😄',
        ':-(' => '🙁', ':(' => '🙁',
        ':-P' => '😛', ':P' => '😛',
        ':-O' => '😮', ':O' => '😮',
        ':-|' => '😐', ':|' => '😐',
        ':-/' => '😕', ':/' => '😕',
    ];
    return str_replace(array_keys($map), array_values($map), $text);
}

function sanitize($str) {
    return trim(filter_var($str, FILTER_UNSAFE_RAW));
}

function append_chat($entry) {
    global $CHAT_FILE;
    $fp = fopen($CHAT_FILE, 'a');
    if (!$fp) return false;
    flock($fp, LOCK_EX);
    fwrite($fp, json_encode($entry) . "\n");
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    @chmod($CHAT_FILE, 0777);
    return true;
}

function read_chat_since($since_ts, $limitLines = 2000) {
    global $CHAT_FILE;
    $lines = [];
    if (!file_exists($CHAT_FILE)) return $lines;
    // Efficient tail-read
    $fp = fopen($CHAT_FILE, 'r');
    if (!$fp) return $lines;
    flock($fp, LOCK_SH);
    // Read last N lines roughly
    $buffer = '';
    $chunkSize = 4096;
    $pos = -1;
    $lineCount = 0;
    $fileSize = filesize($CHAT_FILE);
    if ($fileSize === 0) {
        flock($fp, LOCK_UN); fclose($fp); return [];
    }
    $pos = $fileSize;
    while ($pos > 0 && $lineCount < $limitLines) {
        $read = max($pos - $chunkSize, 0);
        $len = $pos - $read;
        fseek($fp, $read);
        $chunk = fread($fp, $len);
        $buffer = $chunk . $buffer;
        $pos = $read;
        $lineCount = substr_count($buffer, "\n");
    }
    $linesArr = explode("\n", $buffer);
    flock($fp, LOCK_UN);
    fclose($fp);

    $out = [];
    foreach ($linesArr as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $obj = json_decode($line, true);
        if (!$obj) continue;
        if ((int)$obj['ts'] >= (int)$since_ts) {
            $out[] = $obj;
        }
    }
    return $out;
}

function current_user() {
    return isset($_SESSION['nick']) ? [
        'nick' => $_SESSION['nick'],
        'joined_at' => $_SESSION['joined_at'] ?? null,
        'session' => session_id(),
    ] : null;
}

function require_login() {
    $u = current_user();
    if (!$u) json_response(['error' => 'not_logged_in'], 401);
    return $u;
}

// ---- API ----
$action = $_GET['action'] ?? $_POST['action'] ?? null;

if ($action === 'login') {
    $nick = sanitize($_POST['nick'] ?? '');
    if ($nick === '') json_response(['ok' => false, 'error' => 'Nickname erforderlich.'], 400);

    // Ensure unique nickname (case-insensitive)
    $users = load_users();
    prune_inactive($users);
    foreach ($users as $u) {
        if (mb_strtolower($u['nick']) === mb_strtolower($nick)) {
            json_response(['ok' => false, 'error' => 'Nickname bereits online.'], 409);
        }
    }
    $now = time();
    $_SESSION['nick'] = $nick;
    $_SESSION['joined_at'] = $now;
    $users[] = [
        'nick' => $nick,
        'session' => session_id(),
        'joined_at' => $now,
        'last_seen' => $now,
    ];
    save_users($users);
    append_chat(['ts' => $now, 'nick' => 'SYSTEM', 'text' => "$nick hat den Chat betreten."]);
    json_response(['ok' => true]);
}

if ($action === 'logout') {
    $u = current_user();
    if ($u) {
        $users = load_users();
        foreach ($users as $i => $uu) {
            if ($uu['session'] === $u['session']) {
                unset($users[$i]);
                break;
            }
        }
        save_users(array_values($users));
        append_chat(['ts' => time(), 'nick' => 'SYSTEM', 'text' => $u['nick'] . ' hat den Chat verlassen.']);
        // If last user left, truncate chat
        truncate_chat_if_empty();
        session_destroy();
    }
    json_response(['ok' => true]);
}

if ($action === 'post') {
    $u = require_login();
    $text = sanitize($_POST['text'] ?? '');
    if ($text === '') json_response(['ok' => false, 'error' => 'Leere Nachricht.'], 400);
    $text = mb_substr($text, 0, 1000);
    $text = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $text = smileys_to_emoji($text);
    $entry = [
        'ts' => time(),
        'nick' => $u['nick'],
        'text' => $text,
    ];
    append_chat($entry);
    // Touch user last_seen
    $users = load_users();
    foreach ($users as &$uu) {
        if ($uu['session'] === $u['session']) {
            $uu['last_seen'] = time();
            break;
        }
    }
    save_users($users);
    json_response(['ok' => true]);
}

if ($action === 'poll') {
    $u = require_login();
    // Heartbeat/update last_seen
    $users = load_users();
    $now = time();
    $changed = prune_inactive($users);
    $found = false;
    foreach ($users as &$uu) {
        if ($uu['session'] === $u['session']) {
            $uu['last_seen'] = $now;
            $found = true;
            break;
        }
    }
    if (!$found) {
        // Session lost due to inactivity -> force logout on client
        session_destroy();
        json_response(['error' => 'session_expired'], 401);
    }
    if ($changed || $found) save_users($users);

    $joined_at = $_SESSION['joined_at'] ?? $now;
    $messages = read_chat_since($joined_at, $POLL_LIMIT);
    $online = array_map(function($x){ return ['nick' => $x['nick']]; }, $users);
    json_response([
        'ok' => true,
        'messages' => $messages,
        'online_count' => count($online),
        'online_users' => $online,
    ]);
}

// ---- HTML UI (default) ----
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Dateibasierter Chat</title>
  <style>
    :root { --bg:#0f172a; --card:#111827; --fg:#e5e7eb; --muted:#9ca3af; --accent:#22c55e; }
    * { box-sizing: border-box; }
    body { margin: 0; font-family: system-ui, -apple-system, Segoe UI, Roboto, Ubuntu, Cantarell, Noto Sans, sans-serif; background: var(--bg); color: var(--fg); }
    .container { max-width: 980px; margin: 0 auto; padding: 24px; }
    .card { background: var(--card); border-radius: 16px; box-shadow: 0 10px 30px rgba(0,0,0,.25); padding: 16px; }
    .row { display: grid; grid-template-columns: 240px 1fr; gap: 16px; }
    .hidden { display: none; }
    .login { display: grid; gap: 12px; }
    input[type=text] { width: 100%; padding: 10px 12px; border-radius: 12px; border: 1px solid #374151; background: #0b1220; color: var(--fg); }
    button { padding: 10px 14px; border: 0; border-radius: 12px; background: var(--accent); color: #052e16; font-weight: 700; cursor: pointer; }
    button.secondary { background: #334155; color: #e5e7eb; }
    #chatbox { height: 420px; overflow-y: auto; border: 1px solid #1f2937; border-radius: 12px; padding: 12px; background: #0b1220; }
    .msg { margin-bottom: 10px; }
    .msg .meta { color: var(--muted); font-size: 12px; }
    .msg .nick { font-weight: 700; }
    .sidebar { border: 1px solid #1f2937; border-radius: 12px; padding: 12px; background: #0b1220; }
    .online { font-size: 12px; color: var(--muted); margin-bottom: 8px; }
    ul.users { list-style: none; padding: 0; margin: 0; display: grid; gap: 6px; }
    ul.users li { background: #0f172a; border: 1px solid #1f2937; border-radius: 10px; padding: 6px 8px; }
    .composer { display: grid; grid-template-columns: 1fr auto auto; gap: 8px; margin-top: 10px; }
  </style>
</head>
<body>
  <div class="container">
    <h1>Dateibasierter Chat</h1>
    <div id="login" class="card login">
      <label for="nick">Nickname</label>
      <input type="text" id="nick" maxlength="24" placeholder="Dein Nickname…" />
      <div>
        <button id="btnLogin">Einloggen</button>
      </div>
      <p class="hint">Kein Passwort nötig. Neue Nutzer sehen den Chatverlauf erst ab Eintritt.</p>
    </div>

    <div id="app" class="hidden">
      <div class="row">
        <aside class="sidebar">
          <div class="online"><span id="onlineCount">0</span> Nutzer online</div>
          <ul id="userList" class="users"></ul>
          <div style="margin-top:12px">
            <button id="btnLogout" class="secondary">Ausloggen</button>
          </div>
        </aside>
        <main class="card">
          <div id="chatbox"></div>
          <div class="composer">
            <input type="text" id="message" placeholder=":) schreibe eine Nachricht…" maxlength="1000" />
            <button id="btnSend">Senden</button>
            <button id="btnLogout2" class="secondary">Ausloggen</button>
          </div>
        </main>
      </div>
    </div>
  </div>

<script>
const $ = sel => document.querySelector(sel);
const loginView = $('#login');
const appView = $('#app');
const chatbox = $('#chatbox');
const userList = $('#userList');
const onlineCount = $('#onlineCount');

let pollTimer = null;

function showApp() {
  loginView.classList.add('hidden');
  appView.classList.remove('hidden');
  startPolling();
}

function showLogin() {
  stopPolling();
  loginView.classList.remove('hidden');
  appView.classList.add('hidden');
}

function api(action, data={}) {
  const form = new URLSearchParams({action, ...data});
  return fetch(window.location.pathname + '?action=' + action, {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: form
  }).then(async res => {
    const json = await res.json().catch(()=>({}));
    if (!res.ok) throw {status: res.status, ...json};
    return json;
  });
}

$('#btnLogin').addEventListener('click', async () => {
  const nick = $('#nick').value.trim();
  if (!nick) return alert('Bitte Nickname eingeben');
  try {
    await api('login', {nick});
    showApp();
  } catch (e) {
    alert(e.error || 'Login fehlgeschlagen');
  }
});

function renderMessages(msgs) {
  if (!Array.isArray(msgs)) return;
  const frag = document.createDocumentFragment();
  msgs.forEach(m => {
    const d = new Date(m.ts * 1000);
    const div = document.createElement('div');
    div.className = 'msg';
    div.innerHTML = `
      <div class="meta"><span class="time">${d.toLocaleTimeString()}</span> — <span class="nick">${m.nick}</span></div>
      <div class="text">${m.text}</div>
    `;
    frag.appendChild(div);
  });
  chatbox.appendChild(frag);
  chatbox.scrollTop = chatbox.scrollHeight;
}

function renderUsers(list) {
  userList.innerHTML = '';
  (list||[]).forEach(u => {
    const li = document.createElement('li');
    li.textContent = u.nick;
    userList.appendChild(li);
  });
}

async function poll() {
  try {
    const data = await api('poll');
    renderMessages(data.messages);
    renderUsers(data.online_users);
    onlineCount.textContent = data.online_count;
  } catch (e) {
    if (e.status === 401) {
      alert('Deine Sitzung ist abgelaufen oder du bist ausgeloggt.');
      showLogin();
    } else {
      console.warn('Poll-Fehler', e);
    }
  }
}

function startPolling() {
  if (pollTimer) return;
  poll();
  pollTimer = setInterval(poll, 3000);
}

function stopPolling() {
  if (pollTimer) clearInterval(pollTimer);
  pollTimer = null;
}

$('#btnSend').addEventListener('click', async () => {
  const txt = $('#message').value.trim();
  if (!txt) return;
  $('#message').value = '';
  try {
    await api('post', {text: txt});
    // next poll will render it
  } catch (e) {
    if (e.status === 401) {
      alert('Sitzung abgelaufen. Bitte erneut einloggen.');
      showLogin();
    } else {
      alert(e.error || 'Senden fehlgeschlagen');
    }
  }
});

function doLogout() {
  api('logout').catch(()=>{}).finally(() => {
    showLogin();
  });
}

$('#btnLogout').addEventListener('click', doLogout);
$('#btnLogout2').addEventListener('click', doLogout);
</script>
</body>
</html>
