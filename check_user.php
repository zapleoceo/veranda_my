<?php
require_once __DIR__ . '/src/classes/Database.php';

$lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
foreach ($lines as $line) {
    if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) continue;
    [$name, $value] = explode('=', $line, 2);
    $_ENV[$name] = trim($value);
}

$db = new \App\Classes\Database($_ENV['DB_HOST'], $_ENV['DB_NAME'], $_ENV['DB_USER'], $_ENV['DB_PASS'], '');
$res = $db->query("SELECT telegram_username, permissions_json FROM users WHERE telegram_username = 'zapleosoft'")->fetchAll();
print_r($res);
