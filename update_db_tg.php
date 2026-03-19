<?php
require_once __DIR__ . '/src/classes/Database.php';

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

try {
    $db = new \App\Classes\Database($dbHost, $dbName, $dbUser, $dbPass);
    $db->query("ALTER TABLE kitchen_stats ADD COLUMN IF NOT EXISTS tg_message_id BIGINT DEFAULT NULL;");
    echo "Database table updated: added tg_message_id.\n";
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
