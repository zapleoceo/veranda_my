<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/Model.php';
require_once __DIR__ . '/../../../src/classes/PosterAPI.php';

$ajax = $_GET['ajax'] ?? '';

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

if (file_exists(__DIR__ . '/../../../.env')) {
    $lines = file(__DIR__ . '/../../../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
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

$spotId = (int)($_ENV['POSTER_SPOT_ID'] ?? (getenv('POSTER_SPOT_ID') !== false ? getenv('POSTER_SPOT_ID') : 1));
if ($spotId <= 0) {
    $spotId = 1;
}

$apiToken = trim((string)($_ENV['POSTER_API_TOKEN'] ?? (getenv('POSTER_API_TOKEN') !== false ? getenv('POSTER_API_TOKEN') : '')));
$posterApi = $apiToken !== '' ? new \App\Classes\PosterAPI($apiToken) : null;
$model = new ApiPosterNewOrderModel($posterApi, $spotId, $apiToken);

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

if ($ajax === 'get_tables') {
    if (!$posterApi) {
        $respondError(500, 'Poster API Token not set');
    }
    $spotIdReq = (int)($_GET['spot_id'] ?? $spotId);
    $hallIdReq = (int)($_GET['hall_id'] ?? 0);
    try {
        $tables = $model->getTables($spotIdReq, $hallIdReq);
    } catch (\Throwable $e) {
        $respondError(500, 'Poster Error: ' . $e->getMessage());
    }
    $respondOk(['tables' => $tables]);
}

if ($ajax === 'get_spots') {
    if (!$posterApi) {
        $respondError(500, 'Poster API Token not set');
    }
    try {
        $halls = $model->getSpotTablesHalls();
        $spotIds = [];
        foreach ($halls as $h) {
            $sid = (int)($h['spot_id'] ?? 0);
            if ($sid > 0) $spotIds[$sid] = true;
        }
        if (!$spotIds) {
            $spotIds[$spotId] = true;
        }
        $spots = [];
        foreach (array_keys($spotIds) as $sid) {
            $s = $model->getSpot((int)$sid);
            $spots[] = [
                'spot_id' => (int)($s['spot_id'] ?? $sid),
                'name' => (string)($s['name'] ?? ('Spot ' . $sid)),
                'address' => (string)($s['address'] ?? ''),
            ];
        }
    } catch (\Throwable $e) {
        $respondError(500, 'Poster Error: ' . $e->getMessage());
    }
    $respondOk(['spots' => $spots]);
}

if ($ajax === 'get_halls') {
    if (!$posterApi) {
        $respondError(500, 'Poster API Token not set');
    }
    $spotIdReq = (int)($_GET['spot_id'] ?? $spotId);
    if ($spotIdReq <= 0) $spotIdReq = $spotId;
    try {
        $hallsAll = $model->getSpotTablesHalls();
        $halls = [];
        foreach ($hallsAll as $h) {
            if ((int)($h['spot_id'] ?? 0) !== $spotIdReq) continue;
            if ((string)($h['delete'] ?? '0') === '1') continue;
            $halls[] = [
                'hall_id' => (int)($h['hall_id'] ?? 0),
                'hall_name' => (string)($h['hall_name'] ?? ''),
                'hall_order' => (int)($h['hall_order'] ?? 0),
            ];
        }
        usort($halls, function($a, $b) {
            return ((int)$a['hall_order'] <=> (int)$b['hall_order']) ?: ((int)$a['hall_id'] <=> (int)$b['hall_id']);
        });
    } catch (\Throwable $e) {
        $respondError(500, 'Poster Error: ' . $e->getMessage());
    }
    $respondOk(['halls' => $halls]);
}

if ($ajax === 'create_order') {
    $payloadJson = file_get_contents('php://input');
    $payload = json_decode($payloadJson, true);
    if (!is_array($payload)) $respondError(400, 'Bad request');

    $name = trim((string)($payload['name'] ?? ''));
    if ($name === '') $respondError(400, 'Введите имя');

    $products = $payload['products'] ?? [];
    if (!is_array($products) || count($products) === 0) {
        $respondError(400, 'Корзина пуста');
    }

    $serviceMode = 1;

    $spotIdReq = (int)($payload['spot_id'] ?? $spotId);
    if ($spotIdReq <= 0) $spotIdReq = $spotId;
    $tableId = (int)($payload['table_id'] ?? 0);
    if ($tableId < 0) $tableId = 0;
    $waiterId = (int)($payload['waiter_id'] ?? 0);
    if ($waiterId < 0) $waiterId = 0;
    $clientId = (int)($payload['client_id'] ?? 0);
    if ($clientId < 0) $clientId = 0;

    try {
        $resp = $model->createOrder($spotIdReq, $tableId, $waiterId, $clientId, $serviceMode, $name, $products);
    } catch (\Throwable $e) {
        $respondError(500, 'Poster Error: ' . $e->getMessage());
    }

    $orderId = (int)($resp['order_id'] ?? 0);
    if ($orderId <= 0) {
        $respondError(500, 'Не удалось создать заказ в Poster');
    }

    $respondOk(['order_id' => $orderId]);
}

if ($ajax === 'get_open_transactions') {
    if (!$posterApi) {
        $respondError(500, 'Poster API Token not set');
    }

    $spotIdReq = (int)($_GET['spot_id'] ?? $spotId);
    if ($spotIdReq <= 0) $spotIdReq = $spotId;
    $tableIdReq = (int)($_GET['table_id'] ?? 0);
    if ($tableIdReq <= 0) $respondError(400, 'table_id required');

    try {
        $transactions = $posterApi->request('dash.getTransactions', [
            'status' => 1,
            'spot_id' => $spotIdReq,
            'table_id' => $tableIdReq,
            'service_mode' => 1,
            'include_products' => 'false',
            'include_history' => 'false',
            'timezone' => 'client',
        ], 'GET');

        if (!is_array($transactions)) $transactions = [];

        $out = [];
        foreach ($transactions as $tr) {
            $trId = (int)($tr['transaction_id'] ?? 0);
            if ($trId <= 0) continue;

            $products = $posterApi->request('dash.getTransactionProducts', [
                'transaction_id' => $trId,
            ], 'GET');
            if (!is_array($products)) $products = [];

            $items = [];
            foreach ($products as $p) {
                $items[] = [
                    'product_name' => (string)($p['product_name'] ?? ''),
                    'num' => (string)($p['num'] ?? '1'),
                ];
            }

            $out[] = [
                'transaction_id' => $trId,
                'sum' => (string)($tr['sum'] ?? ($tr['payed_sum'] ?? '')),
                'items' => $items,
            ];
        }
    } catch (\Throwable $e) {
        $respondError(500, 'Poster Error: ' . $e->getMessage());
    }

    $respondOk(['transactions' => $out]);
}

if ($ajax === 'add_to_transaction') {
    if (!$posterApi) {
        $respondError(500, 'Poster API Token not set');
    }

    $payloadJson = file_get_contents('php://input');
    $payload = json_decode($payloadJson, true);
    if (!is_array($payload)) $respondError(400, 'Bad request');

    $spotIdReq = (int)($payload['spot_id'] ?? $spotId);
    if ($spotIdReq <= 0) $spotIdReq = $spotId;
    $tabletId = (int)($payload['spot_tablet_id'] ?? 0);
    if ($tabletId <= 0) $respondError(400, 'spot_tablet_id required');
    $transactionId = (int)($payload['transaction_id'] ?? 0);
    if ($transactionId <= 0) $respondError(400, 'transaction_id required');

    $products = $payload['products'] ?? [];
    if (!is_array($products) || count($products) === 0) {
        $respondError(400, 'Корзина пуста');
    }

    $added = 0;
    try {
        foreach ($products as $p) {
            $pid = (int)($p['product_id'] ?? $p['id'] ?? 0);
            $cnt = $p['count'] ?? 1;
            if ($pid <= 0) continue;
            if (!is_numeric($cnt)) $cnt = 1;
            $cnt = (float)$cnt;
            if ($cnt <= 0) continue;

            $posterApi->request('transactions.addTransactionProduct', [
                'spot_id' => $spotIdReq,
                'spot_tablet_id' => $tabletId,
                'transaction_id' => $transactionId,
                'product_id' => $pid,
                'num' => $cnt,
            ], 'POST');
            $added++;
        }
    } catch (\Throwable $e) {
        $respondError(500, 'Poster Error: ' . $e->getMessage());
    }

    $respondOk(['added' => $added]);
}

$respondError(404, 'Unknown ajax action');
