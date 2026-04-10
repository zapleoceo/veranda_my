<?php
require_once __DIR__ . '/.env'; // if needed, or just read .env
$lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
foreach ($lines as $line) {
    if (strpos($line, '=') !== false) {
        [$name, $value] = explode('=', $line, 2);
        $_ENV[trim($name)] = trim($value);
    }
}
require_once __DIR__ . '/src/classes/Database.php';
$db = new \App\Classes\Database($_ENV['DB_HOST'], $_ENV['DB_NAME'], $_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_TABLE_SUFFIX'] ?? '');
$t = $db->t('reservations');
$cols = $db->query("SHOW COLUMNS FROM {$t}")->fetchAll();
print_r($cols);
