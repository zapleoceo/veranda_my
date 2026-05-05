<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/../../../auth_check.php';
require_once __DIR__ . '/../../../src/classes/PosterAPI.php';
require_once __DIR__ . '/../../poster/rawdata/Model.php';

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
$isList = ($ajax === 'list') || ($ajax === '1');

$ks = $db->t('kitchen_stats');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_exclude_item'])) {
    if (!veranda_can('exclude_toggle')) {
        $respondError(403, 'Forbidden');
    }
    $itemId = (int)($_POST['toggle_exclude_item'] ?? 0);
    $excludeFlag = isset($_POST['exclude_from_dashboard']) ? 1 : 0;
    if ($itemId > 0) {
        $db->query("UPDATE {$ks} SET exclude_from_dashboard = ?, exclude_auto = 0 WHERE id = ?", [$excludeFlag, $itemId]);
    }
    $isAjax = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
    if ($isAjax) {
        echo json_encode([
            'ok' => true,
            'item_id' => $itemId,
            'exclude_from_dashboard' => $excludeFlag
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $returnQuery = (string)($_POST['return_query'] ?? '');
    header('Location: /rawdata/' . ($returnQuery ? ('?' . $returnQuery) : ''));
    exit;
}

if (!$isList) {
    $respondError(404, 'Unknown ajax action');
}

$selectedStatus = $_GET['status'] ?? 'all';
$dateFrom = $_GET['dateFrom'] ?? date('Y-m-d');
$dateTo = $_GET['dateTo'] ?? date('Y-m-d');
$hourStart = (int)($_GET['hourStart'] ?? 0);
$hourEnd = (int)($_GET['hourEnd'] ?? 23);
$stationFilter = (string)($_GET['station'] ?? 'all');

$closeReasonMap = [1 => 'Гость ушел', 2 => 'За счёт заведения', 3 => 'Ошибка официанта'];
$payTypeMap = [0 => 'Без оплаты', 1 => 'Наличные', 2 => 'Безнал', 3 => 'Смешанная'];

$posterToken = trim((string)($_ENV['POSTER_API_TOKEN'] ?? $token ?? ''));
$productNames = [];
$productMainCategory = [];
$productSubCategory = [];
if ($posterToken !== '') {
    try {
        $api = new \App\Classes\PosterAPI($posterToken);
        $posterModel = new ApiPosterRawdataModel($api);
        [$productNames, $productMainCategory, $productSubCategory] = $posterModel->getProductsMap();
    } catch (\Throwable $e) {
    }
}

$query = "SELECT id, transaction_date, receipt_number, transaction_opened_at, transaction_closed_at, transaction_id,
                 table_number, waiter_name, status, pay_type, close_reason,
                 dish_id, item_seq, dish_category_id, dish_sub_category_id, dish_name,
                 ticket_sent_at, ready_pressed_at, prob_close_at,
                 was_deleted, service_type, total_sum, station,
                 exclude_from_dashboard, exclude_auto,
                 tg_message_id, tg_acknowledged, tg_acknowledged_at, tg_acknowledged_by
          FROM {$ks}";
$where = [];
$params = [];

if ($selectedStatus === 'closed') {
    $where[] = "status = 2";
} elseif ($selectedStatus === 'open') {
    $where[] = "status = 1";
}

$where[] = "transaction_date BETWEEN ? AND ?";
$params[] = $dateFrom;
$params[] = $dateTo;

if ($stationFilter !== 'all' && $stationFilter !== '') {
    if ($stationFilter === '2') {
        $where[] = "(station = '2' OR station = 2 OR station = 'Kitchen' OR station = 'Main')";
    } elseif ($stationFilter === '3') {
        $where[] = "(station = '3' OR station = 3 OR station = 'Bar Veranda')";
    } else {
        $where[] = "station = ?";
        $params[] = $stationFilter;
    }
}

if ($hourStart > 0 || $hourEnd < 23) {
    $where[] = "HOUR(transaction_opened_at) BETWEEN ? AND ?";
    $params[] = $hourStart;
    $params[] = $hourEnd;
}

if (!empty($where)) {
    $query .= " WHERE " . implode(" AND ", $where);
}

$countQuery = "SELECT COUNT(DISTINCT transaction_id) AS c FROM {$ks}" . (!empty($where) ? (" WHERE " . implode(" AND ", $where)) : "");
$countRow = $db->query($countQuery, $params)->fetch();
$totalReceipts = (int)($countRow['c'] ?? 0);

$offset = max(0, (int)($_GET['offset'] ?? 0));
$limit = (int)($_GET['limit'] ?? 20);
if ($limit < 1) $limit = 20;
if ($limit > 50) $limit = 50;

$txSql = "SELECT transaction_id, receipt_number, MAX(ticket_sent_at) AS last_sent_at
          FROM {$ks}" . (!empty($where) ? (" WHERE " . implode(" AND ", $where)) : "") . "
          GROUP BY transaction_id, receipt_number
          ORDER BY last_sent_at DESC
          LIMIT {$limit} OFFSET {$offset}";
$txRows = $db->query($txSql, $params)->fetchAll();
$txIds = [];
foreach ($txRows as $r) {
    $txId = (int)($r['transaction_id'] ?? 0);
    if ($txId > 0) $txIds[] = $txId;
}

$groupedStats = [];
if (!empty($txIds)) {
    $placeholders = implode(',', array_fill(0, count($txIds), '?'));
    $allStats = $db->query(
        "SELECT * FROM {$ks} WHERE transaction_id IN ({$placeholders}) ORDER BY ticket_sent_at DESC",
        $txIds
    )->fetchAll();

    foreach ($allStats as $row) {
        $receipt = $row['receipt_number'] ?: 'Transaction #' . $row['transaction_id'];
        if (!isset($groupedStats[$receipt])) {
            $groupedStats[$receipt] = [
                'items' => [],
                'date' => $row['transaction_date'],
                'tx_id' => $row['transaction_id'],
                'opened_at' => $row['transaction_opened_at'],
                'closed_at' => $row['transaction_closed_at'],
                'table_number' => $row['table_number'] ?? null,
                'waiter_name' => $row['waiter_name'] ?? null,
                'status' => (int)$row['status'],
                'pay_type' => isset($row['pay_type']) ? (int)$row['pay_type'] : null,
                'close_reason' => isset($row['close_reason']) ? (int)$row['close_reason'] : null,
                'exclude_from_dashboard' => isset($row['exclude_from_dashboard']) ? (int)$row['exclude_from_dashboard'] : 0,
                'max_wait_time' => 0,
                'max_wait_is_uncertain' => false,
                'max_wait_fallback' => false,
                'max_wait_prob' => false,
                'max_wait_pstr' => false,
                'max_wait_log_close_at' => null,
                'max_wait_log_close_timestamp' => 0,
                'max_wait_time_reliable' => 0,
                'max_wait_reliable_log_close_at' => null,
                'max_wait_reliable_log_close_timestamp' => 0,
                'max_wait_time_uncertain' => 0,
                'max_wait_uncertain_source' => '',
                'max_wait_uncertain_log_close_at' => null,
                'max_wait_uncertain_log_close_timestamp' => 0,
                'has_hookah' => false,
                'opened_timestamp' => $row['transaction_opened_at'] ? strtotime($row['transaction_opened_at']) : 0,
                'closed_timestamp' => $row['transaction_closed_at'] ? strtotime($row['transaction_closed_at']) : 0
            ];
        }
        if (empty($groupedStats[$receipt]['closed_at']) && !empty($row['transaction_closed_at'])) {
            $groupedStats[$receipt]['closed_at'] = $row['transaction_closed_at'];
            $groupedStats[$receipt]['closed_timestamp'] = strtotime($row['transaction_closed_at']);
        }
        if (empty($groupedStats[$receipt]['table_number']) && !empty($row['table_number'])) {
            $groupedStats[$receipt]['table_number'] = $row['table_number'];
        }
        if (empty($groupedStats[$receipt]['waiter_name']) && !empty($row['waiter_name'])) {
            $groupedStats[$receipt]['waiter_name'] = $row['waiter_name'];
        }
        if ((int)$row['status'] > $groupedStats[$receipt]['status']) {
            $groupedStats[$receipt]['status'] = (int)$row['status'];
        }
        if ($groupedStats[$receipt]['pay_type'] === null && isset($row['pay_type']) && $row['pay_type'] !== null && $row['pay_type'] !== '') {
            $groupedStats[$receipt]['pay_type'] = (int)$row['pay_type'];
        }
        if ($groupedStats[$receipt]['close_reason'] === null && isset($row['close_reason']) && $row['close_reason'] !== null && $row['close_reason'] !== '') {
            $groupedStats[$receipt]['close_reason'] = (int)$row['close_reason'];
        }
        $groupedStats[$receipt]['items'][] = $row;

        $mainCat = isset($row['dish_category_id']) ? (int)$row['dish_category_id'] : 0;
        $subCat = isset($row['dish_sub_category_id']) ? (int)$row['dish_sub_category_id'] : 0;
        $dishId = (int)($row['dish_id'] ?? 0);
        if ($mainCat <= 0 && $dishId > 0) $mainCat = $productMainCategory[$dishId] ?? 0;
        if ($subCat <= 0 && $dishId > 0) $subCat = $productSubCategory[$dishId] ?? 0;
        $isHookah = ($mainCat === 47) || ($subCat === 47);
        if ($isHookah) {
            $groupedStats[$receipt]['has_hookah'] = true;
        }

        if ($isHookah) {
            continue;
        }
        if (!empty($row['was_deleted'])) {
            continue;
        }
        $sentTs = !empty($row['ticket_sent_at']) ? strtotime($row['ticket_sent_at']) : 0;
        if ($sentTs > 0) {
            $logicalCloseAt = null;
            $logicalCloseTs = 0;
            $logicalSource = '';
            $pstrAt = $row['ready_pressed_at'] ?? null;
            if (!empty($pstrAt)) {
                $ts = strtotime($pstrAt);
                if ($ts !== false && $ts >= $sentTs) {
                    $logicalCloseAt = $pstrAt;
                    $logicalCloseTs = $ts;
                    $logicalSource = 'pstr';
                }
            }
            if ($logicalCloseAt === null) {
                $candidates = [];
                $probAt = $row['prob_close_at'] ?? null;
                if (!empty($probAt)) {
                    $ts = strtotime($probAt);
                    if ($ts !== false && $ts >= $sentTs) {
                        $candidates['prob'] = ['ts' => $ts, 'at' => $probAt];
                    }
                }
                $closedAt = $row['transaction_closed_at'] ?? null;
                if (
                    !empty($closedAt) &&
                    $closedAt !== '0000-00-00 00:00:00' &&
                    (int)date('Y', strtotime($closedAt)) > 1970 &&
                    (int)($row['status'] ?? 1) > 1
                ) {
                    $ts = strtotime($closedAt);
                    if ($ts !== false && $ts >= $sentTs) {
                        $candidates['close'] = ['ts' => $ts, 'at' => $closedAt];
                    }
                }
                if (!empty($candidates)) {
                    $bestSrc = null;
                    $bestTs = 0;
                    $bestAt = null;
                    foreach ($candidates as $src => $c) {
                        $ts = (int)$c['ts'];
                        if ($bestSrc === null || $ts < $bestTs) {
                            $bestSrc = (string)$src;
                            $bestTs = $ts;
                            $bestAt = (string)$c['at'];
                        }
                    }
                    if ($bestSrc !== null && $bestAt !== null) {
                        $logicalCloseAt = $bestAt;
                        $logicalCloseTs = $bestTs;
                        $logicalSource = $bestSrc;
                    }
                }
            }
            if ($logicalCloseAt !== null) {
                $wait = ($logicalCloseTs - $sentTs) / 60;
                $waitRounded = round($wait, 1);
                $isUncertain = ($logicalSource !== 'pstr');

                if ($isUncertain && empty($row['exclude_from_dashboard']) && !empty($row['id'])) {
                    try {
                        $db->query(
                            "UPDATE {$ks} SET exclude_from_dashboard = 1, exclude_auto = 1 WHERE id = ?",
                            [(int)$row['id']]
                        );
                        $row['exclude_from_dashboard'] = 1;
                        $row['exclude_auto'] = 1;
                    } catch (\Exception $e) {
                    }
                }

                if ($isUncertain) {
                    if ($waitRounded > (float)$groupedStats[$receipt]['max_wait_time_uncertain']) {
                        $groupedStats[$receipt]['max_wait_time_uncertain'] = $waitRounded;
                        $groupedStats[$receipt]['max_wait_uncertain_source'] = (string)$logicalSource;
                        $groupedStats[$receipt]['max_wait_uncertain_log_close_at'] = $logicalCloseAt;
                        $groupedStats[$receipt]['max_wait_uncertain_log_close_timestamp'] = $logicalCloseTs;
                    }
                } else {
                    if (empty($row['exclude_from_dashboard']) && $waitRounded > (float)$groupedStats[$receipt]['max_wait_time_reliable']) {
                        $groupedStats[$receipt]['max_wait_time_reliable'] = $waitRounded;
                        $groupedStats[$receipt]['max_wait_reliable_log_close_at'] = $logicalCloseAt;
                        $groupedStats[$receipt]['max_wait_reliable_log_close_timestamp'] = $logicalCloseTs;
                    }
                }
            }
        }
    }
}

foreach ($groupedStats as &$g) {
    $reliable = (float)($g['max_wait_time_reliable'] ?? 0);
    $uncertain = (float)($g['max_wait_time_uncertain'] ?? 0);
    if ($reliable > 0) {
        $g['max_wait_time'] = $reliable;
        $g['max_wait_is_uncertain'] = false;
        $g['max_wait_fallback'] = false;
        $g['max_wait_prob'] = false;
        $g['max_wait_pstr'] = true;
        $g['max_wait_log_close_at'] = $g['max_wait_reliable_log_close_at'] ?? null;
        $g['max_wait_log_close_timestamp'] = (int)($g['max_wait_reliable_log_close_timestamp'] ?? 0);
    } elseif ($uncertain > 0) {
        $src = (string)($g['max_wait_uncertain_source'] ?? '');
        $g['max_wait_time'] = $uncertain;
        $g['max_wait_is_uncertain'] = true;
        $g['max_wait_fallback'] = ($src === 'close');
        $g['max_wait_prob'] = ($src === 'prob');
        $g['max_wait_pstr'] = false;
        $g['max_wait_log_close_at'] = $g['max_wait_uncertain_log_close_at'] ?? null;
        $g['max_wait_log_close_timestamp'] = (int)($g['max_wait_uncertain_log_close_timestamp'] ?? 0);
    } else {
        $g['max_wait_time'] = 0;
        $g['max_wait_is_uncertain'] = false;
        $g['max_wait_fallback'] = false;
        $g['max_wait_prob'] = false;
        $g['max_wait_pstr'] = false;
        $g['max_wait_log_close_at'] = null;
        $g['max_wait_log_close_timestamp'] = 0;
    }
}
unset($g);

ob_start();
if (!empty($groupedStats)) {
    include __DIR__ . '/../../../rawdata_receipts_chunk.php';
}
$html = ob_get_clean();

echo json_encode([
    'ok' => true,
    'html' => $html,
    'offset' => $offset,
    'limit' => $limit,
    'next_offset' => $offset + $limit,
    'total_receipts' => $totalReceipts,
    'has_more' => ($offset + $limit) < $totalReceipts
], JSON_UNESCAPED_UNICODE);
