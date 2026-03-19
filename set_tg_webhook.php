<?php
if (file_exists(__DIR__ . '/.env')) {
    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) {
            continue;
        }
        [$name, $value] = explode('=', $line, 2);
        $_ENV[$name] = trim($value);
    }
}

$token = $_ENV['TELEGRAM_BOT_TOKEN'] ?? '';
$appUrl = rtrim($_ENV['APP_URL'] ?? 'https://veranda.my', '/');
if ($token === '') {
    echo "Missing TELEGRAM_BOT_TOKEN\n";
    exit(1);
}

$hookUrl = $appUrl . '/telegram_webhook.php';
$url = "https://api.telegram.org/bot{$token}/setWebhook";

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['url' => $hookUrl]));
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
