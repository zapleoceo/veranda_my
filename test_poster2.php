<?php
$env = parse_ini_file(__DIR__ . '/.env');
$token = $env['POSTER_API_TOKEN'] ?? '';
require __DIR__ . '/src/classes/PosterAPI.php';

$api = new \App\Classes\PosterAPI($token);

$dateFrom = '20260413';
$dateTo = '20260413';
$shifts = $api->request('finance.getCashShifts', ['dateFrom' => $dateFrom, 'dateTo' => $dateTo]);

if (!is_array($shifts)) die("No shifts\n");

foreach ($shifts as $s) {
    $shiftId = $s['cash_shift_id'] ?? 0;
    if ($shiftId) {
        $txs = $api->request('finance.getCashShiftTransactions', ['shift_id' => $shiftId]);
        if (is_array($txs)) {
            foreach ($txs as $tx) {
                $cmt = $tx['comment'] ?? '';
                if (strpos($cmt, 'Supply #1831') !== false) {
                    echo json_encode($tx, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
                }
            }
        }
    }
}
