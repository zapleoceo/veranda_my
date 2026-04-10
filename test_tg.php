<?php
$tgToken = trim((string)($_ENV['TELEGRAM_BOT_TOKEN'] ?? $_ENV['TG_BOT_TOKEN'] ?? ''));
if ($tgToken === '') {
  // Use a temporary test token if none is available in environment
  echo "No token\n";
  exit;
}
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "https://api.telegram.org/bot{$tgToken}/sendPhoto");
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    'chat_id' => '3397075474', // Test chat id or similar
    'photo' => 'https://qr.sepay.vn/img?acc=96247Y294A&bank=BIDV&amount=100000&des=RES123',
    'caption' => 'Test QR'
]));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$resp = curl_exec($ch);
curl_close($ch);
echo $resp . "\n";
