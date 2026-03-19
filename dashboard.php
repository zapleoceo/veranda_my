<?php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/src/classes/PosterAPI.php';

// Фильтры
$dateFrom = $_GET['dateFrom'] ?? date('Y-m-d');
$dateTo = $_GET['dateTo'] ?? date('Y-m-d');
$hourStart = (int)($_GET['hourStart'] ?? 10);
$hourEnd = (int)($_GET['hourEnd'] ?? 24);
$excludeZero = isset($_GET['excludeZero']) ? (bool)$_GET['excludeZero'] : false;
$excludeCloseBased = isset($_GET['excludeCloseBased']) ? (bool)$_GET['excludeCloseBased'] : true;
$lastSyncLabel = '—';
if ($hourEnd <= $hourStart) {
    $hourEnd = min(24, $hourStart + 1);
}
$rawDataQuery = http_build_query([
    'dateFrom' => $dateFrom,
    'dateTo' => $dateTo,
    'hourStart' => $hourStart,
    'hourEnd' => $hourEnd
]);
$dashboardQuery = http_build_query([
    'dateFrom' => $dateFrom,
    'dateTo' => $dateTo,
    'hourStart' => $hourStart,
    'hourEnd' => $hourEnd,
    'excludeZero' => $excludeZero ? 1 : 0,
    'excludeCloseBased' => $excludeCloseBased ? 1 : 0
]);

