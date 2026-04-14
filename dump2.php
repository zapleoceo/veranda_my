<?php
require __DIR__ . '/src/classes/Database.php';
require __DIR__ . '/src/classes/PosterAPI.php';

$db = new \App\Classes\Database();
$token = $db->query("SELECT option_value FROM {$db->t('system_options')} WHERE option_name = 'poster_api_token' LIMIT 1")->fetchColumn();
$api = new \App\Classes\PosterAPI($token);

$dateFrom = '20260413';
$dateTo = '20260413';
$shifts = $api->request('finance.getCashShifts', ['dateFrom' => $dateFrom, 'dateTo' => $dateTo]);

if (!is_array($shifts)) die("No shifts\n");

foreach ($shifts as $s) {
    $shiftId = $s['cash_shift_id'] ?? 0;
    if ($shiftId) {
        echo "Shift: $shiftId\n";
        $txs = $api->request('finance.getCashShiftTransactions', ['shift_id' => $shiftId]);
        echo json_encode($txs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";
    }
}
