<?php

require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../src/classes/PosterAPI.php';
require_once __DIR__ . '/Model.php';

date_default_timezone_set('Asia/Ho_Chi_Minh');

veranda_require('banya');

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$token = trim((string)($_ENV['POSTER_API_TOKEN'] ?? ''));
$model = new \Banya\Model($token);

$ajax = $_GET['ajax'] ?? '';
if ($ajax) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    if ($token === '') {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'POSTER_API_TOKEN не задан'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $api = $model->getApi();

    try {
        if ($ajax === 'load') {
            $dateFrom = $model->parseDate((string)($_GET['date_from'] ?? ''));
            $dateTo = $model->parseDate((string)($_GET['date_to'] ?? ''));
            if ($dateFrom === null || $dateTo === null || $dateFrom > $dateTo) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Некорректный период'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $productMap = $model->loadProductMap();
            $items = [];
            $seenTx = [];

            $totalChecks = 0;
            $totalSumMinor = 0;
            $hookahSumMinor = 0;

            $spotIds = $model->loadSpotIds();
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
                    if ($hallIdRow !== (int)\Banya\Model::BANYA_HALL_ID && $tableIdRow !== 141) continue;
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
                    if ($menuCat !== \Banya\Model::HOOKAH_CATEGORY_ID) continue;
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
                $dateStr = $dateCloseStr !== '' ? $dateCloseStr : $model->fmtTs(isset($tx['date_start']) ? (int)$tx['date_start'] : 0);
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
                    'hall' => (string)\Banya\Model::BANYA_HALL_ID,
                    'spot_id' => $spotIdRow,
                    'table_id' => $tableIdRow,
                    'table' => $tableName,
                    'receipt' => $receipt,
                    'sum' => $model->fmtVnd($sumMinor),
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
                'hall_id' => \Banya\Model::BANYA_HALL_ID,
                'items' => $items,
                'totals' => [
                    'checks' => (int)$totalChecks,
                    'sum' => $model->fmtVnd($totalSumMinor),
                    'hookah_sum' => $model->fmtVnd($hookahSumMinor),
                    'without_hookah_sum' => $model->fmtVnd($totalSumMinor - $hookahSumMinor),
                ]
            ];
            echo json_encode($out, JSON_UNESCAPED_UNICODE);

        } elseif ($ajax === 'load_day') {
            $date = $model->parseDate((string)($_GET['date'] ?? ''));
            if ($date === null) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Некорректная дата'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $productMap = $model->loadProductMap();
            $spotIds = $model->loadSpotIds();
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
                    if ($hallIdRow !== (int)\Banya\Model::BANYA_HALL_ID && $tableIdRow !== 141) continue;

                    $products = is_array($tx['products'] ?? null) ? $tx['products'] : [];
                    $hookahMinorInCheck = 0;
                    foreach ($products as $p) {
                        if (!is_array($p)) continue;
                        $pid = (int)($p['product_id'] ?? 0);
                        if ($pid <= 0) continue;
                        $menuCat = (int)($productMap[$pid]['menu_category_id'] ?? 0);
                        if ($menuCat !== \Banya\Model::HOOKAH_CATEGORY_ID) continue;
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
                    $dateStr = $dateCloseStr !== '' ? $dateCloseStr : $model->fmtTs(isset($tx['date_start']) ? (int)$tx['date_start'] : 0);
                    if ($dateStr === '') $dateStr = '';
                    $receipt = (string)($tx['receipt_number'] ?? $tx['transaction_id'] ?? '');
                    $spotIdRow = (int)($tx['spot_id'] ?? 0);
                    $tableName = (string)($tx['table_name'] ?? $tableIdRow);
                    $waiter = (string)($tx['name'] ?? $tx['employee_name'] ?? '');

                    $items[] = [
                        'date' => $dateStr,
                        'hall' => (string)\Banya\Model::BANYA_HALL_ID,
                        'spot_id' => $spotIdRow,
                        'table_id' => $tableIdRow,
                        'table' => $tableName,
                        'receipt' => $receipt,
                        'sum' => $model->fmtVnd($sumMinor),
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

        } elseif ($ajax === 'tables_list') {
            $spotIds = $model->loadSpotIds();
            if (!$spotIds) $spotIds = [1];
            $seen = [];
            $out = [];
            foreach ($spotIds as $sid) {
                $rows = $api->request('spots.getTableHallTables', [
                    'spot_id' => (int)$sid,
                    'hall_id' => (int)\Banya\Model::BANYA_HALL_ID,
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

        } elseif ($ajax === 'load_table') {
            $dateFrom = $model->parseDate((string)($_GET['date_from'] ?? ''));
            $dateTo = $model->parseDate((string)($_GET['date_to'] ?? ''));
            $tableId = (int)($_GET['table_id'] ?? 0);
            if ($dateFrom === null || $dateTo === null || $dateFrom > $dateTo || $tableId <= 0) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Bad request'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $productMap = $model->loadProductMap();
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
                        if ($menuCat !== \Banya\Model::HOOKAH_CATEGORY_ID) continue;
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
                    $dateStr = $dateCloseStr !== '' ? $dateCloseStr : $model->fmtTs(isset($tx['date_start']) ? (int)$tx['date_start'] : 0);
                    if ($dateStr === '') $dateStr = '';
                    $receipt = (string)($tx['receipt_number'] ?? $tx['transaction_id'] ?? '');
                    $spotIdRow = (int)($tx['spot_id'] ?? 0);
                    $tableIdRow = (int)($tx['table_id'] ?? 0);
                    $tableName = (string)($tx['table_name'] ?? $tableIdRow);
                    $waiter = (string)($tx['name'] ?? $tx['employee_name'] ?? '');

                    $items[] = [
                        'date' => $dateStr,
                        'hall' => (string)\Banya\Model::BANYA_HALL_ID,
                        'spot_id' => $spotIdRow,
                        'table_id' => $tableIdRow,
                        'table' => $tableName,
                        'receipt' => $receipt,
                        'sum' => $model->fmtVnd($sumMinor),
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

        } elseif ($ajax === 'tx') {
            $trId = (int)($_GET['transaction_id'] ?? 0);
            if ($trId <= 0) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Bad request'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $productMap = $model->loadProductMap();
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
                    'sum' => $model->fmtVnd($lineMinor),
                    'sum_minor' => $lineMinor,
                ];
            }
            echo json_encode(['ok' => true, 'transaction_id' => $trId, 'lines' => $lines], JSON_UNESCAPED_UNICODE);
        }
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

$today = date('Y-m-d');
$firstOfMonth = date('Y-m-01');

require __DIR__ . '/view.php';