try {
    $db = new \App\Classes\Database($dbHost, $dbName, $dbUser, $dbPass);
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
    if (!$columnExists($db, $dbName, 'kitchen_stats', 'ready_chass_at')) {
        $db->query("ALTER TABLE kitchen_stats ADD COLUMN ready_chass_at DATETIME NULL AFTER ready_pressed_at");
    }
    if (!$columnExists($db, $dbName, 'kitchen_stats', 'prob_close_at')) {
        $db->query("ALTER TABLE kitchen_stats ADD COLUMN prob_close_at DATETIME NULL AFTER ready_chass_at");
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
    
    // Получаем данные за период
    $query = "SELECT * FROM kitchen_stats 
              WHERE COALESCE(exclude_from_dashboard, 0) = 0
              AND transaction_date BETWEEN ? AND ?";
    $params = [$dateFrom, $dateTo];
    
    if ($excludeZero) {
        $query .= " AND total_sum > 0";
    }
    
    $query .= " ORDER BY ticket_sent_at ASC";
    $stats = $db->query($query, $params)->fetchAll();
    
    if (empty($stats)) {
        $error = "Нет данных для построения дашборда за выбранный период.";
    } else {
        // Генерируем интервалы
        $hours = [];
        for ($h = $hourStart; $h < $hourEnd; $h++) {
            $hours[] = sprintf("%02d:00", $h);
        }

        // Подготавливаем данные для графиков (ID 2 = Kitchen, ID 3 = Bar)
        $chartData = [
            '2' => ['label' => 'KITCHEN', 'avg' => array_fill(0, count($hours), 0), 'max' => array_fill(0, count($hours), 0), 'counts' => array_fill(0, count($hours), 0)],
            '3' => ['label' => 'BAR VERANDA', 'avg' => array_fill(0, count($hours), 0), 'max' => array_fill(0, count($hours), 0), 'counts' => array_fill(0, count($hours), 0)]
        ];

        foreach ($stats as $row) {
            $stationName = $row['station'];
            $targetStationId = null;

            // Сопоставляем названия станций с ID для графиков
            if ($stationName === '2' || $stationName === 2 || $stationName === 'Kitchen' || $stationName === 'Main') { // 'Main' для обратной совместимости
                $targetStationId = '2';
            } elseif ($stationName === '3' || $stationName === 3 || $stationName === 'Bar Veranda') {
                $targetStationId = '3';
            }

            if ($targetStationId === null || !isset($chartData[$targetStationId])) {
                continue;
            }

            $hour = (int)date('H', strtotime($row['transaction_opened_at']));
            
            // Проверяем, попадает ли в выбранный диапазон часов
            if ($hour < $hourStart || $hour >= $hourEnd) continue;
            
            $hourIdx = $hour - $hourStart;
            
            if ($hourIdx < 0 || $hourIdx >= count($hours)) continue;

            $endTime = null;
            $candidates = [];
            if (!empty($row['ready_pressed_at'])) $candidates[] = $row['ready_pressed_at'];
            if (!empty($row['ready_chass_at'])) $candidates[] = $row['ready_chass_at'];
            if (!empty($row['prob_close_at'])) $candidates[] = $row['prob_close_at'];
            if (!empty($candidates)) {
                usort($candidates, fn($a, $b) => strtotime($a) <=> strtotime($b));
                $endTime = $candidates[0];
            } elseif (
                !$excludeCloseBased &&
                !empty($row['transaction_closed_at']) &&
                $row['transaction_closed_at'] !== '0000-00-00 00:00:00' &&
                (int)date('Y', strtotime($row['transaction_closed_at'])) > 1970 &&
                (int)$row['status'] > 1
            ) {
                $endTime = $row['transaction_closed_at'];
            } else {
                continue;
            }
            if (empty($row['ticket_sent_at'])) {
                continue;
            }
            $waitTime = (strtotime($endTime) - strtotime($row['ticket_sent_at'])) / 60;
            if ($waitTime < 0) {
                continue;
            }
            
            $chartData[$targetStationId]['avg'][$hourIdx] += $waitTime;
            $chartData[$targetStationId]['counts'][$hourIdx]++;
            
            if ($waitTime > $chartData[$targetStationId]['max'][$hourIdx]) {
                $chartData[$targetStationId]['max'][$hourIdx] = $waitTime;
            }
        }

        // Вычисляем среднее
        foreach (['2', '3'] as $sid) {
            foreach ($hours as $idx => $h) {
                if ($chartData[$sid]['counts'][$idx] > 0) {
                    $chartData[$sid]['avg'][$idx] = round($chartData[$sid]['avg'][$idx] / $chartData[$sid]['counts'][$idx], 1);
                    $chartData[$sid]['max'][$idx] = round($chartData[$sid]['max'][$idx], 1);
                }
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
    <title>Dashboard - Kitchen Analytics</title>
    <link rel="stylesheet" href="assets/datepicker-range-dialog.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f4f7f6; padding: 20px; color: #333; }
        .container { max-width: 1200px; margin: 0 auto; }
        h1 { text-align: center; color: #1a73e8; margin-bottom: 40px; }
        .chart-container { background: white; padding: 25px; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); margin-bottom: 30px; border: 1px solid #e0e0e0; }
        h2 { margin-top: 0; color: #444; font-size: 1.2em; border-bottom: 2px solid #f0f0f0; padding-bottom: 10px; margin-bottom: 20px; }
        .error { color: #d32f2f; background: #fdecea; padding: 15px; border-radius: 8px; border: 1px solid #f5c2c7; text-align: center; }
        .nav-links { text-align: center; margin-bottom: 20px; }
        .nav-links a { color: #1a73e8; text-decoration: none; margin: 0 10px; font-weight: 500; }
        .nav-links a:hover { text-decoration: underline; }
        
        .filters { background: white; padding: 15px; border-radius: 12px; margin-bottom: 30px; border: 1px solid #e0e0e0; display: flex; flex-wrap: wrap; gap: 20px; align-items: flex-end; }
        .filter-group { display: flex; flex-direction: column; gap: 8px; justify-content: flex-end; }
        .filter-group label { font-size: 0.85em; font-weight: 600; color: #666; text-transform: uppercase; }
        .filters input, .filters select { padding: 8px 12px; border: 1px solid #ddd; border-radius: 6px; }
        .filters input.range-btn { min-width: 220px; cursor: pointer; background: #fff; }
        .range-btn { padding: 8px 12px; border: 1px solid #ddd; border-radius: 6px; background: #fff; min-width: 220px; text-align: left; cursor: pointer; }
        .range-hint { font-size: 0.75em; color: #777; min-height: 16px; margin-top: 4px; }
        .filters button { padding: 10px 25px; background: #1a73e8; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; }
        .filters button:hover { background: #1557b0; }
        
        .settings-btn { background: #6c757d; color: white; border: none; padding: 10px 15px; border-radius: 6px; cursor: pointer; display: flex; align-items: center; gap: 8px; font-weight: 500; }
        .settings-btn:hover { background: #5a6268; }
        .settings-panel { display: none; background: white; border: 1px solid #ddd; border-radius: 8px; padding: 15px; position: absolute; top: 100%; right: 0; z-index: 100; box-shadow: 0 4px 12px rgba(0,0,0,0.15); min-width: 250px; margin-top: 10px; }
        .settings-panel.active { display: block; }
        .settings-panel label { display: flex; align-items: center; gap: 10px; cursor: pointer; font-size: 0.9em; color: #444; }
        .settings-container { position: relative; }
        .last-sync { text-align: center; color: #546e7a; font-size: 0.95em; margin: -25px 0 20px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="nav-links">
            <a href="dashboard.php?<?= htmlspecialchars($dashboardQuery) ?>">Дашборд</a>
            <a href="rawdata.php?<?= htmlspecialchars($rawDataQuery) ?>">Сырые данные</a>
            <a href="admin.php">УПРАВЛЕНИЕ</a>
            <a href="logout.php">Выйти (<?= htmlspecialchars($_SESSION['user_email']) ?>)</a>
        </div>
        <h1>Kitchen Service Dashboard</h1>
        <div class="last-sync">Последнее обновление из Poster: <?= htmlspecialchars($lastSyncLabel) ?></div>

        <form class="filters" method="GET">
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
            
            <div class="settings-container">
                <button type="button" class="settings-btn" onclick="document.getElementById('settingsPanel').classList.toggle('active')">
                    ⚙️ Параметры
                </button>
                <div class="settings-panel" id="settingsPanel">
                    <label>
                        <input type="hidden" name="excludeZero" value="0">
                        <input type="checkbox" name="excludeZero" value="1" <?= $excludeZero ? 'checked' : '' ?>>
                        Исключить нулевые чеки
                    </label>
                    <label>
                        <input type="hidden" name="excludeCloseBased" value="0">
                        <input type="checkbox" name="excludeCloseBased" value="1" <?= $excludeCloseBased ? 'checked' : '' ?>>
                        Исключить время по закрытию чека
                    </label>
                </div>
            </div>

            <button type="submit">Обновить</button>
        </form>

        <?php if (isset($error)): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php else: ?>
            <div class="chart-container">
                <h2>СТАНЦИЯ: KITCHEN</h2>
                <canvas id="chartKitchen"></canvas>
            </div>

            <div class="chart-container">
                <h2>СТАНЦИЯ: BAR VERANDA</h2>
                <canvas id="chartBar"></canvas>
            </div>
        <?php endif; ?>
    </div>

    <script src="assets/datepicker-range-dialog.js"></script>
    <script>
    <?php if (!isset($error)): ?>
    const labels = <?= json_encode($hours) ?>;
    const dateFrom = <?= json_encode($dateFrom) ?>;
    const dateTo = <?= json_encode($dateTo) ?>;
    
    const options = {
        responsive: true,
        onClick: (event, elements, chart) => {
            if (elements.length > 0) {
                const index = elements[0].index;
                const hour = labels[index];
                const hourInt = parseInt(hour.split(':')[0], 10);
                const stationId = chart.canvas.id === 'chartKitchen' ? 2 : 3;
                
                // Переход на rawdata.php с фильтрами
                const url = `rawdata.php?dateFrom=${dateFrom}&dateTo=${dateTo}&hourStart=${hourInt}&hourEnd=${hourInt}&station=${stationId}`;
                window.location.href = url;
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                title: { display: true, text: 'Минуты ожидания' }
            },
            x: {
                title: { display: true, text: 'Время суток' }
            }
        },
        plugins: {
            tooltip: {
                callbacks: {
                    label: function(context) {
                        return context.dataset.label + ': ' + context.parsed.y + ' мин';
                    }
                }
            }
        }
    };

    // Chart Kitchen
    new Chart(document.getElementById('chartKitchen'), {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Среднее время (мин)',
                    data: <?= json_encode($chartData['2']['avg']) ?>,
                    backgroundColor: 'rgba(26, 115, 232, 0.6)',
                    borderColor: 'rgb(26, 115, 232)',
                    borderWidth: 1
                },
                {
                    label: 'Макс. время (мин)',
                    data: <?= json_encode($chartData['2']['max']) ?>,
                    backgroundColor: 'rgba(211, 47, 47, 0.4)',
                    borderColor: 'rgb(211, 47, 47)',
                    borderWidth: 1
                }
            ]
        },
        options: options
    });

    // Chart Bar
    new Chart(document.getElementById('chartBar'), {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Среднее время (мин)',
                    data: <?= json_encode($chartData['3']['avg']) ?>,
                    backgroundColor: 'rgba(46, 125, 50, 0.6)',
                    borderColor: 'rgb(46, 125, 50)',
                    borderWidth: 1
                },
                {
                    label: 'Макс. время (мин)',
                    data: <?= json_encode($chartData['3']['max']) ?>,
                    backgroundColor: 'rgba(211, 47, 47, 0.4)',
                    borderColor: 'rgb(211, 47, 47)',
                    borderWidth: 1
                }
            ]
        },
        options: options
    });
    <?php endif; ?>
    </script>
</body>
</html>
