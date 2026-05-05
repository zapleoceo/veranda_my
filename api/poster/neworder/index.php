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

    $comment = trim((string)($payload['comment'] ?? ''));

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
        $resp = $model->createOrder($spotIdReq, $tableId, $waiterId, $clientId, $serviceMode, $name, $comment, $products);
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
            'service_mode' => 1,
            'include_products' => 'false',
            'include_history' => 'false',
            'timezone' => 'client',
        ], 'GET');

        if (!is_array($transactions)) $transactions = [];

        $out = [];
        foreach ($transactions as $tr) {
            if ((int)($tr['table_id'] ?? 0) !== $tableIdReq) continue;
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

if ($ajax === 'get_transaction') {
    if (!$posterApi) {
        $respondError(500, 'Poster API Token not set');
    }

    $transactionId = (int)($_GET['transaction_id'] ?? 0);
    if ($transactionId <= 0) {
        $respondError(400, 'transaction_id required');
    }

    try {
        $res = $posterApi->request('dash.getTransaction', [
            'transaction_id' => $transactionId,
            'include_history' => 0,
            'include_products' => 1,
        ], 'GET');
        $tx = (is_array($res) && isset($res[0]) && is_array($res[0])) ? $res[0] : (is_array($res) ? $res : []);

        $products = [];
        $txProducts = $tx['products'] ?? [];
        if (is_array($txProducts)) {
            foreach ($txProducts as $p) {
                if (!is_array($p)) continue;
                $products[] = [
                    'id' => (int)($p['id'] ?? 0),
                    'product_id' => (int)($p['product_id'] ?? 0),
                    'product_name' => (string)($p['product_name'] ?? ''),
                    'count' => $p['count'] ?? ($p['num'] ?? null),
                    'comment' => (string)($p['comment'] ?? ''),
                ];
            }
        }

        $out = [
            'transaction_id' => (int)($tx['transaction_id'] ?? $transactionId),
            'spot_id' => (int)($tx['spot_id'] ?? 0),
            'table_id' => (int)($tx['table_id'] ?? 0),
            'service_mode' => (int)($tx['service_mode'] ?? 0),
            'sum' => (string)($tx['sum'] ?? ''),
            'comment' => (string)($tx['comment'] ?? ''),
            'products' => $products,
        ];
    } catch (\Throwable $e) {
        $respondError(500, 'Poster Error: ' . $e->getMessage());
    }

    $respondOk(['transaction' => $out]);
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

    $comment = trim((string)($payload['comment'] ?? ''));

    $added = 0;
    $commentUpdated = false;
    $commentMethod = '';
    try {
        if ($comment !== '') {
            $posterApi->request('transactions.changeComment', [
                'spot_id' => $spotIdReq,
                'spot_tablet_id' => $tabletId,
                'transaction_id' => $transactionId,
                'comment' => $comment,
            ], 'POST');
            $commentUpdated = true;
            $commentMethod = 'transactions.changeComment';
        }

        $txStartMs = 0;
        try {
            $txRes = $posterApi->request('dash.getTransaction', [
                'transaction_id' => $transactionId,
                'include_history' => 'false',
                'include_products' => 'false',
                'include_delivery' => 'false',
            ], 'GET');
            $tx = (is_array($txRes) && isset($txRes[0]) && is_array($txRes[0])) ? $txRes[0] : (is_array($txRes) ? $txRes : []);
            $dsNew = (int)($tx['date_start_new'] ?? 0);
            $ds = (int)($tx['date_start'] ?? 0);
            $txStartMs = $dsNew > 0 ? $dsNew : $ds;
        } catch (\Throwable $e) {
            $txStartMs = 0;
        }

        $baseTimeMs = (int)round(microtime(true) * 1000);
        if ($txStartMs > 0 && $baseTimeMs <= $txStartMs) {
            $baseTimeMs = $txStartMs + 1;
        }

        $i = 0;
        foreach ($products as $p) {
            $i++;
            $pid = (int)($p['product_id'] ?? $p['id'] ?? 0);
            $mid = (int)($p['modificator_id'] ?? $p['modificatorId'] ?? 0);
            $dishMods = $p['modification'] ?? null;
            $cnt = $p['count'] ?? 1;
            if ($pid <= 0) continue;
            if (!is_numeric($cnt)) $cnt = 1;
            $cnt = (float)$cnt;
            if ($cnt <= 0) continue;

            $modificationParam = '';
            if (is_array($dishMods) && count($dishMods) > 0) {
                $arr = [];
                foreach ($dishMods as $dm) {
                    if (!is_array($dm)) continue;
                    $did = (int)($dm['id'] ?? $dm['m'] ?? 0);
                    $dc = $dm['count'] ?? $dm['a'] ?? 1;
                    if ($did <= 0) continue;
                    if (!is_numeric($dc)) $dc = 1;
                    $dc = (float)$dc;
                    if ($dc <= 0) continue;
                    $arr[] = ['m' => $did, 'a' => $dc];
                }
                if ($arr) {
                    $modificationParam = json_encode($arr, JSON_UNESCAPED_UNICODE);
                }
            }

            $time = (string)($baseTimeMs + $i);
            $params = [
                'spot_id' => $spotIdReq,
                'spot_tablet_id' => $tabletId,
                'transaction_id' => $transactionId,
                'product_id' => $pid,
                'num' => $cnt,
                'time' => $time,
            ];
            if ($mid > 0) {
                $params['modificator_id'] = $mid;
            }
            if ($modificationParam !== '') {
                $params['modification'] = $modificationParam;
            }

            $posterApi->request('transactions.addTransactionProduct', $params, 'POST');

            $pc = trim((string)($p['comment'] ?? ''));
            if ($pc !== '') {
                $pccParams = [
                    'spot_id' => $spotIdReq,
                    'spot_tablet_id' => $tabletId,
                    'transaction_id' => $transactionId,
                    'product_id' => $pid,
                    'comment' => $pc,
                    'time' => $time,
                ];
                if ($mid > 0) {
                    $pccParams['modificator_id'] = $mid;
                }
                if ($modificationParam !== '') {
                    $pccParams['modification'] = $modificationParam;
                }
                $posterApi->request('transactions.changeProductComment', $pccParams, 'POST');
            }
            $added++;
        }
    } catch (\Throwable $e) {
        $respondError(500, 'Poster Error: ' . $e->getMessage());
    }

    $respondOk(['added' => $added, 'comment_updated' => $commentUpdated, 'comment_method' => $commentMethod]);
}

$respondError(404, 'Unknown ajax action');
