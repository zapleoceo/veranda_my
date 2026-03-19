<?php
date_default_timezone_set('Asia/Ho_Chi_Minh');

require_once __DIR__ . '/src/classes/Database.php';
require_once __DIR__ . '/src/classes/PosterAPI.php';
require_once __DIR__ . '/src/classes/KitchenAnalytics.php';

// Load .env
if (file_exists(__DIR__ . '/.env')) {
    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($name, $value) = explode('=', $line, 2);
        $_ENV[$name] = trim($value);
    }
}

$dbHost = $_ENV['DB_HOST'] ?? 'localhost';
$dbName = $_ENV['DB_NAME'] ?? 'veranda_my';
$dbUser = $_ENV['DB_USER'] ?? 'veranda_my';
$dbPass = $_ENV['DB_PASS'] ?? '';
$token = $_ENV['POSTER_API_TOKEN'] ?? '';

try {
    $db = new \App\Classes\Database($dbHost, $dbName, $dbUser, $dbPass);
    $api = new \App\Classes\PosterAPI($token);
    $analytics = new \App\Classes\KitchenAnalytics($api);

    $startDate = '2026-03-01';
    $endDate = date('Y-m-d');
    
    $currentDate = new DateTime($startDate);
    $lastDate = new DateTime($endDate);

    echo "[" . date('Y-m-d H:i:s') . "] Starting historical sync from $startDate to $endDate...\n";

    while ($currentDate <= $lastDate) {
        $dateStr = $currentDate->format('Y-m-d');
        echo "[" . date('Y-m-d H:i:s') . "] Syncing $dateStr... ";
        
        $stats = $analytics->getDailyStats($dateStr);
        
        if (!empty($stats)) {
            $db->saveStats($stats);
            echo "Done (" . count($stats) . " records).\n";
        } else {
            echo "No records found.\n";
        }
        
        // Небольшая пауза, чтобы не превысить лимиты API Poster
        usleep(200000); // 0.2 секунды
        
        $currentDate->modify('+1 day');
    }

    echo "[" . date('Y-m-d H:i:s') . "] Historical sync completed!\n";

} catch (\Exception $e) {
    echo "[" . date('Y-m-d H:i:s') . "] ERROR: " . $e->getMessage() . "\n";
}
