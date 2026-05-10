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

$ajax = trim((string)($_GET['ajax'] ?? ''));
$accept = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
$wantHtml = ((string)($_GET['format'] ?? '') === 'html')
  || (strpos($accept, 'text/html') !== false && strpos($accept, 'application/json') === false);
if ($ajax === 'gemini_usage' && $wantHtml) {
  header('Content-Type: text/html; charset=utf-8');
} else {
  header('Content-Type: application/json; charset=utf-8');
}
if (in_array($ajax, ['health', 'gemini_test', 'log_tail', 'gemini_usage'], true)) {
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');
}
$date = trim((string)($_GET['date'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');

$ok = fn(array $extra = []) => print json_encode(array_merge(['ok' => true], $extra), JSON_UNESCAPED_UNICODE);
$bad = fn(string $msg, array $extra = []) => print json_encode(array_merge(['ok' => false, 'error' => $msg], $extra), JSON_UNESCAPED_UNICODE);

$requireAdmin = function () use ($adminKey, $bad): void {
  if ($adminKey !== '' && (string)($_GET['key'] ?? '') !== $adminKey) {
    $bad('forbidden');
    exit;
  }
};

$routes = [
  'get' => __DIR__ . '/api/get.php',
  'health' => __DIR__ . '/api/health.php',
  'gemini_test' => __DIR__ . '/api/gemini_test.php',
  'log_tail' => __DIR__ . '/api/log_tail.php',
  'gemini_usage' => __DIR__ . '/api/gemini_usage.php',
  'get_prompt' => __DIR__ . '/api/get_prompt.php',
  'set_prompt' => __DIR__ . '/api/set_prompt.php',
  'stats' => __DIR__ . '/api/stats.php',
  'summary' => __DIR__ . '/api/summary.php',
  'last' => __DIR__ . '/api/last.php',
  'generate' => __DIR__ . '/api/generate.php',
];

if ($ajax === '' || !isset($routes[$ajax])) {
  $bad('bad_request');
  exit;
}

require $routes[$ajax];
