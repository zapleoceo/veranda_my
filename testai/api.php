<?php
declare(strict_types=1);

$ctx = require __DIR__ . '/bootstrap.php';
$cfg = $ctx['cfg'];
$db = $ctx['db'];
$rawRepo = $ctx['rawRepo'];
$dailyRepo = $ctx['dailyRepo'];
$settingsRepo = $ctx['settingsRepo'];
$tg = $ctx['tg'];
$gemini = $ctx['gemini'];
$announcementSvc = $ctx['announcementSvc'];
$adminKey = (string)($cfg->adminKey ?? '');
$allowed = $cfg->allowedChatIds ?? null;
$envFile = (string)($ctx['envFile'] ?? '');
$envLoadedKeys = is_array($ctx['envLoadedKeys'] ?? null) ? array_keys($ctx['envLoadedKeys']) : [];

header('Content-Type: application/json; charset=utf-8');

$ajax = trim((string)($_GET['ajax'] ?? ''));
$date = trim((string)($_GET['date'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');

$ok = fn(array $extra = []) => print json_encode(array_merge(['ok' => true], $extra), JSON_UNESCAPED_UNICODE);
$bad = fn(string $msg, array $extra = []) => print json_encode(array_merge(['ok' => false, 'error' => $msg], $extra), JSON_UNESCAPED_UNICODE);

if ($ajax === 'get') {
  $html = $announcementSvc->getCached($date);
  $ok(['date' => $date, 'html' => $html]);
  exit;
}

if ($ajax === 'health') {
  $dbOk = false;
  try {
    $row = $db->query("SELECT 1 AS ok")->fetch();
    $dbOk = is_array($row) && (int)($row['ok'] ?? 0) === 1;
  } catch (\Throwable $e) {}
  $rawTotals = $rawRepo->getTotals();
  $dailyTotal = $dailyRepo->countAll();

  $hook = null;
  $info = $tg->getWebhookInfo();
  if (is_array($info) && isset($info['result']) && is_array($info['result'])) {
    $r = $info['result'];
    $hook = [
      'url' => (string)($r['url'] ?? ''),
      'pending_update_count' => (int)($r['pending_update_count'] ?? 0),
      'last_error_date' => (int)($r['last_error_date'] ?? 0),
      'last_error_message' => (string)($r['last_error_message'] ?? ''),
    ];
  }

  $ok([
    'db_ok' => $dbOk,
    'has_ai_tg_bot' => $tg->hasToken(),
    'gemini_can_call' => $gemini->canCall(),
    'gemini_model' => (string)$cfg->geminiModel,
    'gemini_proxy_base' => (string)$cfg->geminiProxyBase(),
    'env_file_exists' => $envFile !== '' ? file_exists($envFile) : false,
    'env_keys_loaded' => array_values(array_slice($envLoadedKeys, 0, 50)),
    'allowed_chats_configured' => is_array($allowed),
    'allowed_chats_count' => is_array($allowed) ? count($allowed) : 0,
    'raw_total' => (int)($rawTotals['raw_total'] ?? 0),
    'raw_last_received_at' => (string)($rawTotals['raw_last_received_at'] ?? ''),
    'daily_total' => $dailyTotal,
    'webhook' => $hook,
  ]);
  exit;
}

if ($ajax === 'gemini_test') {
  if ($adminKey !== '' && (string)($_GET['key'] ?? '') !== $adminKey) {
    $bad('forbidden');
    exit;
  }
  if (!$gemini->canCall()) {
    $bad('missing_gemini_key');
    exit;
  }
  $q = trim((string)($_GET['q'] ?? 'Say ok'));
  if ($q === '') $q = 'Say ok';
  if (mb_strlen($q) > 500) $q = mb_substr($q, 0, 500);
  $resp = $gemini->generate(
    (string)$cfg->geminiModel,
    [['text' => $q]],
    ['system' => 'Reply with plain text only.', 'temperature' => 0.0, 'maxOutputTokens' => 50]
  );
  $txt = $gemini->text($resp);
  $err = '';
  if (is_array($resp['error'] ?? null)) $err = (string)($resp['error']['message'] ?? '');
  $ok([
    'http_code' => (int)($resp['_http_code'] ?? 0),
    'has_candidates' => !empty($resp['candidates']),
    'text_len' => mb_strlen($txt),
    'error' => $err,
  ]);
  exit;
}

if ($ajax === 'get_prompt') {
  $p = $settingsRepo->getBotPrompt();
  $ok(['prompt' => (string)($p['prompt'] ?? ''), 'updated_at' => (string)($p['updated_at'] ?? '')]);
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
  $settingsRepo->setBotPrompt($prompt, date('Y-m-d H:i:s'));
  $ok(['saved' => true]);
  exit;
}

if ($ajax === 'stats') {
  $st = $rawRepo->getCountsForDay($date);
  $ok([
    'date' => $date,
    'count' => (int)($st['count'] ?? 0),
    'with_media' => (int)($st['with_media'] ?? 0),
    'with_media_text' => (int)($st['with_media_text'] ?? 0),
  ]);
  exit;
}

if ($ajax === 'summary') {
  $row = $dailyRepo->getByDay($date);
  $ok([
    'date' => $date,
    'exists' => is_array($row),
    'summary_text' => is_array($row) ? (string)($row['summary_text'] ?? '') : '',
    'events_json' => is_array($row) ? (string)($row['events_json'] ?? '[]') : '[]',
  ]);
  exit;
}

if ($ajax === 'last') {
  if ($adminKey !== '' && (string)($_GET['key'] ?? '') !== $adminKey) {
    $bad('forbidden');
    exit;
  }
  $limit = (int)($_GET['limit'] ?? 20);
  $limit = max(1, min(50, $limit));
  $items = [];
  $rows = $rawRepo->fetchLastForDay($date, $limit);
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

if (!$gemini->canCall()) {
  $bad('missing_gemini_key');
  exit;
}

$html = $announcementSvc->generate($date);

$ok(['date' => $date, 'html' => $html]);
