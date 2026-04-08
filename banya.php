<?php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/src/classes/PosterAPI.php';

date_default_timezone_set('Asia/Ho_Chi_Minh');

if (!veranda_can('admin')) {
    veranda_require('banya');
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

const BANYA_HALL_ID = 9;
const HOOKAH_CATEGORY_ID = 47;

$token = trim((string)($_ENV['POSTER_API_TOKEN'] ?? ''));

$parseDate = function (string $s): ?string {
    $t = trim($s);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $t) ? $t : null;
};

$fmtVnd = function ($minor): string {
    $vnd = (int)round(((float)$minor) / 100);
    return number_format($vnd, 0, '.', ' ');
};

$fmtTs = function (?int $ms): string {
    if (!$ms || $ms <= 0) return '';
    $dt = new DateTime('@' . (int)round($ms / 1000));
    $dt->setTimezone(new DateTimeZone('Asia/Ho_Chi_Minh'));
    return $dt->format('Y-m-d H:i:s');
};

$loadProductMap = function (\App\Classes\PosterAPI $api): array {
    $products = $api->request('menu.getProducts', []);
    if (!is_array($products)) $products = [];
    $map = [];
    foreach ($products as $p) {
        if (!is_array($p)) continue;
        $pid = (int)($p['product_id'] ?? 0);
        if ($pid <= 0) continue;
        $map[$pid] = [
            'name' => (string)($p['product_name'] ?? ''),
            'category_id' => (int)($p['category_id'] ?? $p['menu_category_id'] ?? $p['main_category_id'] ?? 0),
            'menu_category_id' => (int)($p['menu_category_id'] ?? $p['category_id'] ?? $p['main_category_id'] ?? 0),
            'sub_category_id' => (int)($p['sub_category_id'] ?? $p['menu_category_id2'] ?? $p['category2_id'] ?? 0),
        ];
    }
    return $map;
};

const BANYA_TABLES_WITHOUT_DELETED = 1;

function banya_load_table_halls(\App\Classes\PosterAPI $api, int $spotId): array {
    if ($spotId <= 0) return [];
    $rows = $api->request('spots.getTableHallTables', [
        'spot_id' => $spotId,
        'without_deleted' => BANYA_TABLES_WITHOUT_DELETED,
    ], 'GET');
    if (!is_array($rows)) $rows = [];
    $map = [];
    foreach ($rows as $r) {
        if (!is_array($r)) continue;
        $tid = (int)($r['table_id'] ?? 0);
        $hid = (int)($r['hall_id'] ?? 0);
        if ($tid > 0 && $hid > 0) $map[$tid] = $hid;
    }
    return $map;
}

function banya_load_spot_ids(\App\Classes\PosterAPI $api): array {
    $rows = $api->request('access.getSpots', [], 'GET');
    if (!is_array($rows)) $rows = [];
    $ids = [];
    foreach ($rows as $r) {
        if (!is_array($r)) continue;
        $sid = (int)($r['spot_id'] ?? $r['id'] ?? 0);
        if ($sid > 0) $ids[] = $sid;
    }
    $ids = array_values(array_unique($ids));
    sort($ids);
    return $ids;
}

function banya_load_tables_for_hall(\App\Classes\PosterAPI $api, int $spotId, int $hallId): array {
    if ($spotId <= 0 || $hallId <= 0) return [];
    $rows = $api->request('spots.getTableHallTables', [
        'spot_id' => $spotId,
        'hall_id' => $hallId,
        'without_deleted' => BANYA_TABLES_WITHOUT_DELETED,
    ], 'GET');
    if (!is_array($rows)) $rows = [];
    $out = [];
    foreach ($rows as $r) {
        if (!is_array($r)) continue;
        $tid = (int)($r['table_id'] ?? 0);
        if ($tid <= 0) continue;
        $out[] = [
            'table_id' => $tid,
            'table_num' => (string)($r['table_num'] ?? ''),
            'table_title' => (string)($r['table_title'] ?? ''),
        ];
    }
    return $out;
}

