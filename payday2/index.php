<?php
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../src/classes/PosterAPI.php';

veranda_require('payday');
$apiTzName = trim((string)($_ENV['POSTER_SPOT_TIMEZONE'] ?? 'Asia/Ho_Chi_Minh'));
if ($apiTzName === '') $apiTzName = 'Asia/Ho_Chi_Minh';
date_default_timezone_set($apiTzName);

$db->createPaydayTables();

$message = '';
$error = '';

if (!isset($_SESSION)) {
    if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
}
if (!empty($_SESSION['payday_flash']) && is_array($_SESSION['payday_flash'])) {
    $message = (string)($_SESSION['payday_flash']['message'] ?? '');
    $error = (string)($_SESSION['payday_flash']['error'] ?? '');
    unset($_SESSION['payday_flash']);
}

$dateFrom = trim((string)($_GET['dateFrom'] ?? ($_POST['dateFrom'] ?? '')));
$dateTo = trim((string)($_GET['dateTo'] ?? ($_POST['dateTo'] ?? '')));
$dateSingle = trim((string)($_GET['date'] ?? ($_POST['date'] ?? '')));

if ($dateFrom === '' && $dateTo === '' && $dateSingle !== '') {
    $dateFrom = $dateSingle;
    $dateTo = $dateSingle;
}
if ($dateFrom === '' && $dateTo !== '') $dateFrom = $dateTo;
if ($dateTo === '' && $dateFrom !== '') $dateTo = $dateFrom;

if ($dateFrom === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = date('Y-m-d');
if ($dateTo === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) $dateTo = $dateFrom;

if ($dateFrom > $dateTo) {
    $tmp = $dateFrom;
    $dateFrom = $dateTo;
    $dateTo = $tmp;
}


// Include helper functions and table initializations
require_once __DIR__ . '/functions.php';

// Route AJAX requests
if (isset($_GET['ajax']) && $_GET['ajax'] !== '') {
    require_once __DIR__ . '/ajax.php';
    exit;
}

// Route POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/post.php';
    exit;
}

// Fetch data and Render View
require_once __DIR__ . '/view.php';
