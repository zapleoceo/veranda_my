<?php
require_once __DIR__ . '/src/classes/PosterAPI.php';

if (file_exists(__DIR__ . '/.env')) {
    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) continue;
        [$name, $value] = explode('=', $line, 2);
        $_ENV[$name] = trim($value);
    }
}

$token = $_ENV['POSTER_API_TOKEN'] ?? '';
if (!$token) die("No token\n");

$api = new \App\Classes\PosterAPI($token);

try {
    echo "--- spots.getTableHallTables (with spot_id=1, hall_id=2) ---\n";
    $tables = $api->request('spots.getTableHallTables', ['spot_id' => 1, 'hall_id' => 2]);
    print_r($tables);

    echo "--- spots.getTableHallTables (without hall_id) ---\n";
    $tables2 = $api->request('spots.getTableHallTables', ['spot_id' => 1]);
    print_r($tables2);

    echo "--- spots.getSpotTablesHalls ---\n";
    $halls = $api->request('spots.getSpotTablesHalls', ['spot_id' => 1]);
    print_r($halls);

} catch (\Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