if (($_GET['ajax'] ?? '') === 'load') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    if ($token === '') {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'POSTER_API_TOKEN не задан'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $dateFrom = $parseDate((string)($_GET['date_from'] ?? ''));
    $dateTo = $parseDate((string)($_GET['date_to'] ?? ''));
    if ($dateFrom === null || $dateTo === null || $dateFrom > $dateTo) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Некорректный период'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $api = new \App\Classes\PosterAPI($token);
    try {
        $productMap = $loadProductMap($api);
        $items = [];
        $seenTx = [];

        $totalChecks = 0;
        $totalSumMinor = 0;
        $hookahSumMinor = 0;

        $spotIds = banya_load_spot_ids($api);
        if (!$spotIds) $spotIds = [1];

        $txBase = [];
        $nextTr = null;
        $prevNextTr = null;
        $guard = 0;
        do {
            $guard++;
            if ($guard > 20000) break;
            $params = [
                'dateFrom' => str_replace('-', '', $dateFrom),
                'dateTo' => str_replace('-', '', $dateTo),
                'include_products' => 'true',
                'status' => 2,
            ];
            if ($nextTr !== null) $params['next_tr'] = $nextTr;
            $batch = $api->request('dash.getTransactions', $params, 'GET');
            if (!is_array($batch)) $batch = [];
            $count = count($batch);
            if ($count > 0) {
                $last = end($batch);
                $prevNextTr = $nextTr;
                $nextTr = is_array($last) ? ($last['transaction_id'] ?? null) : null;
            }

            foreach ($batch as $tx) {
                if (!is_array($tx)) continue;
                $txId = (int)($tx['transaction_id'] ?? 0);
                if ($txId <= 0) continue;
                if (isset($seenTx[$txId])) continue;
                $seenTx[$txId] = true;
                $tableIdRow = (int)($tx['table_id'] ?? 0);
                if ($tableIdRow <= 0) continue;
                $hallIdRow = (int)($hallByTable[$tableIdRow] ?? 0);
                if ($hallIdRow !== (int)BANYA_HALL_ID && $tableIdRow !== 141) continue;
                $txBase[$txId] = $tx;
            }

            if ($nextTr !== null && $prevNextTr !== null && (string)$nextTr === (string)$prevNextTr) break;
        } while ($count > 0 && $nextTr !== null);

        foreach ($txBase as $txId => $tx) {
            $products = is_array($tx['products'] ?? null) ? $tx['products'] : [];
            $hookahMinorInCheck = 0;

            foreach ($products as $p) {
                if (!is_array($p)) continue;
                $pid = (int)($p['product_id'] ?? 0);
                if ($pid <= 0) continue;
                $menuCat = (int)($productMap[$pid]['menu_category_id'] ?? 0);
                if ($menuCat !== HOOKAH_CATEGORY_ID) continue;
                $numRaw = $p['num'] ?? $p['count'] ?? 0;
                $num = is_numeric($numRaw) ? (float)$numRaw : 0;
                $lineMinor = isset($p['payed_sum']) ? (int)$p['payed_sum'] : (int)($p['product_sum'] ?? 0);
                if ($lineMinor <= 0) {
                    $lineMinor = (int)round(((int)($p['product_price'] ?? 0)) * $num);
                }
                if ($lineMinor > 0) $hookahMinorInCheck += $lineMinor;
            }

            $sumMinor = (int)($tx['payed_sum'] ?? $tx['sum'] ?? 0);
            $dateCloseStr = (string)($tx['date_close_date'] ?? '');
            $dateStr = $dateCloseStr !== '' ? $dateCloseStr : $fmtTs(isset($tx['date_start']) ? (int)$tx['date_start'] : 0);
            if ($dateStr === '') $dateStr = '';

            $receipt = (string)($tx['receipt_number'] ?? $tx['transaction_id'] ?? '');
            $spotIdRow = (int)($tx['spot_id'] ?? 0);
            $tableIdRow = (int)($tx['table_id'] ?? 0);
            $tableName = (string)($tx['table_name'] ?? $tableIdRow);
            $waiter = (string)($tx['name'] ?? $tx['employee_name'] ?? '');

            if ($sumMinor <= 0) {
                continue;
            }
            $items[] = [
                'date' => $dateStr,
                'hall' => (string)BANYA_HALL_ID,
                'spot_id' => $spotIdRow,
                'table_id' => $tableIdRow,
                'table' => $tableName,
                'receipt' => $receipt,
                'sum' => $fmtVnd($sumMinor),
                'sum_minor' => $sumMinor,
                'hookah_sum_minor' => $hookahMinorInCheck,
                'waiter' => $waiter,
                'transaction_id' => (int)$txId,
            ];

            $totalChecks++;
            $totalSumMinor += $sumMinor;
            $hookahSumMinor += $hookahMinorInCheck;
        }

        usort($items, function ($a, $b) {
            return strcmp((string)$a['date'], (string)$b['date']);
        });

        $out = [
            'ok' => true,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'hall_id' => BANYA_HALL_ID,
            'items' => $items,
            'totals' => [
                'checks' => (int)$totalChecks,
                'sum' => $fmtVnd($totalSumMinor),
                'hookah_sum' => $fmtVnd($hookahSumMinor),
                'without_hookah_sum' => $fmtVnd($totalSumMinor - $hookahSumMinor),
            ]
        ];
        echo json_encode($out, JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if (($_GET['ajax'] ?? '') === 'load_day') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    if ($token === '') {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'POSTER_API_TOKEN не задан'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $date = $parseDate((string)($_GET['date'] ?? ''));
    if ($date === null) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Некорректная дата'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $api = new \App\Classes\PosterAPI($token);
    try {
        $productMap = $loadProductMap($api);
        $spotIds = banya_load_spot_ids($api);
        if (!$spotIds) $spotIds = [1];

        $hallByTable = [];
        foreach ($spotIds as $sid) {
            $rows = $api->request('spots.getTableHallTables', [
                'spot_id' => (int)$sid,
                'without_deleted' => 0,
            ], 'GET');
            if (!is_array($rows)) $rows = [];
            foreach ($rows as $r) {
                if (!is_array($r)) continue;
                $tid = (int)($r['table_id'] ?? 0);
                if ($tid <= 0) continue;
                $hid = (int)($r['hall_id'] ?? $r['table_hall_id'] ?? 0);
                if ($hid <= 0) continue;
                $hallByTable[$tid] = $hid;
            }
        }

        $seenTx = [];
        $items = [];
        $totalChecks = 0;
        $totalSumMinor = 0;
        $hookahSumMinor = 0;

        $nextTr = null;
        $prevNextTr = null;
        $guard = 0;
        do {
            $guard++;
            if ($guard > 20000) break;
            $params = [
                'dateFrom' => str_replace('-', '', $date),
                'dateTo' => str_replace('-', '', $date),
                'include_products' => 'true',
                'status' => 2,
            ];
            if ($nextTr !== null) $params['next_tr'] = $nextTr;
            $batch = $api->request('dash.getTransactions', $params, 'GET');
            if (!is_array($batch)) $batch = [];
            $count = count($batch);
            if ($count > 0) {
                $last = end($batch);
                $prevNextTr = $nextTr;
                $nextTr = is_array($last) ? ($last['transaction_id'] ?? null) : null;
            }

            foreach ($batch as $tx) {
                if (!is_array($tx)) continue;
                $txId = (int)($tx['transaction_id'] ?? 0);
                if ($txId <= 0) continue;
                if (isset($seenTx[$txId])) continue;
                $seenTx[$txId] = true;

                $tableIdRow = (int)($tx['table_id'] ?? 0);
                if ($tableIdRow <= 0) continue;
                $hallIdRow = (int)($hallByTable[$tableIdRow] ?? 0);
                if ($hallIdRow !== (int)BANYA_HALL_ID && $tableIdRow !== 141) continue;

                $products = is_array($tx['products'] ?? null) ? $tx['products'] : [];
                $hookahMinorInCheck = 0;
                foreach ($products as $p) {
                    if (!is_array($p)) continue;
                    $pid = (int)($p['product_id'] ?? 0);
                    if ($pid <= 0) continue;
                    $menuCat = (int)($productMap[$pid]['menu_category_id'] ?? 0);
                    if ($menuCat !== HOOKAH_CATEGORY_ID) continue;
                    $numRaw = $p['num'] ?? $p['count'] ?? 0;
                    $num = is_numeric($numRaw) ? (float)$numRaw : 0;
                    $lineMinor = isset($p['payed_sum']) ? (int)$p['payed_sum'] : (int)($p['product_sum'] ?? 0);
                    if ($lineMinor <= 0) {
                        $lineMinor = (int)round(((int)($p['product_price'] ?? 0)) * $num);
                    }
                    if ($lineMinor > 0) $hookahMinorInCheck += $lineMinor;
                }

                $sumMinor = (int)($tx['payed_sum'] ?? $tx['sum'] ?? 0);
                if ($sumMinor <= 0) continue;
                $dateCloseStr = (string)($tx['date_close_date'] ?? '');
                $dateStr = $dateCloseStr !== '' ? $dateCloseStr : $fmtTs(isset($tx['date_start']) ? (int)$tx['date_start'] : 0);
                if ($dateStr === '') $dateStr = '';
                $receipt = (string)($tx['receipt_number'] ?? $tx['transaction_id'] ?? '');
                $spotIdRow = (int)($tx['spot_id'] ?? 0);
                $tableName = (string)($tx['table_name'] ?? $tableIdRow);
                $waiter = (string)($tx['name'] ?? $tx['employee_name'] ?? '');

                $items[] = [
                    'date' => $dateStr,
                    'hall' => (string)BANYA_HALL_ID,
                    'spot_id' => $spotIdRow,
                    'table_id' => $tableIdRow,
                    'table' => $tableName,
                    'receipt' => $receipt,
                    'sum' => $fmtVnd($sumMinor),
                    'sum_minor' => $sumMinor,
                    'hookah_sum_minor' => $hookahMinorInCheck,
                    'waiter' => $waiter,
                    'transaction_id' => $txId,
                ];

                $totalChecks++;
                $totalSumMinor += $sumMinor;
                $hookahSumMinor += $hookahMinorInCheck;
            }

            if ($nextTr !== null && $prevNextTr !== null && (string)$nextTr === (string)$prevNextTr) break;
        } while ($count > 0 && $nextTr !== null);

        echo json_encode([
            'ok' => true,
            'date' => $date,
            'items' => $items,
            'totals' => [
                'checks' => (int)$totalChecks,
                'sum_minor' => (int)$totalSumMinor,
                'hookah_sum_minor' => (int)$hookahSumMinor,
            ],
        ], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if (($_GET['ajax'] ?? '') === 'tables_list') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    if ($token === '') {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'POSTER_API_TOKEN не задан'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $api = new \App\Classes\PosterAPI($token);
    try {
        $spotIds = banya_load_spot_ids($api);
        if (!$spotIds) $spotIds = [1];
        $seen = [];
        $out = [];
        foreach ($spotIds as $sid) {
            $rows = $api->request('spots.getTableHallTables', [
                'spot_id' => (int)$sid,
                'hall_id' => (int)BANYA_HALL_ID,
                'without_deleted' => 0,
            ], 'GET');
            if (!is_array($rows)) $rows = [];
            foreach ($rows as $r) {
                if (!is_array($r)) continue;
                $tid = (int)($r['table_id'] ?? 0);
                if ($tid <= 0 || isset($seen[$tid])) continue;
                $seen[$tid] = true;
                $out[] = [
                    'table_id' => $tid,
                    'table_num' => (string)($r['table_num'] ?? ''),
                    'table_title' => (string)($r['table_title'] ?? ''),
                ];
            }
        }
        if (!isset($seen[141])) {
            $out[] = ['table_id' => 141, 'table_num' => '141', 'table_title' => '141'];
        }
        echo json_encode(['ok' => true, 'tables' => $out], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if (($_GET['ajax'] ?? '') === 'load_table') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    if ($token === '') {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'POSTER_API_TOKEN не задан'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $dateFrom = $parseDate((string)($_GET['date_from'] ?? ''));
    $dateTo = $parseDate((string)($_GET['date_to'] ?? ''));
    $tableId = (int)($_GET['table_id'] ?? 0);
    if ($dateFrom === null || $dateTo === null || $dateFrom > $dateTo || $tableId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Bad request'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $api = new \App\Classes\PosterAPI($token);
    try {
        $productMap = $loadProductMap($api);
        $items = [];
        $seenTx = [];
        $totalChecks = 0;
        $totalSumMinor = 0;
        $hookahSumMinor = 0;

        $nextTr = null;
        $prevNextTr = null;
        $guard = 0;
        do {
            $guard++;
            if ($guard > 20000) break;
            $params = [
                'dateFrom' => str_replace('-', '', $dateFrom),
                'dateTo' => str_replace('-', '', $dateTo),
                'include_products' => 'true',
                'status' => 2,
                'table_id' => $tableId,
            ];
            if ($nextTr !== null) $params['next_tr'] = $nextTr;
            $batch = $api->request('dash.getTransactions', $params, 'GET');
            if (!is_array($batch)) $batch = [];
            $count = count($batch);
            if ($count > 0) {
                $last = end($batch);
                $prevNextTr = $nextTr;
                $nextTr = is_array($last) ? ($last['transaction_id'] ?? null) : null;
            }

            foreach ($batch as $tx) {
                if (!is_array($tx)) continue;
                $txId = (int)($tx['transaction_id'] ?? 0);
                if ($txId <= 0) continue;
                if (isset($seenTx[$txId])) continue;
                $seenTx[$txId] = true;

                $products = is_array($tx['products'] ?? null) ? $tx['products'] : [];
                $hookahMinorInCheck = 0;
                foreach ($products as $p) {
                    if (!is_array($p)) continue;
                    $pid = (int)($p['product_id'] ?? 0);
                    if ($pid <= 0) continue;
                    $menuCat = (int)($productMap[$pid]['menu_category_id'] ?? 0);
                    if ($menuCat !== HOOKAH_CATEGORY_ID) continue;
                    $numRaw = $p['num'] ?? $p['count'] ?? 0;
                    $num = is_numeric($numRaw) ? (float)$numRaw : 0;
                    $lineMinor = isset($p['payed_sum']) ? (int)$p['payed_sum'] : (int)($p['product_sum'] ?? 0);
                    if ($lineMinor <= 0) {
                        $lineMinor = (int)round(((int)($p['product_price'] ?? 0)) * $num);
                    }
                    if ($lineMinor > 0) $hookahMinorInCheck += $lineMinor;
                }

                $sumMinor = (int)($tx['payed_sum'] ?? $tx['sum'] ?? 0);
                if ($sumMinor <= 0) continue;
                $dateCloseStr = (string)($tx['date_close_date'] ?? '');
                $dateStr = $dateCloseStr !== '' ? $dateCloseStr : $fmtTs(isset($tx['date_start']) ? (int)$tx['date_start'] : 0);
                if ($dateStr === '') $dateStr = '';
                $receipt = (string)($tx['receipt_number'] ?? $tx['transaction_id'] ?? '');
                $spotIdRow = (int)($tx['spot_id'] ?? 0);
                $tableIdRow = (int)($tx['table_id'] ?? 0);
                $tableName = (string)($tx['table_name'] ?? $tableIdRow);
                $waiter = (string)($tx['name'] ?? $tx['employee_name'] ?? '');

                $items[] = [
                    'date' => $dateStr,
                    'hall' => (string)BANYA_HALL_ID,
                    'spot_id' => $spotIdRow,
                    'table_id' => $tableIdRow,
                    'table' => $tableName,
                    'receipt' => $receipt,
                    'sum' => $fmtVnd($sumMinor),
                    'sum_minor' => $sumMinor,
                    'hookah_sum_minor' => $hookahMinorInCheck,
                    'waiter' => $waiter,
                    'transaction_id' => $txId,
                ];

                $totalChecks++;
                $totalSumMinor += $sumMinor;
                $hookahSumMinor += $hookahMinorInCheck;
            }

            if ($nextTr !== null && $prevNextTr !== null && (string)$nextTr === (string)$prevNextTr) break;
        } while ($count > 0 && $nextTr !== null);

        echo json_encode([
            'ok' => true,
            'table_id' => $tableId,
            'items' => $items,
            'totals' => [
                'checks' => (int)$totalChecks,
                'sum_minor' => (int)$totalSumMinor,
                'hookah_sum_minor' => (int)$hookahSumMinor,
            ],
        ], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if (($_GET['ajax'] ?? '') === 'tx') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    if ($token === '') {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'POSTER_API_TOKEN не задан'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $trId = (int)($_GET['transaction_id'] ?? 0);
    if ($trId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Bad request'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $api = new \App\Classes\PosterAPI($token);
    try {
        $productMap = $loadProductMap($api);
        $txArr = $api->request('dash.getTransaction', [
            'transaction_id' => $trId,
            'include_products' => 'true',
            'include_history' => 'false',
            'include_delivery' => 'false',
        ], 'GET');
        $tx = is_array($txArr) && isset($txArr[0]) && is_array($txArr[0]) ? $txArr[0] : (is_array($txArr) ? $txArr : []);
        $products = is_array($tx['products'] ?? null) ? $tx['products'] : [];
        $lines = [];
        foreach ($products as $p) {
            if (!is_array($p)) continue;
            $pid = (int)($p['product_id'] ?? 0);
            $numRaw = $p['num'] ?? $p['count'] ?? 0;
            $num = is_numeric($numRaw) ? (float)$numRaw : 0;
            $lineMinor = isset($p['payed_sum']) ? (int)$p['payed_sum'] : (int)($p['product_sum'] ?? 0);
            if ($lineMinor <= 0) {
                $lineMinor = (int)($p['product_price'] ?? 0);
            }
            $name = (string)($productMap[$pid]['name'] ?? ('#' . $pid));
            $lines[] = [
                'name' => $name,
                'qty' => $num,
                'sum' => $fmtVnd($lineMinor),
                'sum_minor' => $lineMinor,
            ];
        }
        echo json_encode(['ok' => true, 'transaction_id' => $trId, 'lines' => $lines], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

$today = date('Y-m-d');
$firstOfMonth = date('Y-m-01');

?><!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Отчет баня</title>
    <link rel="icon" type="image/svg+xml" href="/links/favicon.svg">
    <script src="/assets/app.js" defer></script>
      <?php include $_SERVER['DOCUMENT_ROOT'] . '/analytics.php'; ?>
  <link rel="stylesheet" href="/assets/css/banya.css">
</head>
<body>
<div class="container" style="max-width: 1200px; margin: 0 auto; padding: 0 16px;">
    <div class="top-nav" style="display:flex; justify-content: space-between; align-items:center; gap: 16px; flex-wrap: wrap; padding: 12px 0;">
        <div class="nav-left" style="display:flex; gap: 14px; align-items:center; flex-wrap: wrap;">
            <div class="nav-title" style="font-weight: 800; color: var(--brand-text);">Отчет баня</div>
        </div>
        <div class="nav-mid"></div>
        <?php require __DIR__ . '/partials/user_menu.php'; ?>
    </div>
</div>
<div class="wrap">
    <div class="card">
        <div class="row">
            <div style="width: max-content;">
                <h1>Отчет баня</h1>
            </div>
            <label>
                Дата начала
                <input type="date" id="dateFrom" value="<?= htmlspecialchars($firstOfMonth) ?>">
            </label>
            <label>
                Дата конца
                <input type="date" id="dateTo" value="<?= htmlspecialchars($today) ?>">
            </label>
            <div class="controls">
                <button id="loadBtn" type="button">ЗАГРУЗИТЬ</button>
                <div class="progress" id="prog" style="display:none; align-items:center; gap: 10px; margin-left: 10px;">
                    <div class="bar" style="width: 104px; height: 10px; border-radius: 999px; background: rgba(182,89,48,0.12); overflow: hidden;">
                        <span id="progBar" style="display:block; height:100%; width:0; background: rgba(182,89,48,0.95); transition: width 0.15s ease;"></span>
                    </div>
                    <div class="label" id="progLabel" style="font-size: 12px; color:#1f2937; font-weight: 900; min-width: 44px;">0%</div>
                    <div class="desc" id="progDesc" style="font-size: 12px; color:#6b7280; font-weight: 800;"></div>
                </div>
                <div class="loader" id="loader" style="display:none;"><span class="spinner"></span><span class="muted">Загрузка…</span></div>
                <div class="toggle-wrap" title="Страницы">
                    <span class="toggle-text">страницы</span>
                    <label class="switch">
                        <input type="checkbox" id="noPages">
                        <span class="slider"></span>
                    </label>
                </div>
                <div class="toggle-wrap" title="Группировать по дням">
                    <span class="toggle-text">по дням</span>
                    <label class="switch">
                        <input type="checkbox" id="groupByDay">
                        <span class="slider"></span>
                    </label>
                </div>
                <div class="pager" id="pagerTop"></div>
            </div>
        </div>
        <div class="error" id="err" style="display:none;"></div>

        <table>
            <thead>
                <tr>
                    <th id="thDate" data-sort="date" style="width:170px; cursor:pointer;">Дата<span class="sort-arrow"></span></th>
                    <th id="thHall" data-sort="hall" style="width:80px; cursor:pointer;">Hall<span class="sort-arrow"></span></th>
                    <th id="thTable" data-sort="table" style="width:140px; cursor:pointer;">
                        <div class="table-filter">
                            <span>Стол</span><span class="sort-arrow"></span>
                            <button type="button" id="tableFilterBtn" class="table-filter-btn" title="Фильтр столов" aria-label="Фильтр столов">▾</button>
                            <div id="tableFilterPop" class="table-filter-pop" style="display:none;"></div>
                        </div>
                    </th>
                    <th id="thReceipt" data-sort="receipt" style="width:120px; cursor:pointer;">Чек<span class="sort-arrow"></span></th>
                    <th id="thWaiter" data-sort="waiter" style="cursor:pointer;">Официант<span class="sort-arrow"></span></th>
                    <th id="thSum" data-sort="sum_minor" style="width:140px; text-align:right; cursor:pointer;">Сумма<span class="sort-arrow"></span></th>
                    <th style="width:120px;"></th>
                </tr>
            </thead>
            <tbody id="tbody"></tbody>
        </table>
        <div style="display:flex; justify-content:flex-end; margin-top: 10px;">
            <div class="pager" id="pagerBottom"></div>
        </div>

        <div class="totals">
            <div class="pill" id="totChecks">Итого чеков: 0</div>
            <div class="pill ok" id="totSum">Итого сумма: 0</div>
            <div class="pill bad" id="totHookah">Сумма кальянов: 0</div>
            <div class="pill ok" id="totWithout">Сумма без кальянов: 0</div>
        </div>
        <div class="muted" style="margin-top: 8px; text-align:right;">Включены только столы Бани · кальяны: категория <?= (int)HOOKAH_CATEGORY_ID ?></div>
    </div>
</div>

<script src="/assets/js/banya.js"></script>
<script src="/assets/user_menu.js" defer></script>
</body>
</html>
