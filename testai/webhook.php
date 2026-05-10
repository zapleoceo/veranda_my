<?php
declare(strict_types=1);

$ctx = require __DIR__ . '/bootstrap.php';
$svc = $ctx['webhookSvc'];
$log = $ctx['log'] ?? null;

$raw = file_get_contents('php://input');
$update = json_decode(is_string($raw) ? $raw : '', true);
if ($log instanceof \App\Classes\TestAILogger) {
  $log->info('webhook_request', [
    'has_body' => is_string($raw) && $raw !== '' ? 1 : 0,
    'is_json' => is_array($update) ? 1 : 0,
    'update_id' => is_array($update) ? (int)($update['update_id'] ?? 0) : 0,
  ]);
}
echo 'ok';
if (function_exists('fastcgi_finish_request')) {
  fastcgi_finish_request();
} else {
  @ob_flush();
  @flush();
}

if (is_array($update)) $svc->handleUpdate($update);
