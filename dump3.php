<?php
require __DIR__ . '/src/classes/PosterAPI.php';

$env = file_get_contents(__DIR__ . '/.env');
preg_match('/POSTER_API_TOKEN=(.+)/', $env, $m);
$token = trim($m[1] ?? '');

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
