<?php
// Let's use veranda_require and the db instance from the app
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/src/classes/PosterAPI.php';

$token = $db->query("SELECT option_value FROM system_options WHERE option_name = 'poster_api_token' LIMIT 1")->fetchColumn();
$api = new \App\Classes\PosterAPI($token);

$date = '2026-04-13';
$startTs = strtotime($date . ' 00:00:00');
$endTs = strtotime($date . ' 23:59:59');

$rows = [];
try {
    $rows = $api->request('finance.getTransactions', [
        'dateFrom' => date('Ymd', $startTs),
        'dateTo' => date('Ymd', $endTs),
        'timezone' => 'client',
    ]);
} catch (\Throwable $e) {}

if (!is_array($rows) || count($rows) === 0) {
    try {
        $rows = $api->request('finance.getTransactions', [
            'dateFrom' => date('dmY', $startTs),
            'dateTo' => date('dmY', $endTs),
            'timezone' => 'client',
        ]);
    } catch (\Throwable $e) {}
}

echo "Rows count: " . (is_array($rows) ? count($rows) : 'not array') . "\n";
if (is_array($rows)) {
    foreach ($rows as $row) {
        $cmt = $row['comment'] ?? $row['description'] ?? $row['comment_text'] ?? '';
        if (mb_stripos($cmt, 'вьетна') !== false) {
            echo "Found: {$row['date']} - amount: {$row['amount']} - acc_from: {$row['account_from']} - acc_to: {$row['account_to']} - type: {$row['type']} - cmt: {$cmt}\n";
        }
    }
}
