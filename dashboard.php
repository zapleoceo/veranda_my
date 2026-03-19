<?php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/src/classes/PosterAPI.php';

// Фильтры
$dateFrom = $_GET['dateFrom'] ?? date('Y-m-d');
$dateTo = $_GET['dateTo'] ?? date('Y-m-d');
$hourStart = (int)($_GET['hourStart'] ?? 10);
$hourEnd = (int)($_GET['hourEnd'] ?? 24);
$doResync = isset($_GET['resync']) && $_GET['resync'] === '1';
$lastSyncLabel = '—';
if ($hourEnd <= $hourStart) {
    $hourEnd = min(24, $hourStart + 1);
}
$rawParams = [
    'dateFrom' => $dateFrom,
    'dateTo' => $dateTo,
    'hourStart' => $hourStart,
    'hourEnd' => $hourEnd
];
if ($doResync) {
    require_once __DIR__ . '/scripts/kitchen/resync_lib.php';
    veranda_resync_range_period($dateFrom, $dateTo);
    $redirectParams = $rawParams;
    $qs = http_build_query($redirectParams);
    header('Location: dashboard.php' . ($qs ? ('?' . $qs) : ''));
    exit;
}
$rawDataQuery = http_build_query([
    'dateFrom' => $dateFrom,
    'dateTo' => $dateTo,
    'hourStart' => $hourStart,
    'hourEnd' => $hourEnd
]);
$dashboardQuery = http_build_query($rawParams);

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
              AND COALESCE(was_deleted, 0) = 0
              AND transaction_date BETWEEN ? AND ?";
    $params = [$dateFrom, $dateTo];
    $query .= " ORDER BY ticket_sent_at ASC";
    $stats = $db->query($query, $params)->fetchAll();
    
    if (empty($stats)) {
        $error = "Нет данных для построения дашборда за выбранный период.";
    } else {
        $hours = [];
        $slotDates = [];
        $slotHours = [];
        $dateRangeSingleDay = $dateFrom === $dateTo;

        if ($dateRangeSingleDay) {
            for ($h = $hourStart; $h < $hourEnd; $h++) {
                $hours[] = sprintf("%02d:00", $h);
                $slotDates[] = $dateFrom;
                $slotHours[] = $h;
            }
        } else {
            $dt = new DateTime($dateFrom);
            $dtEnd = new DateTime($dateTo);
            $dtEnd->setTime(0, 0, 0);
            $dt->setTime(0, 0, 0);

            while ($dt <= $dtEnd) {
                $dIso = $dt->format('Y-m-d');
                $dLabel = $dt->format('d.m');
                for ($h = $hourStart; $h < $hourEnd; $h++) {
                    $hours[] = $dLabel . ' ' . sprintf("%02d:00", $h);
                    $slotDates[] = $dIso;
                    $slotHours[] = $h;
                }
                $dt->modify('+1 day');
            }
        }

        // Подготавливаем данные для графиков (ID 2 = Kitchen, ID 3 = Bar)
        $slotCount = count($hours);
        $chartData = [
            '2' => ['label' => 'KITCHEN', 'avg' => array_fill(0, $slotCount, 0), 'max' => array_fill(0, $slotCount, 0), 'counts' => array_fill(0, $slotCount, 0)],
            '3' => ['label' => 'BAR VERANDA', 'avg' => array_fill(0, $slotCount, 0), 'max' => array_fill(0, $slotCount, 0), 'counts' => array_fill(0, $slotCount, 0)]
        ];

        $slotIndex = [];
        for ($i = 0; $i < $slotCount; $i++) {
            $d = $slotDates[$i] ?? null;
            $h = $slotHours[$i] ?? null;
            if ($d === null || $h === null) continue;
            if (!isset($slotIndex[$d])) $slotIndex[$d] = [];
            $slotIndex[$d][(int)$h] = $i;
        }

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

            $openedAt = $row['transaction_opened_at'] ?? null;
            if (empty($openedAt)) continue;
            $openedTs = strtotime($openedAt);
            if ($openedTs === false || $openedTs <= 0) continue;
            $hour = (int)date('H', $openedTs);
            if ($hour < $hourStart || $hour >= $hourEnd) continue;

            $dIso = date('Y-m-d', $openedTs);
            if (!isset($slotIndex[$dIso][$hour])) continue;
            $hourIdx = (int)$slotIndex[$dIso][$hour];

            if (empty($row['ticket_sent_at'])) continue;
            $sentTs = strtotime($row['ticket_sent_at']);
            if ($sentTs === false || $sentTs <= 0) continue;

            $endTime = null;
            foreach ([$row['ready_pressed_at'] ?? null, $row['ready_chass_at'] ?? null, $row['prob_close_at'] ?? null] as $t) {
                if (empty($t)) continue;
                $ts = strtotime($t);
                if ($ts === false || $ts <= 0) continue;
                if ($ts < $sentTs) continue;
                $endTime = $t;
                break;
            }
            if ($endTime === null) {
                $closedAt = $row['transaction_closed_at'] ?? null;
                if (
                    !empty($closedAt) &&
                    $closedAt !== '0000-00-00 00:00:00' &&
                    (int)date('Y', strtotime($closedAt)) > 1970 &&
                    (int)$row['status'] > 1
                ) {
                    $ts = strtotime($closedAt);
                    if ($ts !== false && $ts >= $sentTs) {
                        $endTime = $closedAt;
                    }
                }
            }
            if ($endTime === null) continue;

            $waitTime = (strtotime($endTime) - $sentTs) / 60;
            
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
        .filters button[type="submit"] { padding: 10px 25px; background: #1a73e8; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; }
        .filters button[type="submit"]:hover { background: #1557b0; }
        .last-sync { text-align: center; color: #546e7a; font-size: 0.95em; margin: -25px 0 20px; display: flex; justify-content: center; align-items: center; gap: 14px; flex-wrap: wrap; }
        .resync-toggle { display: inline-flex; align-items: center; gap: 6px; font-size: 0.9em; color: #546e7a; }
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
        <div class="last-sync">
            <span>Последнее обновление из Poster: <?= htmlspecialchars($lastSyncLabel) ?></span>
            <label class="resync-toggle">
                <input type="checkbox" name="resync" value="1" form="dashboardFilters"> Resync
            </label>
        </div>

        <form class="filters" method="GET" id="dashboardFilters">
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
    document.addEventListener('DOMContentLoaded', () => {
        const form = document.querySelector('form.filters');
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
        if (form && resync) {
            form.addEventListener('submit', (e) => {
                if (resync.checked) {
                    const ok = confirm('Подтвердить Resync? Это может занять время и нагрузить систему.');
                    if (!ok) e.preventDefault();
                }
            });
        }
    });
    <?php if (!isset($error)): ?>
    const labels = <?= json_encode($hours) ?>;
    const slotDates = <?= json_encode($slotDates) ?>;
    const slotHours = <?= json_encode($slotHours) ?>;
    const dateFrom = <?= json_encode($dateFrom) ?>;
    const dateTo = <?= json_encode($dateTo) ?>;
    
    const options = {
        responsive: true,
        onClick: (event, elements, chart) => {
            if (elements.length > 0) {
                const index = elements[0].index;
                const day = slotDates[index] || dateFrom;
                const hourInt = slotHours[index] != null ? parseInt(slotHours[index], 10) : parseInt(String(labels[index] || '').split(':')[0], 10);
                const stationId = chart.canvas.id === 'chartKitchen' ? 2 : 3;
                
                // Переход на rawdata.php с фильтрами
                const url = `rawdata.php?dateFrom=${day}&dateTo=${day}&hourStart=${hourInt}&hourEnd=${hourInt}&station=${stationId}`;
                window.location.href = url;
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                title: { display: true, text: 'Минуты ожидания' }
            },
            x: {
                title: { display: true, text: 'Дата и время' },
                ticks: {
                    maxRotation: 0,
                    autoSkip: true,
                    maxTicksLimit: 18
                }
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
