<?php
require_once __DIR__ . '/config.php';
$ls = \App\Payday2\LocalSettings::merged();
$token = trim((string)($ls['poster_token'] ?? ''));
require_once '/workspace/src/classes/PosterAPI.php';
$api = new \App\Classes\PosterAPI($token);
$shifts = $api->request('finance.getCashShifts', ['dateFrom' => date('Ymd', strtotime('-5 days')), 'dateTo' => date('Ymd')]);
if (is_array($shifts) && count($shifts) > 0) {
    $shiftId = $shifts[0]['shift_id'] ?? $shifts[0]['cash_shift_id'];
    echo "Shift ID: $shiftId\n";
    $txs = $api->request('finance.getCashShiftTransactions', ['shift_id' => $shiftId]);
    print_r($txs);
} else {
    echo "No shifts found\n";
}
