<?php

require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/Model.php';

date_default_timezone_set('Asia/Ho_Chi_Minh');

if (function_exists('veranda_require')) {
    veranda_require('roma');
}

$token = trim((string)($_ENV['POSTER_API_TOKEN'] ?? ''));

const NO_STORE_HEADERS = [
    'Cache-Control: no-store, no-cache, must-revalidate, max-age=0',
    'Pragma: no-cache',
];

$parseDate = function (string $s): ?string {
    $t = trim($s);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $t)) return $t;
    return null;
};

if (($_GET['ajax'] ?? '') === 'load') {
    foreach (NO_STORE_HEADERS as $h) header($h);
    header('Content-Type: application/json; charset=utf-8');

    $dateFrom = $parseDate((string)($_GET['date_from'] ?? ''));
    $dateTo = $parseDate((string)($_GET['date_to'] ?? ''));
    if ($dateFrom === null || $dateTo === null || $dateFrom > $dateTo) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Некорректный период'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $model = new \App\Roma\Model($token);
    try {
        $data = $model->getSales($dateFrom, $dateTo);
        $data['ok'] = true;
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

$today = date('Y-m-d');
$firstOfMonth = date('Y-m-01');

require __DIR__ . '/view.php';
