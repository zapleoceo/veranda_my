<?php
require_once __DIR__ . '/src/classes/PosterAPI.php';

// Load .env
if (file_exists(__DIR__ . '/.env')) {
    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($name, $value) = explode('=', $line, 2);
        $_ENV[$name] = trim($value);
    }
}

$token = $_ENV['POSTER_API_TOKEN'] ?? '';
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
