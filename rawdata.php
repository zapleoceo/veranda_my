<?php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/src/classes/PosterAPI.php';

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
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_exclude_item'])) {
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
    
    // Получаем названия продуктов из API
    $productsRaw = $api->request('menu.getProducts');
    $productNames = [];
    foreach ($productsRaw as $p) {
        $productNames[$p['product_id']] = $p['product_name'];
    }

    // Получаем все данные из БД
    $query = "SELECT * FROM kitchen_stats";
    $where = [];
    $params = [];

    if ($selectedStatus === 'closed') {
        $where[] = "status = 2";
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

    $query .= " ORDER BY ticket_sent_at DESC";
    $allStats = $db->query($query, $params)->fetchAll();
    
    // Группируем по чеку
    $groupedStats = [];
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
        
        // Считаем макс время ожидания
        $readyTimeForWait = null;
        $fallbackWait = false;
        $probWait = false;
        if ($row['ticket_sent_at'] && ($row['ready_pressed_at'] || !empty($row['ready_chass_at']) || !empty($row['prob_close_at']))) {
            $candidates = [];
            if ($row['ready_pressed_at']) $candidates[] = $row['ready_pressed_at'];
            if (!empty($row['ready_chass_at'])) $candidates[] = $row['ready_chass_at'];
            if (!empty($row['prob_close_at'])) $candidates[] = $row['prob_close_at'];
            usort($candidates, fn($a, $b) => strtotime($a) <=> strtotime($b));
            $readyTimeForWait = $candidates[0] ?? null;
            $probWait = !empty($row['prob_close_at']) && $readyTimeForWait === $row['prob_close_at'] && empty($row['ready_pressed_at']) && empty($row['ready_chass_at']);
        } elseif (
            $row['ticket_sent_at'] &&
            $row['transaction_closed_at'] &&
            $row['transaction_closed_at'] !== '0000-00-00 00:00:00' &&
            date('Y', strtotime($row['transaction_closed_at'])) > 1970 &&
            (int)$row['status'] > 1
        ) {
            $readyTimeForWait = $row['transaction_closed_at'];
            $fallbackWait = true;
        }
        if ($readyTimeForWait) {
            $wait = (strtotime($readyTimeForWait) - strtotime($row['ticket_sent_at'])) / 60;
            if ($wait > $groupedStats[$receipt]['max_wait_time']) {
                $groupedStats[$receipt]['max_wait_time'] = round($wait, 1);
                $groupedStats[$receipt]['max_wait_fallback'] = $fallbackWait;
                $groupedStats[$receipt]['max_wait_prob'] = $probWait;
            }
        }
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
        .status-fallback { color: #607d8b; background: #eceff1; padding: 4px 8px; border-radius: 4px; font-size: 0.85em; }
        .wait-time { font-weight: 500; color: #d32f2f; }
        .wait-time-fallback { color: #78909c; }
        .save-indicator { margin-left: 8px; font-size: 0.8em; color: #2e7d32; opacity: 0; transition: opacity 0.2s; }
        .save-indicator.show { opacity: 1; }
        
        .filter-section { background: white; padding: 14px 16px; border-radius: 10px; margin-bottom: 16px; border: 1px solid #e5e7eb; display: flex; align-items: center; gap: 16px; flex-wrap: nowrap; }
        .filter-group { display: flex; flex-direction: column; gap: 6px; }
        .filter-group label { font-size: 0.85em; color: #65676b; font-weight: 600; text-transform: uppercase; }
        .filter-section select, .filter-section input { padding: 8px 12px; border-radius: 4px; border: 1px solid #ccc; font-size: 1em; min-width: 150px; }
        .filter-section input.range-btn { border-radius: 6px; border: 1px solid #d0d5dd; background: #fff; min-width: 190px; cursor: pointer; }
        .range-btn { padding: 8px 12px; border-radius: 6px; border: 1px solid #d0d5dd; background: #fff; min-width: 190px; text-align: left; cursor: pointer; }
        .range-hint { display: none; }
        .filter-section button { padding: 9px 18px; background: #1a73e8; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; height: 36px; }
        .filter-section button:hover { background: #1557b0; }
        .filter-section .spacer { flex: 1; }
        
        .nav-links { text-align: center; margin-bottom: 20px; }
        .nav-links a { color: #1a73e8; text-decoration: none; margin: 0 10px; font-weight: 500; }
        .nav-links a:hover { text-decoration: underline; }
        
        .error { color: #d32f2f; background: #fdecea; padding: 15px; border-radius: 8px; border: 1px solid #f5c2c7; margin-bottom: 20px; }
        .last-sync { text-align: center; color: #546e7a; font-size: 0.95em; margin: -18px 0 20px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="nav-links">
            <a href="dashboard.php?<?= htmlspecialchars($dashboardQuery) ?>">Дашборд</a>
            <a href="rawdata.php">Сырые данные</a>
            <a href="admin.php">УПРАВЛЕНИЕ</a>
            <a href="logout.php">Выйти (<?= htmlspecialchars($_SESSION['user_email']) ?>)</a>
        </div>
        <h1>Raw Data: Kitchen Transactions</h1>
        <div class="last-sync">Последнее обновление из Poster: <?= htmlspecialchars($lastSyncLabel) ?></div>

        <form class="filter-section" method="GET">
            <div class="filter-group">
                <label>Период</label>
                <div class="dp-range" data-date-range-picker data-from-input="dateFromInput" data-to-input="dateToInput">
                    <div class="dp-field">
                        <input type="text" id="dateRangeBtn" class="dp-display range-btn" readonly>
                        <button type="button" class="dp-open" aria-label="Выбрать период">📅</button>
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

        <?php if (empty($groupedStats) && !isset($error)): ?>
            <p style="text-align:center;">No data found in database.</p>
        <?php endif; ?>

        <div class="table-header-sticky" id="mainTableHeader">
            <div data-sort="receipt" class="sort-asc">Чек <span class="sort-icon"></span></div>
            <div data-sort="opened">Открыт <span class="sort-icon"></span></div>
            <div data-sort="closed">ЗакрытPoster <span class="sort-icon"></span></div>
            <div data-sort="wait">Макс. ожидание <span class="sort-icon"></span></div>
        </div>

        <div id="receiptsList">
        <?php foreach ($groupedStats as $receiptNum => $data): ?>
            <?php $receiptClass = ((int)($data['status'] ?? 1) > 1) ? 'receipt-item receipt-closed' : 'receipt-item'; ?>
            <details class="<?= $receiptClass ?>" 
                     data-receipt="<?= htmlspecialchars($receiptNum) ?>" 
                     data-opened="<?= $data['opened_timestamp'] ?>" 
                     data-closed="<?= $data['closed_timestamp'] ?>" 
                     data-wait="<?= $data['max_wait_time'] ?>">
                <summary>
                    <div class="receipt-info">
                        <span class="receipt-number">Чек <?= htmlspecialchars($receiptNum) ?></span>
                        <div class="receipt-times">
                            <span>Открыт: <?= ($data['opened_at'] && $data['opened_at'] !== '0000-00-00 00:00:00' && date('Y', strtotime($data['opened_at'])) > 1970) ? date('H:i:s', strtotime($data['opened_at'])) : '—' ?></span>
                            <span>Закрыт: <?php
                                if ((int)$data['status'] > 1 && $data['closed_at'] && $data['closed_at'] !== '0000-00-00 00:00:00' && date('Y', strtotime($data['closed_at'])) > 1970) {
                                    echo date('H:i:s', strtotime($data['closed_at']));
                                } elseif ((int)$data['status'] > 1) {
                                    if ($data['close_reason'] !== null && isset($closeReasonMap[$data['close_reason']])) {
                                        echo 'Закрыт без оплаты: ' . $closeReasonMap[$data['close_reason']];
                                    } elseif ($data['pay_type'] !== null && isset($payTypeMap[$data['pay_type']])) {
                                        echo 'Закрыт: ' . $payTypeMap[$data['pay_type']];
                                    } else {
                                        echo 'Закрыт (время не передано API)';
                                    }
                                } else {
                                    echo '—';
                                }
                            ?></span>
                        </div>
                        <?php if ($data['max_wait_time'] > 0): 
                            $waitClass = 'wait-low';
                            if (!empty($data['max_wait_fallback'])) {
                                $waitClass = 'wait-fallback';
                            } elseif ($data['max_wait_time'] >= 40) {
                                $waitClass = 'wait-high';
                            } elseif ($data['max_wait_time'] >= 20) {
                                $waitClass = 'wait-medium';
                            }
                            $waitIcon = !empty($data['max_wait_prob']) ? '❓' : (!empty($data['max_wait_fallback']) ? '📌' : '⌛');
                        ?>
                            <span class="receipt-max-wait <?= $waitClass ?>" title="<?= !empty($data['max_wait_prob']) ? 'Макс. ожидание рассчитано от отправки на станцию до расчетного времени (ProbCloseTime: берется из следующего чека(ов) по цеху).' : (!empty($data['max_wait_fallback']) ? 'Макс. ожидание рассчитано от отправки на станцию до времени закрытия чека (fallback).' : 'Макс. ожидание рассчитано от отправки на станцию до отметки Готово.') ?>"><?= $waitIcon ?> <?= $data['max_wait_time'] ?> мин</span>
                        <?php endif; ?>
                    </div>
                </summary>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th title="Название позиции (из Poster).">Блюдо</th>
                                <th title="Время открытия чека (Poster date_start).">Открыт</th>
                                <th title="Время отправки позиции на станцию/цех (Poster TransactionHistory sendtokitchen).">Отправ</th>
                                <th title="Время готовности: Poster (finishedcooking) → Chef Assistant (readyTime) → ProbCloseTime.">Готово</th>
                                <th title="Время закрытия чека в Poster (date_close/date_close_date), только для закрытых чеков.">ЗакPoster</th>
                                <th title="Время готовности из Chef Assistant (readyTime).">ЗакChAss</th>
                                <th title="Расчетное время (ProbCloseTime): берется из ближайшего следующего чека (+1..+3) с тем же цехом, где есть готовые позиции.">ЗакРассч</th>
                                <th title="Отправ → (Готово/ЗакChAss/ЗакРассч). Если ничего нет и чек закрыт: Отправ → ЗакPoster (📌).">Время ожидания</th>
                                <th title="Исключить позицию из дашборда и алертов.">Не учитывать</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($data['items'] as $item): 
                                $wait = '—';
                                $waitClass = 'wait-time';
                                $usedFallbackTime = false;
                                $usedInProgressTime = false;
                                $usedProbCloseTime = false;
                                if ($item['ticket_sent_at'] && ($item['ready_pressed_at'] || !empty($item['ready_chass_at']) || !empty($item['prob_close_at']))) {
                                    $candidates = [];
                                    if ($item['ready_pressed_at']) $candidates[] = $item['ready_pressed_at'];
                                    if (!empty($item['ready_chass_at'])) $candidates[] = $item['ready_chass_at'];
                                    if (!empty($item['prob_close_at'])) $candidates[] = $item['prob_close_at'];
                                    usort($candidates, fn($a, $b) => strtotime($a) <=> strtotime($b));
                                    $endTime = $candidates[0] ?? null;
                                    if ($endTime) {
                                        $diff = strtotime($endTime) - strtotime($item['ticket_sent_at']);
                                        $usedProbCloseTime = !empty($item['prob_close_at']) && $endTime === $item['prob_close_at'] && empty($item['ready_pressed_at']) && empty($item['ready_chass_at']);
                                        $icon = $usedProbCloseTime ? '❓' : '⌛';
                                        $wait = $icon . ' ' . round($diff / 60, 1) . ' мин';
                                    }
                                } elseif (
                                    $item['ticket_sent_at'] &&
                                    $item['transaction_closed_at'] &&
                                    $item['transaction_closed_at'] !== '0000-00-00 00:00:00' &&
                                    date('Y', strtotime($item['transaction_closed_at'])) > 1970 &&
                                    (int)$item['status'] > 1
                                ) {
                                    $diff = strtotime($item['transaction_closed_at']) - strtotime($item['ticket_sent_at']);
                                    $wait = '📌 ' . round($diff / 60, 1) . ' мин';
                                    $waitClass = 'wait-time wait-time-fallback';
                                    $usedFallbackTime = true;
                                } elseif (
                                    $item['ticket_sent_at'] &&
                                    empty($item['ready_pressed_at']) &&
                                    (int)$item['status'] === 1
                                ) {
                                    $diff = time() - strtotime($item['ticket_sent_at']);
                                    if ($diff >= 0) {
                                        $wait = round($diff / 60, 1) . ' мин…';
                                        $waitClass = 'wait-time wait-time-fallback';
                                        $usedInProgressTime = true;
                                    }
                                }
                                $dishName = $productNames[$item['dish_id']] ?? $item['dish_name'];
                                
                                $opened = ($item['transaction_opened_at'] && $item['transaction_opened_at'] !== '0000-00-00 00:00:00' && date('Y', strtotime($item['transaction_opened_at'])) > 1970) ? date('H:i:s', strtotime($item['transaction_opened_at'])) : '—';
                                if ((int)$item['status'] > 1 && $item['transaction_closed_at'] && $item['transaction_closed_at'] !== '0000-00-00 00:00:00' && date('Y', strtotime($item['transaction_closed_at'])) > 1970) {
                                    $closed = date('H:i:s', strtotime($item['transaction_closed_at']));
                                } elseif ((int)$item['status'] > 1) {
                                    $reason = isset($item['close_reason']) && $item['close_reason'] !== '' ? (int)$item['close_reason'] : null;
                                    $payType = isset($item['pay_type']) && $item['pay_type'] !== '' ? (int)$item['pay_type'] : null;
                                    if ($reason !== null && isset($closeReasonMap[$reason])) {
                                        $closed = 'Закрыт без оплаты: ' . $closeReasonMap[$reason];
                                    } elseif ($payType !== null && isset($payTypeMap[$payType])) {
                                        $closed = 'Закрыт: ' . $payTypeMap[$payType];
                                    } else {
                                        $closed = 'Закрыт (время не передано API)';
                                    }
                                } else {
                                    $closed = '—';
                                }
                            ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($dishName) ?></strong>
                                        <div style="font-size: 0.8em; color: #999;">ID: <?= $item['dish_id'] ?></div>
                                    </td>
                                    <td><?= $opened ?></td>
                                    <td><?= $item['ticket_sent_at'] ? date('H:i:s', strtotime($item['ticket_sent_at'])) : '—' ?></td>
                                    <td>
                                        <?php if (!empty($item['was_deleted'])): ?>
                                            <span class="status-deleted">Удалено</span>
                                        <?php elseif ($item['ready_pressed_at']): ?>
                                            <span class="status-ready" title="Время взято из события Готово на станции кухни/бара."><?= date('H:i:s', strtotime($item['ready_pressed_at'])) ?></span>
                                        <?php elseif (!empty($item['ready_chass_at'])): ?>
                                            <span class="status-ready" title="Время взято из Chef Assistant."><?= date('H:i:s', strtotime($item['ready_chass_at'])) ?></span>
                                        <?php elseif (!empty($item['prob_close_at'])): ?>
                                            <span class="status-fallback" title="Время вычислено по следующим чекам (ProbCloseTime)."><?= date('H:i:s', strtotime($item['prob_close_at'])) ?></span>
                                        <?php elseif ($usedFallbackTime): ?>
                                            <span class="status-fallback" title="Отметки Готово нет. Время взято из закрытия чека как fallback."><?= date('H:i:s', strtotime($item['transaction_closed_at'])) ?></span>
                                        <?php else: ?>
                                            <span class="status-cooking">В процессе</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= $closed ?></td>
                                    <td><?= !empty($item['ready_chass_at']) ? date('H:i:s', strtotime($item['ready_chass_at'])) : '—' ?></td>
                                    <td><?= !empty($item['prob_close_at']) ? date('H:i:s', strtotime($item['prob_close_at'])) : '—' ?></td>
                                    <td class="<?= $waitClass ?>" title="<?= $usedFallbackTime ? '📌 Расчет: ЗакPoster - Отправ.' : ($usedInProgressTime ? 'Расчет: текущее время - Отправ.' : ($usedProbCloseTime ? '❓ Расчет: ЗакРассч (ProbCloseTime) - Отправ. ЗакРассч берется из следующего чека(+1..+3) по тому же цеху.' : '⌛ Расчет: (Готово/ЗакChAss) - Отправ.')) ?>"><?= $wait ?></td>
                                    <td>
                                        <form method="POST" class="exclude-item-form">
                                            <input type="hidden" name="toggle_exclude_item" value="<?= (int)$item['id'] ?>">
                                            <input type="hidden" name="return_query" value="<?= htmlspecialchars(http_build_query($_GET), ENT_QUOTES) ?>">
                                            <label class="exclude-toggle">
                                                <input type="checkbox" name="exclude_from_dashboard" value="1" <?= (!empty($item['exclude_from_dashboard']) || !empty($item['was_deleted'])) ? 'checked' : '' ?>>
                                                не учитывать
                                            </label>
                                            <span class="save-indicator">сохранено</span>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </details>
        <?php endforeach; ?>
        </div>
    </div>

    <script src="assets/datepicker-range-dialog.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const header = document.getElementById('mainTableHeader');
            const list = document.getElementById('receiptsList');
            const items = Array.from(list.getElementsByClassName('receipt-item'));
            
            let currentSort = { field: 'receipt', order: 'asc' };

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

                // Sort items
                const sortedItems = items.sort((a, b) => {
                    let valA, valB;
                    
                    switch(field) {
                        case 'receipt':
                            valA = a.dataset.receipt;
                            valB = b.dataset.receipt;
                            return order === 'asc' 
                                ? valA.localeCompare(valB, undefined, {numeric: true})
                                : valB.localeCompare(valA, undefined, {numeric: true});
                        case 'opened':
                            valA = parseInt(a.dataset.opened);
                            valB = parseInt(b.dataset.opened);
                            break;
                        case 'closed':
                            valA = parseInt(a.dataset.closed);
                            valB = parseInt(b.dataset.closed);
                            break;
                        case 'wait':
                            valA = parseFloat(a.dataset.wait);
                            valB = parseFloat(b.dataset.wait);
                            break;
                    }

                    if (order === 'asc') return valA - valB;
                    return valB - valA;
                });

                // Re-append items
                sortedItems.forEach(item => list.appendChild(item));
                currentSort = { field, order };
            });

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
    </script>
</body>
</html>
