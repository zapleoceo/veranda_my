<?php
/**
 * ==============================================================================
 * 🛑 STOP! READ THIS BEFORE MODIFYING THIS FILE OR DIRECTORY 🛑
 * ==============================================================================
 *
 * This directory (`payday2/`) strictly follows the principles of
 * Separation of Concerns (SoC) and the MVC (Model-View-Controller) pattern.
 *
 * It was explicitly refactored from a monolithic "God Object" script.
 * DO NOT write "spaghetti code" here!
 *
 * 📜 GUIDELINES:
 * 1. `index.php` (THIS FILE): Is the FRONT CONTROLLER. Its only job is to handle
 *    routing, initialization, and dispatching. DO NOT put business logic, HTML,
 *    or raw SQL queries here.
 * 2. `functions.php` / `src/Services`: Contains business logic, database queries,
 *    and shared helper functions (The "Model" / Service layer).
 * 3. `ajax.php`: Handles API endpoints returning JSON. Keep it lean; move heavy
 *    logic to reusable functions or services.
 * 4. `post.php`: Handles form submissions. It should process data and redirect.
 * 5. `view.php`: The presentation layer (The "View"). NO SQL QUERIES allowed here.
 *    Only use basic loops and variable outputs.
 * 6. JavaScript: Must go to `assets/js/payday2.js`. Pass variables from PHP
 *    using the `window.PAYDAY_CONFIG` object. DO NOT write inline `<script>`.
 * 7. CSS: Must go to `assets/css/payday2.css`. DO NOT use inline `style="..."`.
 *
 * Please keep this ecosystem clean and maintainable.
 * ==============================================================================
 */

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
