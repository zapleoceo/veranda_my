<?php

require_once __DIR__ . '/src/classes/PosterAPI.php';
require_once __DIR__ . '/src/classes/KitchenAnalytics.php';
require_once __DIR__ . '/src/classes/Database.php';

use App\Classes\PosterAPI;
use App\Classes\KitchenAnalytics;
use App\Classes\Database;

// Загрузка .env (простая реализация без сторонних библиотек)
if (file_exists(__DIR__ . '/.env')) {
    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($name, $value) = explode('=', $line, 2);
        $_ENV[$name] = trim($value);
    }
}

// Конфигурация
$token = $_ENV['POSTER_API_TOKEN'] ?? null;
$baseUrl = $_ENV['POSTER_API_BASE_URL'] ?? 'https://joinposter.com/api';
$outputDir = __DIR__ . '/' . ($_ENV['OUTPUT_DIR'] ?? 'data/output');

// DB Config
$dbHost = $_ENV['DB_HOST'] ?? 'localhost';
$dbName = $_ENV['DB_NAME'] ?? 'veranda_my';
$dbUser = $_ENV['DB_USER'] ?? 'veranda_my';
$dbPass = $_ENV['DB_PASS'] ?? '';

if (!$token) {
    die("Error: POSTER_API_TOKEN not found in .env\n");
}

// Получение даты из аргументов командной строки или за вчера
$date = $argv[1] ?? date('Y-m-d', strtotime('-1 day'));

echo "🚀 Starting Kitchen Analytics for date: $date\n";

try {
    $db = new Database($dbHost, $dbName, $dbUser, $dbPass);
    $db->createTables(); // Ensure table exists

    $api = new PosterAPI($token, $baseUrl);
    $analytics = new KitchenAnalytics($api);

    echo "📡 Fetching data from Poster...\n";
    $stats = $analytics->getDailyStats($date);

    if (empty($stats)) {
        echo "⚠️ No data found for $date\n";
        exit;
    }

    echo "📊 Processed " . count($stats) . " dish records.\n";

    // Сохранение в MySQL
    echo "💾 Saving to Database...\n";
    $db->saveStats($stats);
    echo "✅ Database updated.\n";

    // Сохранение в JSON
    if (!is_dir($outputDir)) {
        mkdir($outputDir, 0777, true);
    }

    $jsonFile = "$outputDir/kitchen_$date.json";
    file_put_contents($jsonFile, json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo "✅ JSON saved: $jsonFile\n";

    // Сохранение в CSV
    $csvFile = "$outputDir/kitchen_$date.csv";
    $fp = fopen($csvFile, 'w');
    fputcsv($fp, array_keys($stats[0]));
    foreach ($stats as $row) {
        fputcsv($fp, $row);
    }
    fclose($fp);
    echo "✅ CSV saved: $csvFile\n";

} catch (\Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
