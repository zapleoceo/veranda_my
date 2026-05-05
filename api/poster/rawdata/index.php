<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/../../../auth_check.php';
require_once __DIR__ . '/../../../src/classes/PosterAPI.php';
require_once __DIR__ . '/Model.php';

veranda_require('rawdata');
date_default_timezone_set('Asia/Ho_Chi_Minh');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$respondError = function(int $code, string $err) {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $err], JSON_UNESCAPED_UNICODE);
    exit;
};

$ajax = (string)($_GET['ajax'] ?? '');
if ($ajax !== 'products_map') {
    $respondError(404, 'Unknown ajax action');
}

$posterToken = trim((string)($_ENV['POSTER_API_TOKEN'] ?? $token ?? ''));
if ($posterToken === '') {
    $respondError(500, 'POSTER_API_TOKEN не задан');
}

$api = new \App\Classes\PosterAPI($posterToken);
$model = new ApiPosterRawdataModel($api);

try {
    [$names, $main, $sub] = $model->getProductsMap();
} catch (\Throwable $e) {
    $respondError(500, $e->getMessage());
}

echo json_encode(['ok' => true, 'names' => $names, 'main' => $main, 'sub' => $sub], JSON_UNESCAPED_UNICODE);

