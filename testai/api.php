<?php
declare(strict_types=1);

$ctx = require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/gemini.php';
require_once __DIR__ . '/html_sanitize.php';

$db = $ctx['db'];
$tRaw = $ctx['tRaw'];
$tDaily = $ctx['tDaily'];
$tSettings = $ctx['tSettings'] ?? null;
$tgToken = (string)($ctx['tgToken'] ?? '');
$geminiKey = (string)$ctx['geminiKey'];
$geminiModel = (string)$ctx['geminiModel'];
$adminKey = (string)$ctx['adminKey'];
$allowed = $ctx['allowedChatIds'] ?? null;

require_once __DIR__ . '/tg.php';

header('Content-Type: application/json; charset=utf-8');

$ajax = trim((string)($_GET['ajax'] ?? ''));
$date = trim((string)($_GET['date'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');

$cacheDir = __DIR__ . '/cache';
if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
$cacheFile = $cacheDir . '/announce_' . $date . '.html';

$ok = fn(array $extra = []) => print json_encode(array_merge(['ok' => true], $extra), JSON_UNESCAPED_UNICODE);
$bad = fn(string $msg, array $extra = []) => print json_encode(array_merge(['ok' => false, 'error' => $msg], $extra), JSON_UNESCAPED_UNICODE);

if ($ajax === 'get') {
  $html = '';
  if (is_file($cacheFile)) {
    $html = (string)file_get_contents($cacheFile);
  }
  $ok(['date' => $date, 'html' => $html]);
  exit;
}

if ($ajax === 'health') {
  $dbOk = false;
  $rawTotal = 0;
  $rawLast = '';
  $dailyTotal = 0;
  try {
    $row = $db->query("SELECT 1 AS ok")->fetch();
    $dbOk = is_array($row) && (int)($row['ok'] ?? 0) === 1;
  } catch (\Throwable $e) {}
  try {
    $row = $db->query("SELECT COUNT(*) AS c, MAX(received_at) AS m FROM {$tRaw}")->fetch();
    if (is_array($row)) {
      $rawTotal = (int)($row['c'] ?? 0);
      $rawLast = (string)($row['m'] ?? '');
    }
  } catch (\Throwable $e) {}
  try {
    $row = $db->query("SELECT COUNT(*) AS c FROM {$tDaily}")->fetch();
    if (is_array($row)) $dailyTotal = (int)($row['c'] ?? 0);
  } catch (\Throwable $e) {}

  $hook = null;
  if ($tgToken !== '') {
    $info = testai_tg_post_json($tgToken, 'getWebhookInfo', []);
    if (is_array($info) && isset($info['result']) && is_array($info['result'])) {
      $r = $info['result'];
      $hook = [
        'url' => (string)($r['url'] ?? ''),
        'pending_update_count' => (int)($r['pending_update_count'] ?? 0),
        'last_error_date' => (int)($r['last_error_date'] ?? 0),
        'last_error_message' => (string)($r['last_error_message'] ?? ''),
      ];
    }
  }

  $ok([
    'db_ok' => $dbOk,
    'has_ai_tg_bot' => $tgToken !== '',
    'has_gemini_key' => $geminiKey !== '',
    'gemini_model' => $geminiModel,
    'allowed_chats_configured' => is_array($allowed),
    'allowed_chats_count' => is_array($allowed) ? count($allowed) : 0,
    'raw_total' => $rawTotal,
    'raw_last_received_at' => $rawLast,
    'daily_total' => $dailyTotal,
    'webhook' => $hook,
  ]);
  exit;
}

if ($ajax === 'get_prompt') {
  $v = '';
  $updatedAt = '';
  if (is_string($tSettings) && $tSettings !== '') {
    try {
      $row = $db->query("SELECT v, updated_at FROM {$tSettings} WHERE k = ? LIMIT 1", ['bot_prompt'])->fetch();
      if (is_array($row)) {
        $v = (string)($row['v'] ?? '');
        $updatedAt = (string)($row['updated_at'] ?? '');
      }
    } catch (\Throwable $e) {}
  }
  $ok(['prompt' => $v, 'updated_at' => $updatedAt]);
  exit;
}

if ($ajax === 'set_prompt') {
  if ($adminKey !== '' && (string)($_GET['key'] ?? '') !== $adminKey) {
    $bad('forbidden');
    exit;
  }
  $prompt = (string)($_POST['prompt'] ?? '');
  $prompt = trim($prompt);
  if (mb_strlen($prompt) > 20000) $prompt = mb_substr($prompt, 0, 20000);
  if (!is_string($tSettings) || $tSettings === '') {
    $bad('settings_table_missing');
    exit;
  }
  try {
    $db->query(
      "INSERT INTO {$tSettings} (k, v, updated_at)
       VALUES (?, ?, ?)
       ON DUPLICATE KEY UPDATE v = VALUES(v), updated_at = VALUES(updated_at)",
      ['bot_prompt', $prompt, date('Y-m-d H:i:s')]
    );
  } catch (\Throwable $e) {}
  $ok(['saved' => true]);
  exit;
}

if ($ajax === 'stats') {
  $from = $date . ' 00:00:00';
  $to = $date . ' 23:59:59';
  $cnt = 0;
  $withMedia = 0;
  $withMediaText = 0;
  try {
    $row = $db->query(
      "SELECT
         COUNT(*) AS cnt,
         SUM(CASE WHEN media_type IS NOT NULL AND media_type <> '' THEN 1 ELSE 0 END) AS with_media,
         SUM(CASE WHEN media_text IS NOT NULL AND media_text <> '' THEN 1 ELSE 0 END) AS with_media_text
       FROM {$tRaw}
       WHERE received_at BETWEEN ? AND ?",
      [$from, $to]
    )->fetch();
    if (is_array($row)) {
      $cnt = (int)($row['cnt'] ?? 0);
      $withMedia = (int)($row['with_media'] ?? 0);
      $withMediaText = (int)($row['with_media_text'] ?? 0);
    }
  } catch (\Throwable $e) {}
  $ok(['date' => $date, 'count' => $cnt, 'with_media' => $withMedia, 'with_media_text' => $withMediaText]);
  exit;
}

if ($ajax === 'summary') {
  $summary = '';
  $eventsJson = '[]';
  $exists = false;
  try {
    $row = $db->query(
      "SELECT summary_text, events_json, created_at
       FROM {$tDaily}
       WHERE day = ?
       LIMIT 1",
      [$date]
    )->fetch();
    if (is_array($row)) {
      $exists = true;
      $summary = (string)($row['summary_text'] ?? '');
      $eventsJson = (string)($row['events_json'] ?? '[]');
    }
  } catch (\Throwable $e) {}
  $ok(['date' => $date, 'exists' => $exists, 'summary_text' => $summary, 'events_json' => $eventsJson]);
  exit;
}

if ($ajax === 'last') {
  if ($adminKey !== '' && (string)($_GET['key'] ?? '') !== $adminKey) {
    $bad('forbidden');
    exit;
  }
  $limit = (int)($_GET['limit'] ?? 20);
  $limit = max(1, min(50, $limit));
  $from = $date . ' 00:00:00';
  $to = $date . ' 23:59:59';
  $items = [];
  try {
    $rows = $db->query(
      "SELECT tg_chat_id, tg_message_id, tg_chat_title, tg_username, tg_name, received_at, text, media_type, media_text
       FROM {$tRaw}
       WHERE received_at BETWEEN ? AND ?
       ORDER BY received_at DESC
       LIMIT {$limit}",
      [$from, $to]
    )->fetchAll();
    foreach (is_array($rows) ? $rows : [] as $r) {
      if (!is_array($r)) continue;
      $txt = trim((string)($r['text'] ?? ''));
      if (mb_strlen($txt) > 300) $txt = mb_substr($txt, 0, 300) . '…';
      $mt = trim((string)($r['media_text'] ?? ''));
      if (mb_strlen($mt) > 300) $mt = mb_substr($mt, 0, 300) . '…';
      $items[] = [
        'tg_chat_id' => (string)($r['tg_chat_id'] ?? ''),
        'tg_message_id' => (string)($r['tg_message_id'] ?? ''),
        'received_at' => (string)($r['received_at'] ?? ''),
        'chat_title' => (string)($r['tg_chat_title'] ?? ''),
        'from' => (string)($r['tg_username'] ?? $r['tg_name'] ?? ''),
        'media_type' => (string)($r['media_type'] ?? ''),
        'text' => $txt,
        'media_text' => $mt,
      ];
    }
  } catch (\Throwable $e) {}
  $ok(['date' => $date, 'items' => $items]);
  exit;
}

if ($ajax !== 'generate') {
  $bad('bad_request');
  exit;
}

if ($adminKey !== '' && (string)($_GET['key'] ?? '') !== $adminKey) {
  $bad('forbidden');
  exit;
}

if ($geminiKey === '') {
  $bad('missing_gemini_key');
  exit;
}

$since = date('Y-m-d', strtotime('-90 day')) . ' 00:00:00';
$events = [];
try {
  $rows = $db->query(
    "SELECT day, events_json
     FROM {$tDaily}
     WHERE created_at >= ?
     ORDER BY day ASC",
    [$since]
  )->fetchAll();
  foreach (is_array($rows) ? $rows : [] as $r) {
    if (!is_array($r)) continue;
    $ej = json_decode((string)($r['events_json'] ?? '[]'), true);
    if (is_array($ej)) {
      foreach ($ej as $ev) {
        if (!is_array($ev)) continue;
        if (isset($ev['announce_date']) && (string)$ev['announce_date'] === $date) $events[] = $ev;
      }
    }
  }
} catch (\Throwable $e) {}

$today = date('Y-m-d');
$todayMessages = [];
if ($date === $today) {
  try {
    $from = $today . ' 00:00:00';
    $to = $today . ' 23:59:59';
    $rows = $db->query(
      "SELECT tg_chat_id, tg_message_id, tg_chat_title, tg_username, tg_name, received_at, text, media_text
       FROM {$tRaw}
       WHERE received_at BETWEEN ? AND ?
       ORDER BY received_at ASC",
      [$from, $to]
    )->fetchAll();
    foreach (is_array($rows) ? $rows : [] as $r) {
      if (!is_array($r)) continue;
      $txt = trim((string)($r['text'] ?? ''));
      $m = trim((string)($r['media_text'] ?? ''));
      $combined = trim($txt . "\n" . ($m !== '' ? ('[media]\n' . $m) : ''));
      if ($combined === '') continue;
      $todayMessages[] = [
        'tg_chat_id' => (string)($r['tg_chat_id'] ?? ''),
        'tg_message_id' => (string)($r['tg_message_id'] ?? ''),
        'received_at' => (string)($r['received_at'] ?? ''),
        'chat_title' => (string)($r['tg_chat_title'] ?? ''),
        'from' => (string)($r['tg_username'] ?? $r['tg_name'] ?? ''),
        'text' => $combined,
      ];
    }
  } catch (\Throwable $e) {}
}

$system = 'Return HTML only. No markdown. No scripts. Use simple tags: div,p,br,strong,em,ul,li,h2,h3,a,span.';
$prompt = "Create a short HTML announcement for the restaurant for date {$date}. If no information is available, return HTML with a short message that there is no confirmed announcement yet.";
$payload = [
  'date' => $date,
  'events' => $events,
  'today_messages' => $todayMessages,
];

$resp = testai_gemini_generate(
  $geminiKey,
  $geminiModel,
  [['text' => $prompt], ['text' => json_encode($payload, JSON_UNESCAPED_UNICODE)]],
  ['system' => $system, 'temperature' => 0.3, 'maxOutputTokens' => 2200]
);
$html = testai_gemini_text($resp);
$html = testai_sanitize_html($html);

if ($html !== '') {
  @file_put_contents($cacheFile, $html, LOCK_EX);
}

$ok(['date' => $date, 'html' => $html]);
