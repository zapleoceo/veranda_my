<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/../../../auth_check.php';
require_once __DIR__ . '/../../../src/classes/PosterAPI.php';
require_once __DIR__ . '/Model.php';

veranda_require('zapara');
date_default_timezone_set('Asia/Ho_Chi_Minh');

$parseDate = function (string $s): ?string {
    $t = trim($s);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $t) ? $t : null;
};

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$respondError = function(int $code, string $err) {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $err], JSON_UNESCAPED_UNICODE);
    exit;
};

$ajax = (string)($_GET['ajax'] ?? '');
if ($ajax === '') {
    $respondError(404, 'Unknown ajax action');
}

$posterToken = trim((string)($_ENV['POSTER_API_TOKEN'] ?? $token ?? ''));
if ($posterToken === '') {
    $respondError(500, 'POSTER_API_TOKEN не задан');
}

$api = new \App\Classes\PosterAPI($posterToken);
$model = new ApiPosterZaparaModel($api);

if ($ajax === 'day') {
    $date = $parseDate((string)($_GET['date'] ?? ''));
    if ($date === null) {
        $respondError(400, 'Bad request');
    }
    try {
        echo json_encode($model->day($date), JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        $respondError(500, $e->getMessage());
    }
    exit;
}

if ($ajax === 'data') {
    $dateFrom = $parseDate((string)($_GET['date_from'] ?? ''));
    $dateTo = $parseDate((string)($_GET['date_to'] ?? ''));
    if ($dateFrom === null || $dateTo === null || $dateFrom > $dateTo) {
        $respondError(400, 'Bad request');
    }
    try {
        echo json_encode($model->data($dateFrom, $dateTo), JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        $respondError(500, $e->getMessage());
    }
    exit;
}

$respondError(404, 'Unknown ajax action');

