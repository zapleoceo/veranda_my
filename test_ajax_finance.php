<?php
// Let's emulate the AJAX call.
$_SERVER['REQUEST_METHOD'] = 'POST';
$_GET['ajax'] = 'refresh_finance_transfers';
$payload = json_encode([
    'kind' => 'vietnam',
    'dateFrom' => '2024-04-13', // Or whatever date
    'dateTo' => '2024-04-14',
    'accountFrom' => 1,
    'accountTo' => 9
]);
// We need to pass the payload to php://input... well, we can't easily override php://input in CLI script without stream wrappers.
// Let's just copy the logic.
require_once __DIR__ . '/payday2/vendor/autoload.php';
$cfg = require __DIR__ . '/payday2/config.php';
$db = new PDO('mysql:host=127.0.0.1;dbname=zapleoce_my;charset=utf8mb4', 'zapleoce_my', 'r5sR1c6s', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$token = $db->query("SELECT val FROM settings WHERE k='poster_api_token'")->fetchColumn();
$api = new \App\Classes\PosterAPI($token);

$startTs = strtotime('2024-04-13 00:00:00');
$endTs = strtotime('2024-04-14 23:59:59');

$rows = $api->request('finance.getTransactions', [
    'dateFrom' => date('dmY', $startTs),
    'dateTo' => date('dmY', $endTs),
    'timezone' => 'client',
]);
echo "Rows: " . count($rows) . "\n";
