<?php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/src/classes/PosterAPI.php';
veranda_require('dashboard');

// Фильтры
$dateFrom = $_GET['dateFrom'] ?? date('Y-m-d');
$dateTo = $_GET['dateTo'] ?? date('Y-m-d');
$hourStart = (int)($_GET['hourStart'] ?? 0);
$hourEnd = (int)($_GET['hourEnd'] ?? 23);
$doResync = isset($_GET['resync']) && $_GET['resync'] === '1';
$lastSyncLabel = '—';
if ($hourStart < 0) $hourStart = 0;
if ($hourStart > 23) $hourStart = 23;
if ($hourEnd === 24) $hourEnd = 23;
if ($hourEnd < 0) $hourEnd = 0;
if ($hourEnd > 23) $hourEnd = 23;
if ($hourEnd < $hourStart) $hourEnd = $hourStart;
$rawParams = [
    'dateFrom' => $dateFrom,
    'dateTo' => $dateTo,
    'hourStart' => $hourStart,
    'hourEnd' => $hourEnd
];
if ($doResync) {
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
    $redirectParams = $rawParams;
    $redirectParams['resync_started'] = '1';
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
    $ks = $db->t('kitchen_stats');
    $metaTable = $db->t('system_meta');
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
    
    $hours = [];
    $slotDates = [];
    $slotHours = [];
    $dateRangeSingleDay = $dateFrom === $dateTo;

    if ($dateRangeSingleDay) {
        for ($h = $hourStart; $h <= $hourEnd; $h++) {
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
            for ($h = $hourStart; $h <= $hourEnd; $h++) {
                $hours[] = $dLabel . ' ' . sprintf("%02d:00", $h);
                $slotDates[] = $dIso;
                $slotHours[] = $h;
            }
            $dt->modify('+1 day');
        }
    }

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

    $rows = $db->query(
        "SELECT sid, d_iso, h_int,
                ROUND(AVG(wait_min), 1) AS avg_wait,
                ROUND(MAX(wait_min), 1) AS max_wait,
                COUNT(*) AS cnt
         FROM (
              SELECT
                CASE
                  WHEN station = '2' OR station = 2 OR station = 'Kitchen' OR station = 'Main' THEN '2'
                  WHEN station = '3' OR station = 3 OR station = 'Bar Veranda' THEN '3'
                  ELSE NULL
                END AS sid,
                DATE(transaction_opened_at) AS d_iso,
                HOUR(transaction_opened_at) AS h_int,
                (TIMESTAMPDIFF(SECOND, ticket_sent_at,
                    CASE
                      WHEN ready_pressed_at IS NOT NULL THEN ready_pressed_at
                      WHEN prob_close_at IS NOT NULL
                       AND status > 1
                       AND transaction_closed_at IS NOT NULL
                       AND transaction_closed_at <> '0000-00-00 00:00:00'
                        THEN CASE WHEN prob_close_at < transaction_closed_at THEN prob_close_at ELSE transaction_closed_at END
                      WHEN prob_close_at IS NOT NULL THEN prob_close_at
                      WHEN status > 1 AND transaction_closed_at IS NOT NULL AND transaction_closed_at <> '0000-00-00 00:00:00' THEN transaction_closed_at
                      ELSE NULL
                    END
                ) / 60) AS wait_min
              FROM {$ks}
              WHERE transaction_date BETWEEN ? AND ?
                AND COALESCE(exclude_from_dashboard, 0) = 0
                AND COALESCE(was_deleted, 0) = 0
                AND ticket_sent_at IS NOT NULL
                AND transaction_opened_at IS NOT NULL
                AND HOUR(transaction_opened_at) BETWEEN ? AND ?
                AND NOT (COALESCE(dish_category_id, 0) = 47 OR COALESCE(dish_sub_category_id, 0) = 47)
                AND (
                    ready_pressed_at IS NOT NULL
                 OR prob_close_at IS NOT NULL
                 OR (status > 1 AND transaction_closed_at IS NOT NULL AND transaction_closed_at <> '0000-00-00 00:00:00')
                )
         ) x
         WHERE sid IS NOT NULL AND wait_min IS NOT NULL AND wait_min >= 0
         GROUP BY sid, d_iso, h_int",
        [$dateFrom, $dateTo, $hourStart, $hourEnd]
    )->fetchAll();

    if (empty($rows)) {
        $error = "Нет данных для построения дашборда за выбранный период.";
    } else {
        foreach ($rows as $r) {
            $sid = (string)($r['sid'] ?? '');
            $dIso = (string)($r['d_iso'] ?? '');
            $hInt = (int)($r['h_int'] ?? -1);
            if ($sid === '' || $dIso === '' || $hInt < 0) continue;
            if (!isset($slotIndex[$dIso][$hInt])) continue;
            $idx = (int)$slotIndex[$dIso][$hInt];
            if (!isset($chartData[$sid])) continue;
            $chartData[$sid]['avg'][$idx] = (float)($r['avg_wait'] ?? 0);
            $chartData[$sid]['max'][$idx] = (float)($r['max_wait'] ?? 0);
            $chartData[$sid]['counts'][$idx] = (int)($r['cnt'] ?? 0);
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
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/svg+xml" href="/links/favicon.svg">
    <title>Dashboard - Kitchen Analytics</title>
    <link rel="stylesheet" href="assets/app.css">
    <link rel="stylesheet" href="assets/datepicker-range-dialog.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f4f7f6; padding: 0; color: #333; }
        .container { width: 100%; max-width: 1800px; margin: 0 auto; padding: 12px; box-sizing: border-box; }
        h1 { text-align: center; color: #1a73e8; margin-bottom: 40px; }
        .charts-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; align-items: stretch; }
        @media (max-width: 980px) { .charts-grid { grid-template-columns: 1fr; } }
        .chart-container { background: white; padding: 14px; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); margin-bottom: 0; border: 1px solid #e0e0e0; }
        h2 { margin: 0 0 10px; color: #444; font-size: 1.05em; border-bottom: 2px solid #f0f0f0; padding-bottom: 8px; }
        .chart-canvas { width: 100% !important; height: 280px !important; }
        .error { color: #d32f2f; background: #fdecea; padding: 15px; border-radius: 8px; border: 1px solid #f5c2c7; text-align: center; }
        .top-nav { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; gap: 16px; flex-wrap: wrap; }
        .nav-left { display: flex; gap: 14px; flex-wrap: wrap; align-items: center; min-width: 0; }
        .nav-left a { color: #1a73e8; text-decoration: none; font-weight: 500; }
        .nav-left a:hover { text-decoration: underline; }
        .nav-title { font-weight: 800; color: #2c3e50; }
        .nav-mid { display: flex; justify-content: center; align-items: center; gap: 14px; flex-wrap: wrap; color: #546e7a; font-size: 0.95em; flex: 1 1 360px; min-width: 260px; }
        .user-menu { position: relative; }
        .user-chip { display: inline-flex; align-items: center; gap: 8px; padding: 6px 10px; border: 1px solid #e0e0e0; border-radius: 999px; background: #fff; color: #37474f; font-weight: 600; cursor: default; }
        .user-icon { width: 22px; height: 22px; border-radius: 50%; background: #e3f2fd; display: inline-flex; align-items: center; justify-content: center; color: #1a73e8; font-weight: 800; font-size: 12px; overflow: hidden; }
        .user-icon img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .user-dropdown { position: absolute; right: 0; top: calc(100% + 8px); background: #fff; border: 1px solid #e0e0e0; border-radius: 10px; box-shadow: 0 8px 18px rgba(0,0,0,0.12); padding: 8px; min-width: 160px; display: none; z-index: 1000; }
        .user-menu.open .user-dropdown { display: block; }
        .user-dropdown a { display: block; padding: 8px 10px; border-radius: 8px; color: #37474f; text-decoration: none; font-weight: 600; }
        .user-dropdown a:hover { background: #f4f7f6; }
        
        .filters { background: white; padding: 15px; border-radius: 12px; margin-bottom: 30px; border: 1px solid #e0e0e0; display: flex; flex-wrap: wrap; gap: 20px; align-items: flex-end; }
        .filter-group { display: flex; flex-direction: column; gap: 8px; justify-content: flex-end; }
        .filter-group label { font-size: 0.85em; font-weight: 600; color: #666; text-transform: uppercase; }
        .filters input, .filters select { padding: 8px 12px; border: 1px solid #ddd; border-radius: 6px; }
        .filters input.range-btn { min-width: 220px; cursor: pointer; background: #fff; }
        .range-btn { padding: 8px 12px; border: 1px solid #ddd; border-radius: 6px; background: #fff; min-width: 220px; text-align: left; cursor: pointer; }
        .range-hint { font-size: 0.75em; color: #777; min-height: 16px; margin-top: 4px; }
        .filters button[type="submit"] { padding: 10px 25px; background: #1a73e8; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; }
        .filters button[type="submit"]:hover { background: #1557b0; }
        .resync-toggle { display: inline-flex; align-items: center; gap: 6px; font-size: 0.9em; color: #546e7a; }
        .chart-type-switch { display: inline-flex; border: 1px solid #d0d5dd; border-radius: 999px; overflow: hidden; background: #fff; }
        .chart-type-btn { appearance: none; border: 0; background: transparent; padding: 8px 12px; font-weight: 700; color: #546e7a; cursor: pointer; }
        .chart-type-btn[aria-pressed="true"] { background: rgba(26,115,232,0.12); color: #1a73e8; }
        .chart-type-btn:focus { outline: 2px solid rgba(26,115,232,0.35); outline-offset: 2px; }
    </style>
  <?php include $_SERVER['DOCUMENT_ROOT'] . '/analytics.php'; ?>
</head>
<body>
    <div class="container">
        <div class="top-nav">
            <div class="nav-left"><div class="nav-title">Дашборд</div></div>
            <div class="nav-mid">
                <span>Последнее обновление из Poster: <?= htmlspecialchars($lastSyncLabel) ?></span>
                <label class="resync-toggle">
                    <input type="checkbox" name="resync" value="1" form="dashboardFilters"> Resync
                </label>
            </div>
            <?php require __DIR__ . '/partials/user_menu.php'; ?>
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
                        <?php for($h=0; $h<24; $h++): ?>
                            <option value="<?= $h ?>" <?= $hourEnd == $h ? 'selected' : '' ?>><?= sprintf("%02d:59", $h) ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
            </div>

            <div class="filter-group">
                <label>График</label>
                <div class="chart-type-switch" id="chartTypeSwitch" role="group" aria-label="Тип графика">
                    <button type="button" class="chart-type-btn" data-type="bar" aria-pressed="false">Столбики</button>
                    <button type="button" class="chart-type-btn" data-type="line" aria-pressed="false">Линия</button>
                </div>
            </div>
            
            <button type="submit">Обновить</button>
        </form>

        <?php if (isset($error)): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php else: ?>
            <div class="charts-grid">
                <div class="chart-container">
                    <h2>СТАНЦИЯ: KITCHEN</h2>
                    <canvas id="chartKitchen" class="chart-canvas"></canvas>
                </div>

                <div class="chart-container">
                    <h2>СТАНЦИЯ: BAR VERANDA</h2>
                    <canvas id="chartBar" class="chart-canvas"></canvas>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script src="assets/app.js" defer></script>
    <script src="assets/user_menu.js" defer></script>
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
    
    const canRawData = <?= json_encode(veranda_can('rawdata')) ?>;
    const options = {
        responsive: true,
        maintainAspectRatio: false,
        onClick: (event, elements, chart) => {
            if (elements.length > 0) {
                if (!canRawData) return;
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

    const chartTypeSwitch = document.getElementById('chartTypeSwitch');
    const storageKey = 'dashboard_chart_type';
    const getType = () => {
        const t = (localStorage.getItem(storageKey) || '').trim();
        if (t === 'line' || t === 'bar') return t;
        return 'bar';
    };
    const setType = (t) => {
        if (t !== 'line' && t !== 'bar') return;
        localStorage.setItem(storageKey, t);
    };

    let kitchenChart = null;
    let barChart = null;

    const setSwitchState = (type) => {
        if (!chartTypeSwitch) return;
        const btns = Array.from(chartTypeSwitch.querySelectorAll('button.chart-type-btn'));
        btns.forEach((b) => {
            const t = (b.getAttribute('data-type') || '').trim();
            b.setAttribute('aria-pressed', t === type ? 'true' : 'false');
        });
    };

    const buildDataset = (type, label, data, colors) => {
        const base = {
            label,
            data,
            borderWidth: 2,
        };
        if (type === 'line') {
            return Object.assign(base, {
                backgroundColor: colors.lineFill,
                borderColor: colors.lineStroke,
                pointRadius: 2,
                tension: 0.25,
                fill: false,
            });
        }
        return Object.assign(base, {
            backgroundColor: colors.barFill,
            borderColor: colors.barStroke,
            borderWidth: 1,
        });
    };

    const renderCharts = (type) => {
        const kitchenEl = document.getElementById('chartKitchen');
        const barEl = document.getElementById('chartBar');
        if (!kitchenEl || !barEl) return;

        if (kitchenChart) { kitchenChart.destroy(); kitchenChart = null; }
        if (barChart) { barChart.destroy(); barChart = null; }

        kitchenChart = new Chart(kitchenEl, {
            type,
            data: {
                labels,
                datasets: [
                    buildDataset(type, 'Среднее время (мин)', <?= json_encode($chartData['2']['avg']) ?>, {
                        barFill: 'rgba(26, 115, 232, 0.6)',
                        barStroke: 'rgb(26, 115, 232)',
                        lineFill: 'rgba(26, 115, 232, 0)',
                        lineStroke: 'rgb(26, 115, 232)',
                    }),
                    buildDataset(type, 'Макс. время (мин)', <?= json_encode($chartData['2']['max']) ?>, {
                        barFill: 'rgba(211, 47, 47, 0.4)',
                        barStroke: 'rgb(211, 47, 47)',
                        lineFill: 'rgba(211, 47, 47, 0)',
                        lineStroke: 'rgb(211, 47, 47)',
                    }),
                ],
            },
            options,
        });

        barChart = new Chart(barEl, {
            type,
            data: {
                labels,
                datasets: [
                    buildDataset(type, 'Среднее время (мин)', <?= json_encode($chartData['3']['avg']) ?>, {
                        barFill: 'rgba(46, 125, 50, 0.6)',
                        barStroke: 'rgb(46, 125, 50)',
                        lineFill: 'rgba(46, 125, 50, 0)',
                        lineStroke: 'rgb(46, 125, 50)',
                    }),
                    buildDataset(type, 'Макс. время (мин)', <?= json_encode($chartData['3']['max']) ?>, {
                        barFill: 'rgba(211, 47, 47, 0.4)',
                        barStroke: 'rgb(211, 47, 47)',
                        lineFill: 'rgba(211, 47, 47, 0)',
                        lineStroke: 'rgb(211, 47, 47)',
                    }),
                ],
            },
            options,
        });
    };

    const initialType = getType();
    if (chartTypeSwitch) {
        setSwitchState(initialType);
        chartTypeSwitch.addEventListener('click', (e) => {
            const btn = e.target.closest('button.chart-type-btn');
            if (!btn) return;
            const t = (btn.getAttribute('data-type') || '').trim();
            if (t !== 'line' && t !== 'bar') return;
            setType(t);
            setSwitchState(t);
            renderCharts(t);
        });
    }
    renderCharts(initialType);
    <?php endif; ?>

    </script>
</body>
</html>
