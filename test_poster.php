<?php
require_once __DIR__ . '/src/classes/Database.php';
require_once __DIR__ . '/src/classes/PosterAPI.php';

if (file_exists(__DIR__ . '/.env')) {
    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        [$name, $value] = explode('=', $line, 2);
        $name = trim($name);
        if ($name === '') continue;
        $value = trim($value);
        if (strpos($value, '"') === 0 && strrpos($value, '"') === strlen($value) - 1) {
            $value = substr($value, 1, -1);
        }
        $_ENV[$name] = $value;
        putenv("$name=$value");
    }
}

$dbHost = $_ENV['DB_HOST'] ?? 'localhost';
$dbName = $_ENV['DB_NAME'] ?? 'veranda';
$dbUser = $_ENV['DB_USER'] ?? 'root';
$dbPass = $_ENV['DB_PASS'] ?? '';
$tableSuffix = $_ENV['DB_TABLE_SUFFIX'] ?? '';

$db = new \App\Classes\Database($dbHost, $dbName, $dbUser, $dbPass, $tableSuffix);
$apiToken = $db->query("SELECT option_value FROM system_meta WHERE option_key = 'poster_api_token'")->fetchColumn();
if (!$apiToken) die("No API token");
$api = new \App\Classes\PosterAPI($apiToken);

try {
    $rows = $api->request('finance.getCashShifts', ['dateFrom' => '20260420', 'dateTo' => '20260421']);
    echo "First row keys: \n";
    if (!empty($rows)) {
        print_r(array_keys($rows[0]));
        print_r($rows[0]);
    } else {
        echo "No data returned\n";
    }
} catch (\Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
