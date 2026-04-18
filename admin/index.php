<?php
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../src/classes/PosterAPI.php';
require_once __DIR__ . '/../src/classes/PosterMenuSync.php';
require_once __DIR__ . '/../src/classes/MenuAutoFill.php';
require_once __DIR__ . '/../src/classes/TelegramBot.php';
require_once __DIR__ . '/../src/classes/MetaRepository.php';

veranda_require('admin');

$posterToken = $_ENV['POSTER_API_TOKEN'] ?? null;
if (!is_string($posterToken) || $posterToken === '') {
    if (file_exists(__DIR__ . '/../.env')) {
        $lines = file(__DIR__ . '/../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0) continue;
            if (strpos($line, '=') === false) continue;
            [$name, $value] = explode('=', $line, 2);
            $_ENV[$name] = trim($value);
        }
    }
    $posterToken = $_ENV['POSTER_API_TOKEN'] ?? '';
}
$posterToken = (string)$posterToken;

$message = '';
$error = '';

$tab = (string)($_GET['tab'] ?? 'sync');
$ajax = (string)($_GET['ajax'] ?? '');

if ($ajax === 'menu_publish') $tab = 'menu';
if ($ajax === 'telegram_test' || $ajax === 'telegram_status_ensure') $tab = 'telegram';

if ($tab === 'main') $tab = 'access';
if (!in_array($tab, ['sync', 'access', 'telegram', 'menu', 'categories', 'reservations', 'logs'], true)) {
    $tab = 'sync';
}

$controller_file = __DIR__ . '/controllers/' . $tab . '.php';
if (file_exists($controller_file)) {
    require_once $controller_file;
}

require_once __DIR__ . '/views/layout.php';
