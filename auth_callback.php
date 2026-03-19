<?php
require_once __DIR__ . '/src/classes/Database.php';
require_once __DIR__ . '/src/classes/Auth.php';

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
$googleClientId = $_ENV['GOOGLE_CLIENT_ID'] ?? '';
$googleClientSecret = $_ENV['GOOGLE_CLIENT_SECRET'] ?? '';
$googleRedirectUri = $_ENV['GOOGLE_REDIRECT_URI'] ?? 'https://veranda.my/auth_callback.php';

$db = new \App\Classes\Database($dbHost, $dbName, $dbUser, $dbPass);
$auth = new \App\Classes\Auth($db, $googleClientId, $googleClientSecret, $googleRedirectUri);

$code = $_GET['code'] ?? null;
if ($code) {
    if ($auth->handleCallback($code)) {
        header('Location: dashboard.php');
        exit;
    } else {
        header('Location: login.php?error=access_denied');
        exit;
    }
} else {
    header('Location: login.php?error=auth_failed');
    exit;
}
