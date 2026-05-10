<?php
declare(strict_types=1);

$ctx = require __DIR__ . '/bootstrap.php';
$svc = $ctx['webhookSvc'];

$raw = file_get_contents('php://input');
$update = json_decode(is_string($raw) ? $raw : '', true);
echo 'ok';
if (function_exists('fastcgi_finish_request')) {
  fastcgi_finish_request();
} else {
  @ob_flush();
  @flush();
}

if (is_array($update)) $svc->handleUpdate($update);
