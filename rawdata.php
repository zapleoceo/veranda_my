<?php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/src/classes/PosterAPI.php';
veranda_require('rawdata');
date_default_timezone_set('Asia/Ho_Chi_Minh');

// Получаем фильтры из GET
$selectedStatus = $_GET['status'] ?? 'all';
$dateFrom = $_GET['dateFrom'] ?? date('Y-m-d');
$dateTo = $_GET['dateTo'] ?? date('Y-m-d');
$hourStart = (int)($_GET['hourStart'] ?? 0);
$hourEnd = (int)($_GET['hourEnd'] ?? 23);
$stationFilter = (string)($_GET['station'] ?? 'all');
$lastSyncLabel = '—';
$closeReasonMap = [1 => 'Гость ушел', 2 => 'За счёт заведения', 3 => 'Ошибка официанта'];
$payTypeMap = [0 => 'Без оплаты', 1 => 'Наличные', 2 => 'Безнал', 3 => 'Смешанная'];
$dashboardQuery = http_build_query([
    'dateFrom' => $dateFrom,
    'dateTo' => $dateTo,
    'hourStart' => $hourStart,
    'hourEnd' => $hourEnd,
    'station' => $stationFilter
]);

try {
    $ks = $db->t('kitchen_stats');
    $metaTable = $db->t('system_meta');
    $api = new \App\Classes\PosterAPI($token);
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_exclude_item'])) {
        if (!veranda_can('exclude_toggle')) {
            $isAjax = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
            if ($isAjax) {
                http_response_code(403);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false], JSON_UNESCAPED_UNICODE);
                exit;
            }
            http_response_code(403);
            echo 'Forbidden';
            exit;
        }
        $itemId = (int)($_POST['toggle_exclude_item'] ?? 0);
        $excludeFlag = isset($_POST['exclude_from_dashboard']) ? 1 : 0;
        if ($itemId > 0) {
            $db->query("UPDATE {$ks} SET exclude_from_dashboard = ?, exclude_auto = 0 WHERE id = ?", [$excludeFlag, $itemId]);
        }
        $isAjax = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => true,
                'item_id' => $itemId,
                'exclude_from_dashboard' => $excludeFlag
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $returnQuery = $_POST['return_query'] ?? '';
        header('Location: rawdata.php' . ($returnQuery ? ('?' . $returnQuery) : ''));
        exit;
    }
    try {
        $meta = $db->query("SELECT meta_value FROM {$metaTable} WHERE meta_key = 'poster_last_sync_at' LIMIT 1")->fetch();
        if (!empty($meta['meta_value'])) {
            $lastSyncLabel = date('d.m.Y H:i:s', strtotime($meta['meta_value']));
        }
    } catch (\Exception $e) {
    }
    if ($lastSyncLabel === '—') {
        $fallback = $db->query("SELECT MAX(created_at) AS last_sync_at FROM {$ks}")->fetch();
        if (!empty($fallback['last_sync_at'])) {
            $lastSyncLabel = date('d.m.Y H:i:s', strtotime($fallback['last_sync_at']));
        }
    }
    
    $productNames = [];
    $productMainCategory = [];
    $productSubCategory = [];
    $cacheTtl = 6 * 60 * 60;
    if (
        !empty($_SESSION['products_cache_ts']) &&
        (time() - (int)$_SESSION['products_cache_ts']) < $cacheTtl &&
        isset($_SESSION['products_cache_names'], $_SESSION['products_cache_main'], $_SESSION['products_cache_sub'])
    ) {
        $productNames = (array)$_SESSION['products_cache_names'];
        $productMainCategory = (array)$_SESSION['products_cache_main'];
        $productSubCategory = (array)$_SESSION['products_cache_sub'];
    } else {
        $productsRaw = $api->request('menu.getProducts');
        foreach ($productsRaw as $p) {
            $pid = (int)($p['product_id'] ?? 0);
            if ($pid <= 0) continue;
            $productNames[$pid] = $p['product_name'] ?? ('Product #' . $pid);
            $productMainCategory[$pid] = (int)($p['category_id'] ?? $p['menu_category_id'] ?? $p['main_category_id'] ?? 0);
            $productSubCategory[$pid] = (int)($p['sub_category_id'] ?? $p['menu_category_id2'] ?? $p['category2_id'] ?? 0);
        }
        $_SESSION['products_cache_ts'] = time();
        $_SESSION['products_cache_names'] = $productNames;
        $_SESSION['products_cache_main'] = $productMainCategory;
        $_SESSION['products_cache_sub'] = $productSubCategory;
    }

    $isAjax = (($_GET['ajax'] ?? '') === '1');
    $doResync = (($_GET['resync'] ?? '') === '1');
    if ($doResync && !$isAjax) {
        $metaTable = $db->t('system_meta');
        $pidVal = 0;
        $statusRow = $db->query(
            "SELECT meta_key, meta_value
             FROM {$metaTable}
             WHERE meta_key IN ('kitchen_resync_job_pid','kitchen_resync_job_status')"
        )->fetchAll();
        $meta = [];
        foreach ($statusRow as $r) $meta[(string)$r['meta_key']] = (string)$r['meta_value'];
        $existingPid = (int)($meta['kitchen_resync_job_pid'] ?? 0);
        $existingStatus = (string)($meta['kitchen_resync_job_status'] ?? '');
        $isRunning = false;
        if ($existingPid > 0 && $existingStatus === 'running') {
            if (function_exists('posix_kill')) {
                $isRunning = @posix_kill($existingPid, 0);
            } else {
                $isRunning = is_dir('/proc/' . $existingPid);
            }
        }
        if (!$isRunning) {
            $jobId = date('Ymd_His');
            $cmd = PHP_BINARY . ' ' . escapeshellarg(__DIR__ . '/scripts/kitchen/resync_range.php') . ' ' . escapeshellarg($dateFrom) . ' ' . escapeshellarg($dateTo) . ' ' . escapeshellarg($jobId);
            $logFile = __DIR__ . '/resync_range.log';
            $out = [];
            @exec($cmd . ' >> ' . escapeshellarg($logFile) . ' 2>&1 & echo $!', $out);
            $pidVal = (int)trim((string)end($out));
            if ($pidVal > 0) {
                $db->query(
                    "INSERT INTO {$metaTable} (meta_key, meta_value) VALUES (?, ?)
                     ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value), updated_at = CURRENT_TIMESTAMP",
                    ['kitchen_resync_job_pid', (string)$pidVal]
                );
            }
        }
        $redirectQuery = $_GET;
        unset($redirectQuery['resync']);
        $redirectQuery['resync_started'] = '1';
        $qs = http_build_query($redirectQuery);
        header('Location: rawdata.php' . ($qs ? ('?' . $qs) : ''));
        exit;
    }

    // Получаем данные из БД
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

    // Фильтр по дате
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

    // Фильтр по времени (часам)
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

    $groupedStats = [];

    if ($isAjax) {
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
            include __DIR__ . '/rawdata_receipts_chunk.php';
        }
        $html = ob_get_clean();

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => true,
            'html' => $html,
            'offset' => $offset,
            'limit' => $limit,
            'next_offset' => $offset + $limit,
            'total_receipts' => $totalReceipts,
            'has_more' => ($offset + $limit) < $totalReceipts
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

} catch (\Exception $e) {
    $error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/svg+xml" href="/links/favicon.svg">
    <title>Raw Data - Kitchen Analytics</title>
    <link rel="stylesheet" href="assets/app.css">
    <link rel="stylesheet" href="assets/datepicker-range-dialog.css">
      <?php include $_SERVER['DOCUMENT_ROOT'] . '/analytics.php'; ?>
  <link rel="stylesheet" href="/assets/css/common.css">
  <link rel="stylesheet" href="/assets/css/rawdata.css">
</head>
<body>
    <div class="container">
        <div class="top-nav">
            <div class="nav-left"><div class="nav-title">Таблица</div></div>
            <?php require __DIR__ . '/partials/user_menu.php'; ?>
        </div>
        <div class="last-sync">
            <span>Последнее обновление из Poster: <?= htmlspecialchars($lastSyncLabel) ?></span>
            <label class="resync-toggle">
                <input type="checkbox" name="resync" value="1" form="rawdataFilters"> Resync
            </label>
        </div>

        <form class="filter-section" method="GET" id="rawdataFilters">
            <div class="filter-group">
                <label>Период</label>
                <div class="dp-range" data-date-range-picker data-from-input="dateFromInput" data-to-input="dateToInput">
                    <div class="dp-field">
                        <input type="text" id="dateRangeBtn" class="dp-display range-btn" readonly>
                    </div>
                    <input type="hidden" name="dateFrom" id="dateFromInput" value="<?= htmlspecialchars($dateFrom) ?>">
                    <input type="hidden" name="dateTo" id="dateToInput" value="<?= htmlspecialchars($dateTo) ?>">
                    <div class="dp-overlay" data-dp-overlay hidden></div>
                    <div class="dp-dialog" data-dp-dialog role="dialog" aria-modal="true" aria-label="Выбор периода" hidden>
                        <div class="dp-header">
                            <button type="button" class="dp-nav dp-prev-month" aria-label="Предыдущий месяц">‹</button>
                            <div class="dp-month-year" aria-live="polite"></div>
                            <button type="button" class="dp-nav dp-next-month" aria-label="Следующий месяц">›</button>
                        </div>
                        <table class="dp-grid" role="grid" aria-label="Календарь">
                            <thead><tr></tr></thead>
                            <tbody></tbody>
                        </table>
                        <div class="dp-footer">
                            <div class="dp-hint" aria-live="polite"></div>
                            <div class="dp-actions">
                                <button type="button" class="dp-action dp-cancel" value="cancel">Отмена</button>
                                <button type="button" class="dp-action primary dp-ok" value="ok">OK</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="filter-group">
                <label>Время</label>
                <div style="display:flex; gap:10px;">
                    <select name="hourStart">
                        <?php for($h=0; $h<24; $h++): ?>
                            <option value="<?= $h ?>" <?= $hourStart == $h ? 'selected' : '' ?>><?= sprintf("%02d:00", $h) ?></option>
                        <?php endfor; ?>
                    </select>
                    <select name="hourEnd">
                        <?php for($h=1; $h<=24; $h++): ?>
                            <option value="<?= $h ?>" <?= $hourEnd == $h ? 'selected' : '' ?>><?= sprintf("%02d:00", $h) ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
            </div>

            <div class="filter-group">
                <label for="station">Цех:</label>
                <select name="station" id="station">
                    <option value="all" <?= $stationFilter === 'all' ? 'selected' : '' ?>>Все</option>
                    <option value="2" <?= $stationFilter === '2' ? 'selected' : '' ?>>Kitchen (2)</option>
                    <option value="3" <?= $stationFilter === '3' ? 'selected' : '' ?>>Bar (3)</option>
                </select>
            </div>

            <div class="filter-group">
                <label for="status">Статус:</label>
                <select name="status" id="status">
                    <option value="all" <?= $selectedStatus === 'all' ? 'selected' : '' ?>>Все чеки</option>
                    <option value="open" <?= $selectedStatus === 'open' ? 'selected' : '' ?>>Только открытые</option>
                    <option value="closed" <?= $selectedStatus === 'closed' ? 'selected' : '' ?>>Только закрытые</option>
                </select>
            </div>

            <div class="spacer"></div>
            <button type="submit">Применить</button>
            <?php if ($selectedStatus !== 'all' || $dateFrom !== date('Y-m-d') || $dateTo !== date('Y-m-d') || $hourStart !== 0 || $hourEnd !== 23 || $stationFilter !== 'all'): ?>
                <a href="rawdata.php" style="font-size: 0.9em; color: #666; margin-left: 10px;">Сбросить</a>
            <?php endif; ?>
        </form>
        
        <?php if (isset($error)): ?>
            <div class="error">Error: <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <div class="table-header-sticky" id="mainTableHeader">
            <div data-sort="receipt" class="sort-asc">Чек <span class="sort-icon"></span></div>
            <div data-sort="opened">ВрОткр <span class="sort-icon"></span></div>
            <div data-sort="closed">ВрЛогЗакр <span class="sort-icon"></span></div>
            <div data-sort="wait">Макс. ожидание <span class="sort-icon"></span></div>
        </div>

        <div id="lazyStatus" style="text-align:center; color:#65676b; height:0; margin:0; overflow:hidden;"></div>
        <div id="receiptsList"></div>
        <div id="lazySentinel" style="height:1px;"></div>
    </div>

    <script src="assets/app.js" defer></script>
    <script src="assets/user_menu.js" defer></script>
    <script src="assets/datepicker-range-dialog.js"></script>
    <script src="/assets/js/rawdata.js"></script>
</body>
</html>
