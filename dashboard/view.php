<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/svg+xml" href="/links/favicon.svg">
    <title>Dashboard - Kitchen Analytics</title>
    <link rel="stylesheet" href="/assets/app.css">
    <link rel="stylesheet" href="/assets/datepicker-range-dialog.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/analytics.php'; ?>
    <link rel="stylesheet" href="/assets/css/common.css">
    <link rel="stylesheet" href="/assets/css/dashboard.css">
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
            <?php require $_SERVER['DOCUMENT_ROOT'] . '/partials/user_menu.php'; ?>
        </div>

        <form class="filters" method="GET" id="dashboardFilters" action="/dashboard/">
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

    <script src="/assets/app.js" defer></script>
    <script src="/assets/user_menu.js" defer></script>
    <script src="/assets/datepicker-range-dialog.js"></script>
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
                const url = `/rawdata/?dateFrom=${day}&dateTo=${day}&hourStart=${hourInt}&hourEnd=${hourInt}&station=${stationId}`;
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
                        barFill: 'rgba(255, 143, 0, 0.4)',
                        barStroke: 'rgb(255, 143, 0)',
                        lineFill: 'rgba(255, 143, 0, 0)',
                        lineStroke: 'rgb(255, 143, 0)',
                    }),
                ],
            },
            options,
        });
    };

    const onSwitchClick = (e) => {
        const btn = e.target.closest('button.chart-type-btn');
        if (!btn) return;
        const type = (btn.getAttribute('data-type') || '').trim();
        if (type !== 'line' && type !== 'bar') return;
        setType(type);
        setSwitchState(type);
        renderCharts(type);
    };

    if (chartTypeSwitch) {
        chartTypeSwitch.addEventListener('click', onSwitchClick);
    }

    const initialType = getType();
    setSwitchState(initialType);
    renderCharts(initialType);
    <?php endif; ?>
    </script>
</body>
</html>

