<?php
require_once __DIR__ . '/src/classes/PosterAPI.php';

$db = new PDO('mysql:host=127.0.0.1;dbname=zapleoce_my;charset=utf8mb4', 'zapleoce_my', 'r5sR1c6s', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$token = $db->query("SELECT option_value FROM system_options WHERE option_name='poster_api_token'")->fetchColumn();

$api = new \App\Classes\PosterAPI($token);

$date = '2026-04-13';
$startTs = strtotime($date . ' 00:00:00');
$endTs = strtotime($date . ' 23:59:59');

$rows = $api->request('finance.getTransactions', [
    'dateFrom' => date('Ymd', $startTs),
    'dateTo' => date('Ymd', $endTs),
    'timezone' => 'client',
]);
if (!is_array($rows) || count($rows) === 0) {
    $rows = $api->request('finance.getTransactions', [
        'dateFrom' => date('dmY', $startTs),
        'dateTo' => date('dmY', $endTs),
        'timezone' => 'client',
    ]);
}
echo "Rows count: " . (is_array($rows) ? count($rows) : 'not array') . "\n";
if (is_array($rows)) {
    foreach ($rows as $row) {
        $cmt = $row['comment'] ?? $row['description'] ?? $row['comment_text'] ?? '';
        if (mb_stripos($cmt, 'вьетна') !== false) {
            echo "Found: {$row['date']} - {$row['amount']} - {$cmt}\n";
        }
    }
}
