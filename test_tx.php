<?php
require_once __DIR__ . '/src/classes/PosterAPI.php';

$env = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$token = '';
foreach ($env as $line) {
    if (strpos($line, 'POSTER_API_TOKEN=') === 0) {
        $token = trim(explode('=', $line, 2)[1], '"\'');
    }
}
if (!$token) die("No token");

$api = new \App\Classes\PosterAPI($token);
$shifts = $api->request('finance.getCashShifts', ['dateFrom' => date('Ymd', strtotime('-5 days')), 'dateTo' => date('Ymd')]);
if (!empty($shifts[0]['shift_id']) || !empty($shifts[0]['cash_shift_id'])) {
    $shiftId = $shifts[0]['shift_id'] ?? $shifts[0]['cash_shift_id'];
    $txs = $api->request('finance.getCashShiftTransactions', ['shift_id' => $shiftId]);
    echo json_encode(array_slice((array)$txs, 0, 5), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
} else {
    echo "No shifts found\n";
}
