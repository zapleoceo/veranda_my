<?php
$tgToken = trim((string)($_ENV['TELEGRAM_BOT_TOKEN'] ?? $_ENV['TG_BOT_TOKEN'] ?? ''));
if ($tgToken === '') {
    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            [$name, $value] = explode('=', $line, 2);
            $_ENV[trim($name)] = trim($value);
        }
    }
    $tgToken = trim((string)($_ENV['TELEGRAM_BOT_TOKEN'] ?? $_ENV['TG_BOT_TOKEN'] ?? ''));
}

$tgUid = 3397075474;
$qrUrl = "https://img.vietqr.io/image/BIDV-96247Y294A-compact2.png?amount=100000&addInfo=RES%2012345&accountName=MELENTEV%20ANDREI";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "https://api.telegram.org/bot{$tgToken}/sendPhoto");
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, [
    'chat_id' => (string)$tgUid,
    'photo' => $qrUrl,
    'caption' => 'Test',
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$resp = curl_exec($ch);
echo $resp . "\n";
