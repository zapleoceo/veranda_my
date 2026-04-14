<?php
require_once __DIR__ . '/vendor/autoload.php';

$cfg = require __DIR__ . '/config.php';
$token = $cfg['poster_api_token'] ?? '';
if (!$token) {
    // Try db
    $db = new PDO('mysql:host=127.0.0.1;dbname=zapleoce_my;charset=utf8mb4', 'zapleoce_my', 'r5sR1c6s', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $token = $db->query("SELECT val FROM settings WHERE k='poster_api_token'")->fetchColumn();
}
if (!$token) {
    echo "No token\n"; exit;
}

$api = new \App\Classes\PosterAPI($token);

try {
    $rows1 = $api->request('finance.getTransactions', [
        'dateFrom' => '20240413',
        'dateTo' => '20240414',
        'timezone' => 'client',
    ]);
    echo "Ymd rows: " . count($rows1) . "\n";
} catch (\Throwable $e) {
    echo "Ymd error: " . $e->getMessage() . "\n";
}

try {
    $rows2 = $api->request('finance.getTransactions', [
        'dateFrom' => '13042024',
        'dateTo' => '14042024',
        'timezone' => 'client',
    ]);
    echo "dmY rows: " . count($rows2) . "\n";
} catch (\Throwable $e) {
    echo "dmY error: " . $e->getMessage() . "\n";
}
