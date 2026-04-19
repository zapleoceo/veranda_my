<?php
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/config.php';

veranda_require('payday');

$action = (string)($_POST['action'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action !== '') {
    payday2_ensure_csrf();
    if (!payday2_csrf_valid()) {
        $error = 'Сессия устарела. Обновите страницу (CSRF).';
        $action = '';
    }
}

try {
    $api = new \App\Classes\PosterAPI((string)$token);
    $normalizePosterTx = function ($v): ?array {
        if (!is_array($v)) return null;
        if (isset($v[0]) && is_array($v[0])) return $v[0];
        return $v;
    };

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action !== '') {
        $actionFile = __DIR__ . '/post/' . preg_replace('/[^a-z0-9_]/', '', $action) . '.php';
        if (file_exists($actionFile)) {
            require $actionFile;
        }
    }
} catch (\Throwable $e) {
    if ($error === '') $error = $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action !== '') {
    if (!isset($_SESSION)) {
        if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
    }
    $_SESSION['payday_flash'] = [
        'message' => $message,
        'error' => $error,
        'at' => time(),
    ];
    header('Location: ?' . http_build_query(['dateFrom' => $dateFrom, 'dateTo' => $dateTo]));
    exit;
}
