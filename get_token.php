<?php
$_SERVER['DOCUMENT_ROOT'] = __DIR__;
require __DIR__ . '/src/classes/Database.php';
require __DIR__ . '/src/classes/PosterAPI.php';

// Load .env if it exists
if (file_exists(__DIR__ . '/.env')) {
    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($name, $value) = explode('=', $line, 2);
        $_ENV[$name] = trim($value);
    }
}

// Fallback to getenv
$token = $_ENV['POSTER_API_TOKEN'] ?? getenv('POSTER_API_TOKEN');

if (!$token) {
    // try to get from db
    $dbHost = $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: 'localhost';
    $dbName = $_ENV['DB_NAME'] ?? getenv('DB_NAME') ?: 'veranda_my';
    $dbUser = $_ENV['DB_USER'] ?? getenv('DB_USER') ?: 'veranda_my';
    $dbPass = $_ENV['DB_PASS'] ?? getenv('DB_PASS') ?: '';
    try {
        $db = new \App\Classes\Database($dbHost, $dbName, $dbUser, $dbPass, '');
        $token = $db->query("SELECT option_value FROM system_options WHERE option_name = 'poster_api_token'")->fetchColumn();
    } catch (\Exception $e) {}
}

if (!$token) {
    echo "TOKEN NOT FOUND\n";
    // Let's dump all env vars to see if apache sets it
    print_r($_ENV);
    print_r($_SERVER);
    exit;
}

$api = new \App\Classes\PosterAPI($token);
$dateFrom = '20260413';
$dateTo = '20260413';
$shifts = $api->request('finance.getCashShifts', ['dateFrom' => $dateFrom, 'dateTo' => $dateTo]);

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
