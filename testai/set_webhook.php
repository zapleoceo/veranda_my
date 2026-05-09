<?php
declare(strict_types=1);

$ctx = require __DIR__ . '/bootstrap.php';
$token = (string)($ctx['tgToken'] ?? '');
$appUrl = rtrim((string)($_ENV['APP_URL'] ?? 'https://veranda.my'), '/');

if ($token === '') {
  echo "Missing ai_tg_bot\n";
  exit(1);
}

$hookUrl = $appUrl . '/testai/webhook';
$url = "https://api.telegram.org/bot{$token}/setWebhook";
$payload = [
  'url' => $hookUrl,
  'allowed_updates' => json_encode(['message', 'edited_message'], JSON_UNESCAPED_UNICODE),
];

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
$response = curl_exec($ch);
$error = curl_error($ch);
curl_close($ch);

if ($response === false) {
  echo "setWebhook failed: {$error}\n";
  exit(1);
}

echo $response . "\n";

