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
$lastSyncLabel = '—';
$closeReasonMap = [1 => 'Гость ушел', 2 => 'За счёт заведения', 3 => 'Ошибка официанта'];
$payTypeMap = [0 => 'Без оплаты', 1 => 'Наличные', 2 => 'Безнал', 3 => 'Смешанная'];
$dashboardQuery = http_build_query([
    'dateFrom' => $dateFrom,
    'dateTo' => $dateTo,
    'hourStart' => $hourStart,
    'hourEnd' => $hourEnd
]);

try {
    $db = new \App\Classes\Database($dbHost, $dbName, $dbUser, $dbPass);
    $api = new \App\Classes\PosterAPI($token);
    $columnExists = function (\App\Classes\Database $db, string $dbName, string $table, string $column): bool {
        $row = $db->query(
            "SELECT COUNT(*) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?",
            [$dbName, $table, $column]
        )->fetch();
        return (int)($row['c'] ?? 0) > 0;
    };
    if (!$columnExists($db, $dbName, 'kitchen_stats', 'exclude_from_dashboard')) {
        $db->query("ALTER TABLE kitchen_stats ADD COLUMN exclude_from_dashboard TINYINT(1) NOT NULL DEFAULT 0 AFTER close_reason");
    }
    if (!$columnExists($db, $dbName, 'kitchen_stats', 'exclude_auto')) {
        $db->query("ALTER TABLE kitchen_stats ADD COLUMN exclude_auto TINYINT(1) NOT NULL DEFAULT 0 AFTER exclude_from_dashboard");
    }
    if (!$columnExists($db, $dbName, 'kitchen_stats', 'ready_chass_at')) {
        $db->query("ALTER TABLE kitchen_stats ADD COLUMN ready_chass_at DATETIME NULL AFTER ready_pressed_at");
    }
    if (!$columnExists($db, $dbName, 'kitchen_stats', 'prob_close_at')) {
        $db->query("ALTER TABLE kitchen_stats ADD COLUMN prob_close_at DATETIME NULL AFTER ready_chass_at");
    }
    if (!$columnExists($db, $dbName, 'kitchen_stats', 'dish_category_id')) {
        $db->query("ALTER TABLE kitchen_stats ADD COLUMN dish_category_id BIGINT NULL AFTER dish_id");
    }
    if (!$columnExists($db, $dbName, 'kitchen_stats', 'dish_sub_category_id')) {
        $db->query("ALTER TABLE kitchen_stats ADD COLUMN dish_sub_category_id BIGINT NULL AFTER dish_category_id");
    }
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
            $db->query("UPDATE kitchen_stats SET exclude_from_dashboard = ?, exclude_auto = 0 WHERE id = ?", [$excludeFlag, $itemId]);
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
        $meta = $db->query("SELECT meta_value FROM system_meta WHERE meta_key = 'poster_last_sync_at' LIMIT 1")->fetch();
        if (!empty($meta['meta_value'])) {
            $lastSyncLabel = date('d.m.Y H:i:s', strtotime($meta['meta_value']));
        }
    } catch (\Exception $e) {
    }
    if ($lastSyncLabel === '—') {
        $fallback = $db->query("SELECT MAX(created_at) AS last_sync_at FROM kitchen_stats")->fetch();
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
        require_once __DIR__ . '/scripts/kitchen/resync_lib.php';
        veranda_resync_range_period($dateFrom, $dateTo);
        $redirectQuery = $_GET;
        unset($redirectQuery['resync']);
        $qs = http_build_query($redirectQuery);
        header('Location: rawdata.php' . ($qs ? ('?' . $qs) : ''));
        exit;
    }

    // Получаем данные из БД
    $query = "SELECT * FROM kitchen_stats";
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

    // Фильтр по времени (часам)
    if ($hourStart > 0 || $hourEnd < 23) {
        $where[] = "HOUR(transaction_opened_at) BETWEEN ? AND ?";
        $params[] = $hourStart;
        $params[] = $hourEnd;
    }

    if (!empty($where)) {
        $query .= " WHERE " . implode(" AND ", $where);
    }

    $countQuery = "SELECT COUNT(DISTINCT transaction_id) AS c FROM kitchen_stats" . (!empty($where) ? (" WHERE " . implode(" AND ", $where)) : "");
    $countRow = $db->query($countQuery, $params)->fetch();
    $totalReceipts = (int)($countRow['c'] ?? 0);

    $groupedStats = [];

    if ($isAjax) {
        $offset = max(0, (int)($_GET['offset'] ?? 0));
        $limit = (int)($_GET['limit'] ?? 20);
        if ($limit < 1) $limit = 20;
        if ($limit > 50) $limit = 50;

        $txSql = "SELECT transaction_id, receipt_number, MAX(ticket_sent_at) AS last_sent_at
                  FROM kitchen_stats" . (!empty($where) ? (" WHERE " . implode(" AND ", $where)) : "") . "
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
                "SELECT * FROM kitchen_stats WHERE transaction_id IN ({$placeholders}) ORDER BY ticket_sent_at DESC",
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
                        'status' => (int)$row['status'],
                        'pay_type' => isset($row['pay_type']) ? (int)$row['pay_type'] : null,
                        'close_reason' => isset($row['close_reason']) ? (int)$row['close_reason'] : null,
                        'exclude_from_dashboard' => isset($row['exclude_from_dashboard']) ? (int)$row['exclude_from_dashboard'] : 0,
                        'max_wait_time' => 0,
                        'max_wait_fallback' => false,
                        'max_wait_prob' => false,
                        'max_wait_pstr' => false,
                        'max_wait_log_close_at' => null,
                        'max_wait_log_close_timestamp' => 0,
                        'has_hookah' => false,
                        'opened_timestamp' => $row['transaction_opened_at'] ? strtotime($row['transaction_opened_at']) : 0,
                        'closed_timestamp' => $row['transaction_closed_at'] ? strtotime($row['transaction_closed_at']) : 0
                    ];
                }
                if (empty($groupedStats[$receipt]['closed_at']) && !empty($row['transaction_closed_at'])) {
                    $groupedStats[$receipt]['closed_at'] = $row['transaction_closed_at'];
                    $groupedStats[$receipt]['closed_timestamp'] = strtotime($row['transaction_closed_at']);
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
                    foreach ([
                        'pstr' => ($row['ready_pressed_at'] ?? null),
                        'chass' => ($row['ready_chass_at'] ?? null),
                        'prob' => ($row['prob_close_at'] ?? null),
                    ] as $src => $t) {
                        if (empty($t)) continue;
                        $ts = strtotime($t);
                        if ($ts === false || $ts <= 0) continue;
                        if ($ts < $sentTs) continue;
                        $logicalCloseAt = $t;
                        $logicalCloseTs = $ts;
                        $logicalSource = $src;
                        break;
                    }
                    if ($logicalCloseAt === null) {
                        $closedAt = $row['transaction_closed_at'] ?? null;
                        if (
                            !empty($closedAt) &&
                            $closedAt !== '0000-00-00 00:00:00' &&
                            (int)date('Y', strtotime($closedAt)) > 1970 &&
                            (int)($row['status'] ?? 1) > 1
                        ) {
                            $ts = strtotime($closedAt);
                            if ($ts !== false && $ts >= $sentTs) {
                                $logicalCloseAt = $closedAt;
                                $logicalCloseTs = $ts;
                                $logicalSource = 'close';
                            }
                        }
                    }
                    if ($logicalCloseAt !== null) {
                        $wait = ($logicalCloseTs - $sentTs) / 60;
                        if ($wait > $groupedStats[$receipt]['max_wait_time']) {
                            $groupedStats[$receipt]['max_wait_time'] = round($wait, 1);
                            $groupedStats[$receipt]['max_wait_fallback'] = ($logicalSource === 'close');
                            $groupedStats[$receipt]['max_wait_prob'] = ($logicalSource === 'prob');
                            $groupedStats[$receipt]['max_wait_pstr'] = ($logicalSource === 'pstr');
                            $groupedStats[$receipt]['max_wait_log_close_at'] = $logicalCloseAt;
                            $groupedStats[$receipt]['max_wait_log_close_timestamp'] = $logicalCloseTs;
                        }
                    }
                }
            }
        }

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
    <link rel="icon" type="image/png" href="favicon.png">
    <title>Raw Data - Kitchen Analytics</title>
    <link rel="stylesheet" href="assets/datepicker-range-dialog.css">
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f0f2f5; padding: 20px; color: #1c1e21; }
        .container { max-width: 1000px; margin: 0 auto; }
        h1 { margin-bottom: 30px; text-align: center; color: #1a73e8; }
        
        details { background: white; margin-bottom: 12px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); overflow: hidden; border: 1px solid #ddd; }
        summary { padding: 16px; cursor: pointer; font-weight: 600; display: flex; justify-content: space-between; align-items: center; outline: none; }
        summary:hover { background: #f8f9fa; }
        summary::-webkit-details-marker { display: none; }
        summary::after { content: "▼"; font-size: 12px; color: #777; transition: transform 0.2s; flex-shrink: 0; }
        details[open] summary::after { transform: rotate(180deg); }
        
        .receipt-info { display: flex; gap: 20px; align-items: center; flex-grow: 1; }
        .receipt-number { font-size: 1.1em; color: #1a73e8; min-width: 100px; }
        .receipt-date { font-size: 0.9em; color: #65676b; font-weight: normal; min-width: 150px; }
        .receipt-times { font-size: 0.85em; color: #666; display: flex; gap: 15px; flex-grow: 1; }
        .receipt-item.receipt-closed summary { border-left: 6px solid #3c763d; }
        .receipt-item.receipt-closed .receipt-number { color: #3c763d; }
        .receipt-item.receipt-closed summary::after { color: #3c763d; }
        .exclude-toggle { display: inline-flex; align-items: center; gap: 6px; font-size: 0.85em; color: #444; background: #fff8e1; border: 1px solid #ffe082; border-radius: 6px; padding: 4px 8px; }
        .receipt-max-wait { font-size: 0.95em; padding: 4px 12px; border-radius: 6px; white-space: nowrap; font-weight: 700; border: 1px solid #ddd; }
        .wait-low { background: #ffffff; color: #333; }
        .wait-medium { background: #fff3e0; color: #f57c00; border-color: #ffe0b2; }
        .wait-high { background: #d32f2f; color: #ffffff; border-color: #b71c1c; }
        .wait-fallback { background: #eceff1; color: #607d8b; border-color: #cfd8dc; }
        
        .table-header-sticky { background: #eef1f4; padding: 12px 16px; border-radius: 8px 8px 0 0; border: 1px solid #ddd; border-bottom: none; display: flex; font-weight: 600; font-size: 0.8em; color: #65676b; text-transform: uppercase; margin-top: 20px; cursor: pointer; user-select: none; }
        .table-header-sticky div { flex: 1; display: flex; align-items: center; gap: 5px; }
        .sort-icon::after { content: "↕"; opacity: 0.3; }
        .sort-asc .sort-icon::after { content: "↑"; opacity: 1; }
        .sort-desc .sort-icon::after { content: "↓"; opacity: 1; }

        .table-container { padding: 0 16px 16px 16px; border-top: 1px solid #eee; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 0.95em; }
        th, td { padding: 12px 8px; text-align: left; border-bottom: 1px solid #eee; }
        th { color: #65676b; font-weight: 600; text-transform: uppercase; font-size: 0.8em; }
        
        .status-ready { color: #2e7d32; background: #e8f5e9; padding: 4px 8px; border-radius: 4px; font-size: 0.85em; }
        .status-cooking { color: #f57c00; background: #fff3e0; padding: 4px 8px; border-radius: 4px; font-size: 0.85em; }
        .status-deleted { color: #757575; background: #f5f5f5; padding: 4px 8px; border-radius: 4px; font-size: 0.85em; text-decoration: line-through; }
        .wait-spinner { display: inline-block; width: 10px; height: 10px; border: 2px solid rgba(245, 124, 0, 0.3); border-top-color: #f57c00; border-radius: 50%; margin-right: 6px; animation: waitSpin 0.9s linear infinite; vertical-align: -1px; }
        @keyframes waitSpin { to { transform: rotate(360deg); } }
        .status-fallback { color: #607d8b; background: #eceff1; padding: 4px 8px; border-radius: 4px; font-size: 0.85em; }
        .wait-time { font-weight: 500; color: #d32f2f; }
        .wait-time-fallback { color: #78909c; }
        .wait-time-hookah { color: #607d8b; font-weight: 700; }
        .save-indicator { margin-left: 8px; font-size: 0.8em; color: #2e7d32; opacity: 0; transition: opacity 0.2s; }
        .save-indicator.show { opacity: 1; }
        
        .filter-section { background: white; padding: 14px 16px; border-radius: 10px; margin-bottom: 16px; border: 1px solid #e5e7eb; display: flex; align-items: center; gap: 16px; flex-wrap: nowrap; }
        .filter-group { display: flex; flex-direction: column; gap: 6px; }
        .filter-group label { font-size: 0.85em; color: #65676b; font-weight: 600; text-transform: uppercase; }
        .filter-section select, .filter-section input { padding: 8px 12px; border-radius: 4px; border: 1px solid #ccc; font-size: 1em; min-width: 150px; }
        .filter-section input.range-btn { border-radius: 6px; border: 1px solid #d0d5dd; background: #fff; min-width: 190px; cursor: pointer; }
        .range-btn { padding: 8px 12px; border-radius: 6px; border: 1px solid #d0d5dd; background: #fff; min-width: 190px; text-align: left; cursor: pointer; }
        .range-hint { display: none; }
        .filter-section button[type="submit"] { padding: 9px 18px; background: #1a73e8; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; height: 36px; }
        .filter-section button[type="submit"]:hover { background: #1557b0; }
        .filter-section .spacer { flex: 1; }
        
        .top-nav { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; gap: 16px; }
        .nav-left { display: flex; gap: 14px; flex-wrap: wrap; align-items: center; }
        .nav-left a { color: #1a73e8; text-decoration: none; font-weight: 500; }
        .nav-left a:hover { text-decoration: underline; }
        .user-menu { position: relative; }
        .user-chip { display: inline-flex; align-items: center; gap: 8px; padding: 6px 10px; border: 1px solid #e5e7eb; border-radius: 999px; background: #fff; color: #37474f; font-weight: 600; cursor: default; }
        .user-icon { width: 22px; height: 22px; border-radius: 50%; background: #e3f2fd; display: inline-flex; align-items: center; justify-content: center; color: #1a73e8; font-weight: 800; font-size: 12px; overflow: hidden; }
        .user-icon img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .user-dropdown { position: absolute; right: 0; top: calc(100% + 8px); background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; box-shadow: 0 8px 18px rgba(0,0,0,0.12); padding: 8px; min-width: 160px; display: none; z-index: 1000; }
        .user-menu.open .user-dropdown { display: block; }
        .user-dropdown a { display: block; padding: 8px 10px; border-radius: 8px; color: #37474f; text-decoration: none; font-weight: 600; }
        .user-dropdown a:hover { background: #f5f6fa; }
        
        .error { color: #d32f2f; background: #fdecea; padding: 15px; border-radius: 8px; border: 1px solid #f5c2c7; margin-bottom: 20px; }
        .last-sync { text-align: center; color: #546e7a; font-size: 0.95em; margin: -18px 0 20px; display: flex; justify-content: center; align-items: center; gap: 14px; flex-wrap: wrap; }
        .resync-toggle { display: inline-flex; align-items: center; gap: 6px; font-size: 0.9em; color: #546e7a; }
    </style>
</head>
<body>
    <div class="container">
        <div class="top-nav">
            <div class="nav-left"></div>
            <div class="user-menu">
                <?php
                    $userLabel = (string)($_SESSION['user_name'] ?? $_SESSION['user_email'] ?? '');
                    $initial = mb_strtoupper(mb_substr($userLabel !== '' ? $userLabel : 'U', 0, 1));
                    $avatar = (string)($_SESSION['user_avatar'] ?? '');
                ?>
                <div class="user-chip">
                    <span class="user-icon"><?php if ($avatar !== ''): ?><img src="<?= htmlspecialchars($avatar) ?>" alt=""><?php else: ?><?= htmlspecialchars($initial) ?><?php endif; ?></span>
                    <span><?= htmlspecialchars($userLabel) ?></span>
                </div>
                <div class="user-dropdown">
                    <?php if (veranda_can('dashboard')): ?><a href="dashboard.php?<?= htmlspecialchars($dashboardQuery) ?>">Дашюорд</a><?php endif; ?>
                    <?php if (veranda_can('rawdata')): ?><a href="rawdata.php">Таблица</a><?php endif; ?>
                    <?php if (veranda_can('kitchen_online')): ?><a href="kitchen_online.php">КухняОнлайн</a><?php endif; ?>
                    <?php if (veranda_can('admin')): ?><a href="admin.php">Управление</a><?php endif; ?>
                    <a href="logout.php">Выход</a>
                </div>
            </div>
        </div>
        <h1>Raw Data: Kitchen Transactions</h1>
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
                <label for="status">Статус:</label>
                <select name="status" id="status">
                    <option value="all" <?= $selectedStatus === 'all' ? 'selected' : '' ?>>Все чеки</option>
                    <option value="open" <?= $selectedStatus === 'open' ? 'selected' : '' ?>>Только открытые</option>
                    <option value="closed" <?= $selectedStatus === 'closed' ? 'selected' : '' ?>>Только закрытые</option>
                </select>
            </div>

            <div class="spacer"></div>
            <button type="submit">Применить</button>
            <?php if ($selectedStatus !== 'all' || $dateFrom !== date('Y-m-d') || $dateTo !== date('Y-m-d')): ?>
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

    <script src="assets/datepicker-range-dialog.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const header = document.getElementById('mainTableHeader');
            const list = document.getElementById('receiptsList');
            const statusEl = document.getElementById('lazyStatus');
            const sentinel = document.getElementById('lazySentinel');
            
            let currentSort = { field: 'receipt', order: 'asc' };
            let userSorted = false;
            const state = { offset: 0, limit: 20, loading: false, done: false, total: null };

            const baseParams = new URLSearchParams(window.location.search);
            baseParams.delete('ajax');
            baseParams.delete('offset');
            baseParams.delete('limit');

            const applySort = () => {
                const items = Array.from(list.getElementsByClassName('receipt-item'));
                if (items.length === 0) return;

                const { field, order } = currentSort;
                const sortedItems = items.sort((a, b) => {
                    let valA, valB;
                    switch (field) {
                        case 'receipt':
                            valA = a.dataset.receipt || '';
                            valB = b.dataset.receipt || '';
                            return order === 'asc'
                                ? valA.localeCompare(valB, undefined, { numeric: true })
                                : valB.localeCompare(valA, undefined, { numeric: true });
                        case 'opened':
                            valA = parseInt(a.dataset.opened || '0', 10);
                            valB = parseInt(b.dataset.opened || '0', 10);
                            break;
                        case 'closed':
                            valA = parseInt(a.dataset.closed || '0', 10);
                            valB = parseInt(b.dataset.closed || '0', 10);
                            break;
                        case 'wait':
                            valA = parseFloat(a.dataset.wait || '0');
                            valB = parseFloat(b.dataset.wait || '0');
                            break;
                    }
                    return order === 'asc' ? (valA - valB) : (valB - valA);
                });

                sortedItems.forEach(item => list.appendChild(item));
            };

            const updateLiveCooking = () => {
                const els = Array.from(list.getElementsByClassName('live-wait'));
                if (els.length === 0) return;
                const nowSec = Math.floor(Date.now() / 1000);
                for (const el of els) {
                    const sentTs = parseInt(el.dataset.sentTs || '0', 10);
                    if (!sentTs) continue;
                    const diffSec = Math.max(0, nowSec - sentTs);
                    const mm = Math.floor(diffSec / 60);
                    const ss = diffSec % 60;
                    const out = String(mm).padStart(2, '0') + ':' + String(ss).padStart(2, '0');
                    const t = el.querySelector('.live-time');
                    if (t) t.textContent = out;
                }
            };

            const updateStatus = () => {
                if (!statusEl) return;
                if (state.loading) {
                    statusEl.textContent = 'Загрузка…';
                    statusEl.style.height = '18px';
                    statusEl.style.margin = '16px 0';
                    return;
                }
                if (state.total !== null && list.children.length === 0 && state.done) {
                    statusEl.textContent = 'Нет данных';
                    statusEl.style.height = '18px';
                    statusEl.style.margin = '16px 0';
                    return;
                }
                statusEl.textContent = '';
                statusEl.style.height = '0';
                statusEl.style.margin = '0';
            };

            const loadNext = async () => {
                if (state.loading || state.done) return;
                state.loading = true;
                updateStatus();
                try {
                    const params = new URLSearchParams(baseParams);
                    params.set('ajax', '1');
                    params.set('offset', String(state.offset));
                    params.set('limit', String(state.limit));

                    const res = await fetch(`rawdata.php?${params.toString()}`, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                    if (!res.ok) throw new Error('load failed');
                    const data = await res.json();
                    if (!data || !data.ok) throw new Error('bad response');

                    state.total = typeof data.total_receipts === 'number' ? data.total_receipts : state.total;
                    if (data.html) {
                        const tmp = document.createElement('div');
                        tmp.innerHTML = data.html;
                        while (tmp.firstChild) list.appendChild(tmp.firstChild);
                        if (userSorted) applySort();
                        updateLiveCooking();
                    }
                    state.offset = typeof data.next_offset === 'number' ? data.next_offset : (state.offset + state.limit);
                    state.done = !data.has_more;
                } catch (e) {
                    state.done = true;
                    if (statusEl) {
                        statusEl.textContent = 'Ошибка загрузки';
                        statusEl.style.display = 'block';
                    }
                } finally {
                    state.loading = false;
                    updateStatus();
                }
            };

            header.addEventListener('click', (e) => {
                const target = e.target.closest('div[data-sort]');
                if (!target) return;

                const field = target.dataset.sort;
                const order = (currentSort.field === field && currentSort.order === 'asc') ? 'desc' : 'asc';

                // Update UI
                header.querySelectorAll('div').forEach(div => {
                    div.classList.remove('sort-asc', 'sort-desc');
                });
                target.classList.add(`sort-${order}`);

                currentSort = { field, order };
                userSorted = true;
                applySort();
            });

            if (sentinel) {
                const io = new IntersectionObserver((entries) => {
                    if (entries.some(e => e.isIntersecting)) loadNext();
                }, { rootMargin: '600px 0px 600px 0px' });
                io.observe(sentinel);
            }
            loadNext();
            setInterval(updateLiveCooking, 1000);

            const resync = document.querySelector('input[name="resync"][type="checkbox"]');
            if (resync) {
                resync.checked = false;
                resync.addEventListener('change', () => {
                    if (resync.checked) {
                        const ok = confirm('Resync делает полную пересинхронизацию данных из Poster за выбранный период и может сильно нагрузить систему. Используй редко. Продолжить?');
                        if (!ok) resync.checked = false;
                    }
                });
            }
            const form = document.getElementById('rawdataFilters');
            if (form && resync) {
                form.addEventListener('submit', (e) => {
                    if (resync.checked) {
                        const ok = confirm('Подтвердить Resync? Это может занять время и нагрузить систему.');
                        if (!ok) e.preventDefault();
                    }
                });
            }

            list.addEventListener('change', async (e) => {
                const checkbox = e.target.closest('input[name="exclude_from_dashboard"]');
                if (!checkbox) return;
                const form = checkbox.closest('form.exclude-item-form');
                if (!form) return;

                const payload = new FormData(form);
                if (!checkbox.checked) {
                    payload.delete('exclude_from_dashboard');
                }
                const indicator = form.querySelector('.save-indicator');

                checkbox.disabled = true;
                try {
                    const response = await fetch('rawdata.php', {
                        method: 'POST',
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                        body: payload
                    });
                    if (!response.ok) {
                        checkbox.checked = !checkbox.checked;
                    } else if (indicator) {
                        indicator.classList.add('show');
                        indicator.textContent = 'сохранено';
                        setTimeout(() => indicator.classList.remove('show'), 1200);
                    }
                } catch (err) {
                    checkbox.checked = !checkbox.checked;
                    if (indicator) {
                        indicator.classList.add('show');
                        indicator.textContent = 'ошибка';
                        setTimeout(() => {
                            indicator.textContent = 'сохранено';
                            indicator.classList.remove('show');
                        }, 1500);
                    }
                } finally {
                    checkbox.disabled = false;
                }
            });
        });

        (() => {
            const menu = document.querySelector('.user-menu');
            if (!menu) return;
            let t = null;
            const open = () => {
                if (t) { clearTimeout(t); t = null; }
                menu.classList.add('open');
            };
            const scheduleClose = () => {
                if (t) clearTimeout(t);
                t = setTimeout(() => {
                    menu.classList.remove('open');
                    t = null;
                }, 1000);
            };
            menu.addEventListener('mouseenter', open);
            menu.addEventListener('mouseleave', scheduleClose);
        })();
    </script>
</body>
</html>
