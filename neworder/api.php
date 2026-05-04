<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/../src/classes/Database.php';
require_once __DIR__ . '/../src/classes/PosterAPI.php';
require_once __DIR__ . '/Model.php';

$ajax = $_GET['ajax'] ?? '';
$wantsJson = true;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$respondOk = function(array $data = []) {
    echo json_encode(array_merge(['ok' => true], $data), JSON_UNESCAPED_UNICODE);
    exit;
};

$respondError = function(int $code, string $err) {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $err], JSON_UNESCAPED_UNICODE);
    exit;
};

if (file_exists(__DIR__ . '/../.env')) {
    $lines = file(__DIR__ . '/../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        if (strpos($line, '=') === false) {
            continue;
        }
        [$name, $value] = explode('=', $line, 2);
        $name = trim($name);
        if ($name === '') {
            continue;
        }
        $_ENV[$name] = trim(trim((string)$value), '"\'');
    }
}

$spotId = (int)($_ENV['POSTER_SPOT_ID'] ?? 1);
if ($spotId <= 0) {
    $spotId = 1;
}

$apiToken = trim((string)($_ENV['POSTER_API_TOKEN'] ?? ''));
$posterApi = $apiToken !== '' ? new \App\Classes\PosterAPI($apiToken) : null;
$model = new NewOrderModel(null, $posterApi, $spotId);

if ($ajax === 'get_menu') {
    $dbHost = trim((string)($_ENV['DB_HOST'] ?? ''));
    $dbName = trim((string)($_ENV['DB_NAME'] ?? ''));
    $dbUser = trim((string)($_ENV['DB_USER'] ?? ''));
    $dbPass = (string)($_ENV['DB_PASS'] ?? '');
    $dbSuffix = trim((string)($_ENV['DB_TABLE_SUFFIX'] ?? ''));

    if ($dbHost === '' || $dbName === '' || $dbUser === '') {
        $respondError(500, 'Database credentials not configured');
    }

    $db = new \App\Classes\Database($dbHost, $dbName, $dbUser, $dbPass, $dbSuffix);
    if (!$db) {
        $respondError(500, 'Database connection failed');
    }

    try {
        $db->createMenuTables();
    } catch (\Throwable $e) {
    }

    $model = new NewOrderModel($db, $posterApi, $spotId);
    $lang = strtolower(trim((string)($_GET['lang'] ?? 'ru')));
    $supportedLangs = ['ru', 'en', 'vi', 'ko'];
    if (!in_array($lang, $supportedLangs, true)) {
        $lang = 'ru';
    }
    $trLang = $lang === 'vi' ? 'vn' : $lang;
    try {
        [$groups, $lastMenuSyncAt] = $model->getMenuGroups($trLang);
    } catch (\Throwable $e) {
        $respondError(500, 'Menu query failed: ' . $e->getMessage());
    }
    $respondOk(['groups' => $groups, 'last_sync_at' => $lastMenuSyncAt]);
}

if ($ajax === 'search_products') {
    $q = (string)($_GET['q'] ?? '');
    $q = trim($q);
    if ($q === '') {
        $respondOk(['products' => []]);
    }
    try {
        $products = $model->searchProducts($q, 30);
    } catch (\Throwable $e) {
        $respondError(500, 'Search failed: ' . $e->getMessage());
    }
    $respondOk(['products' => $products]);
}

if ($ajax === 'get_products') {
    if (!$posterApi) {
        $respondError(500, 'Poster API Token not set');
    }
    try {
        $products = $model->getPosterProductsDirect();
    } catch (\Throwable $e) {
        $respondError(500, 'Poster Error: ' . $e->getMessage());
    }
    $respondOk(['products' => $products, 'spot_id' => $spotId]);
}

if ($ajax === 'create_order') {
    $payloadJson = file_get_contents('php://input');
    $payload = json_decode($payloadJson, true);
    if (!is_array($payload)) $respondError(400, 'Bad request');

    // Phone validation
    $phone = trim((string)($payload['phone'] ?? ''));
    $phoneNorm = preg_replace('/\D+/', '', $phone);
    if ($phoneNorm === '' || !preg_match('/^[1-9]\d{6,15}$/', $phoneNorm)) {
        $respondError(400, 'Проверьте корректность номера телефона');
    }
    $phoneNorm = '+' . $phoneNorm;

    $name = trim((string)($payload['name'] ?? ''));
    if ($name === '') $respondError(400, 'Введите имя');

    $products = $payload['products'] ?? [];
    if (!is_array($products) || count($products) === 0) {
        $respondError(400, 'Корзина пуста');
    }

    $serviceMode = (int)($payload['service_mode'] ?? 2); // 2 - Takeaway, 3 - In place
    if (!in_array($serviceMode, [1, 2, 3], true)) $serviceMode = 2;

    $orderProducts = [];
    foreach ($products as $p) {
        $orderProducts[] = [
            'product_id' => (int)($p['product_id'] ?? 0),
            'count' => (int)($p['count'] ?? 1)
        ];
    }

    try {
        $resp = $model->createIncomingOrder($phoneNorm, $name, $serviceMode, $orderProducts);
    } catch (\Throwable $e) {
        $respondError(500, 'Poster Error: ' . $e->getMessage());
    }

    $orderId = (int)($resp['order_id'] ?? 0);
    if ($orderId <= 0) {
        $respondError(500, 'Не удалось создать заказ в Poster');
    }

    $respondOk(['order_id' => $orderId]);
}

$respondError(404, 'Unknown ajax action');
