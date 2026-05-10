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
$log = $ctx['log'] ?? null;

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
    ['system' => 'Reply with plain text only.', 'temperature' => 0.0, 'maxOutputTokens' => 50, 'tag' => 'gemini_test']
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

if ($ajax === 'log_tail') {
  if ($adminKey !== '' && (string)($_GET['key'] ?? '') !== $adminKey) {
    $bad('forbidden');
    exit;
  }
  $n = (int)($_GET['n'] ?? 80);
  $n = max(1, min(300, $n));
  $file = ($log instanceof \App\Classes\TestAILogger) ? $log->filePath() : '';
  if ($file === '' || !is_file($file)) {
    $bad('missing_log_file');
    exit;
  }
  $tail = function (string $path, int $lines, int $maxBytes = 200000): string {
    $size = @filesize($path);
    if (!is_int($size) || $size <= 0) return '';
    $fh = @fopen($path, 'rb');
    if (!is_resource($fh)) return '';
    $read = min($maxBytes, $size);
    @fseek($fh, -$read, SEEK_END);
    $buf = (string)@fread($fh, $read);
    @fclose($fh);
    $buf = str_replace("\r\n", "\n", $buf);
    $parts = array_values(array_filter(explode("\n", $buf), fn($x) => $x !== ''));
    $slice = array_slice($parts, max(0, count($parts) - $lines));
    return implode("\n", $slice);
  };
  $ok(['file' => $file, 'tail' => $tail($file, $n)]);
  exit;
}

if ($ajax === 'gemini_usage') {
  if ($adminKey !== '' && (string)($_GET['key'] ?? '') !== $adminKey) {
    $bad('forbidden');
    exit;
  }
  $file = ($log instanceof \App\Classes\TestAILogger) ? $log->filePath() : '';
  if ($file === '' || !is_file($file)) {
    $bad('missing_log_file');
    exit;
  }

  $maxBytes = (int)($_GET['max_bytes'] ?? 700000);
  $maxBytes = max(50000, min(2500000, $maxBytes));

  $size = @filesize($file);
  if (!is_int($size) || $size <= 0) {
    $ok(['file' => $file, 'size' => 0, 'events_parsed' => 0, 'counts' => []]);
    exit;
  }

  $fh = @fopen($file, 'rb');
  if (!is_resource($fh)) {
    $bad('cannot_open_log_file');
    exit;
  }
  $read = min($maxBytes, $size);
  @fseek($fh, -$read, SEEK_END);
  $buf = (string)@fread($fh, $read);
  @fclose($fh);
  $buf = str_replace("\r\n", "\n", $buf);
  $lines = array_values(array_filter(explode("\n", $buf), fn($x) => trim((string)$x) !== ''));

  $now = time();
  $counts = [
    'last_60s' => 0,
    'last_10m' => 0,
    'last_60m' => 0,
    'last_24h' => 0,
    'http' => [],
    'tag' => [],
  ];
  $eventsParsed = 0;
  $latest = null;
  $latestRate = null;

  foreach ($lines as $line) {
    if (strpos($line, '"event":"gemini_http"') === false) continue;
    $j = json_decode($line, true);
    if (!is_array($j) || (string)($j['event'] ?? '') !== 'gemini_http') continue;
    $eventsParsed++;

    $ts = (string)($j['ts'] ?? '');
    $t = strtotime($ts);
    if (!is_int($t) || $t <= 0) continue;

    $ctx = is_array($j['ctx'] ?? null) ? $j['ctx'] : [];
    $code = (int)($ctx['http_code'] ?? 0);
    $err = (string)($ctx['error'] ?? '');
    $tag = trim((string)($ctx['tag'] ?? ''));
    if (!isset($counts['http'][(string)$code])) $counts['http'][(string)$code] = 0;
    $counts['http'][(string)$code]++;
    if ($tag !== '') {
      if (!isset($counts['tag'][$tag])) $counts['tag'][$tag] = 0;
      $counts['tag'][$tag]++;
    }

    $age = $now - $t;
    if ($age <= 60) $counts['last_60s']++;
    if ($age <= 600) $counts['last_10m']++;
    if ($age <= 3600) $counts['last_60m']++;
    if ($age <= 86400) $counts['last_24h']++;

    if ($latest === null || $t >= (int)($latest['t'] ?? 0)) {
      $latest = [
        'ts' => $ts,
        't' => $t,
        'http_code' => $code,
        'has_error' => $err !== '' ? 1 : 0,
      ];
    }

    if ($err !== '' && preg_match('/limit:\s*(\d+)/i', $err, $m)) {
      $limit = (int)($m[1] ?? 0);
      $retrySec = null;
      if (preg_match('/retry in\s*([0-9.]+)s/i', $err, $m2)) $retrySec = (float)($m2[1] ?? 0);
      $latestRate = [
        'ts' => $ts,
        'http_code' => $code,
        'limit' => $limit,
        'retry_in_sec' => $retrySec,
      ];
    }
  }

  $assumedLimitPerMin = is_array($latestRate) && (int)($latestRate['limit'] ?? 0) > 0 ? (int)$latestRate['limit'] : null;
  $remaining = is_int($assumedLimitPerMin) ? max(0, $assumedLimitPerMin - (int)$counts['last_60s']) : null;

  $ok([
    'file' => $file,
    'size' => $size,
    'events_parsed' => $eventsParsed,
    'counts' => $counts,
    'latest' => $latest,
    'latest_rate_limit' => $latestRate,
    'assumed_limit_per_minute' => $assumedLimitPerMin,
    'assumed_remaining_this_minute' => $remaining,
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
