<?php
require_once __DIR__ . '/payday2/config.php';
require_once __DIR__ . '/src/classes/PosterAPI.php';

$api = new \App\Classes\PosterAPI((string)$token);
$dFrom = date('Ymd', strtotime('-2 days'));
$dTo = date('Ymd');
$shifts = $api->request('finance.getCashShifts', ['dateFrom' => $dFrom, 'dateTo' => $dTo]);
print_r($shifts[0] ?? []);

if (!empty($shifts[0]['shift_id'])) {
    $txs = $api->request('finance.getCashShiftTransactions', ['shift_id' => $shifts[0]['shift_id']]);
    print_r($txs[0] ?? []);
}
