<?php
require_once __DIR__ . '/src/classes/Database.php';

date_default_timezone_set('Asia/Ho_Chi_Minh');

if (file_exists(__DIR__ . '/.env')) {
    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $t = trim($line);
        if ($t === '' || strpos($t, '#') === 0) continue;
        if (strpos($t, '=') === false) continue;
        [$name, $value] = explode('=', $line, 2);
        $_ENV[$name] = trim($value);
    }
}

$dbHost = $_ENV['DB_HOST'] ?? 'localhost';
$dbName = $_ENV['DB_NAME'] ?? 'veranda_my';
$dbUser = $_ENV['DB_USER'] ?? 'veranda_my';
$dbPass = $_ENV['DB_PASS'] ?? '';
$tableSuffix = (string)($_ENV['DB_TABLE_SUFFIX'] ?? '');
$db = new \App\Classes\Database($dbHost, $dbName, $dbUser, $dbPass, $tableSuffix);
$ks = $db->t('kitchen_stats');

$ym = (string)($_GET['ym'] ?? '');
if (!preg_match('/^\d{4}-\d{2}$/', $ym)) {
    $ym = date('Y-m');
}

$monthStart = $ym . '-01';
$monthStartTs = strtotime($monthStart . ' 00:00:00');
$monthEndTs = strtotime('+1 month', $monthStartTs);
$monthEndTs = $monthEndTs !== false ? strtotime('-1 day', $monthEndTs) : false;
$monthEnd = $monthEndTs !== false ? date('Y-m-d', $monthEndTs) : $monthStart;

$barCond = "(station = '3' OR station = 3 OR station = 'Bar Veranda')";
$kitchenCond = "(station = '2' OR station = 2 OR station = 'Kitchen' OR station = 'Main')";

$baseWhere = "transaction_date BETWEEN ? AND ?
              AND ticket_sent_at IS NOT NULL
              AND COALESCE(was_deleted, 0) = 0
              AND NOT (COALESCE(dish_category_id, 0) = 47 OR COALESCE(dish_sub_category_id, 0) = 47)";

if (($_GET['ajax'] ?? '') === 'day') {
    header('Content-Type: application/json; charset=utf-8');
    $date = trim((string)($_GET['date'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Bad request'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    try {
        $row = $db->query(
            "SELECT
                COUNT(*) total,
                SUM(CASE WHEN ready_pressed_at IS NOT NULL THEN 1 ELSE 0 END) ready_cnt,
                SUM(CASE WHEN ready_pressed_at IS NULL THEN 1 ELSE 0 END) missing_cnt,
                SUM(CASE WHEN {$barCond} THEN 1 ELSE 0 END) total_bar,
                SUM(CASE WHEN {$barCond} AND ready_pressed_at IS NOT NULL THEN 1 ELSE 0 END) ready_bar,
                SUM(CASE WHEN {$barCond} AND ready_pressed_at IS NULL THEN 1 ELSE 0 END) missing_bar,
                SUM(CASE WHEN {$kitchenCond} THEN 1 ELSE 0 END) total_kitchen,
                SUM(CASE WHEN {$kitchenCond} AND ready_pressed_at IS NOT NULL THEN 1 ELSE 0 END) ready_kitchen,
                SUM(CASE WHEN {$kitchenCond} AND ready_pressed_at IS NULL THEN 1 ELSE 0 END) missing_kitchen
             FROM {$ks}
             WHERE transaction_date = ?
               AND ticket_sent_at IS NOT NULL
               AND COALESCE(was_deleted, 0) = 0
               AND NOT (COALESCE(dish_category_id, 0) = 47 OR COALESCE(dish_sub_category_id, 0) = 47)",
            [$date]
        )->fetch();
        $row = is_array($row) ? $row : [];
        $hours = $db->query(
            "SELECT
                HOUR(ticket_sent_at) h,
                COUNT(*) total,
                SUM(CASE WHEN ready_pressed_at IS NULL THEN 1 ELSE 0 END) missing
             FROM {$ks}
             WHERE transaction_date = ?
               AND ticket_sent_at IS NOT NULL
               AND COALESCE(was_deleted, 0) = 0
               AND NOT (COALESCE(dish_category_id, 0) = 47 OR COALESCE(dish_sub_category_id, 0) = 47)
             GROUP BY HOUR(ticket_sent_at)
             ORDER BY h ASC",
            [$date]
        )->fetchAll();
        if (!is_array($hours)) $hours = [];
        $hMap = array_fill(0, 24, ['total' => 0, 'missing' => 0]);
        foreach ($hours as $hRow) {
            $h = (int)($hRow['h'] ?? -1);
            if ($h < 0 || $h > 23) continue;
            $hMap[$h] = ['total' => (int)($hRow['total'] ?? 0), 'missing' => (int)($hRow['missing'] ?? 0)];
        }
        echo json_encode([
            'ok' => true,
            'date' => $date,
            'total' => (int)($row['total'] ?? 0),
            'ready' => (int)($row['ready_cnt'] ?? 0),
            'missing' => (int)($row['missing_cnt'] ?? 0),
            'stations' => [
                'bar' => [
                    'total' => (int)($row['total_bar'] ?? 0),
                    'ready' => (int)($row['ready_bar'] ?? 0),
                    'missing' => (int)($row['missing_bar'] ?? 0),
                ],
                'kitchen' => [
                    'total' => (int)($row['total_kitchen'] ?? 0),
                    'ready' => (int)($row['ready_kitchen'] ?? 0),
                    'missing' => (int)($row['missing_kitchen'] ?? 0),
                ]
            ],
            'hours' => $hMap,
        ], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

$monthRow = $db->query(
    "SELECT
        COUNT(*) total,
        SUM(CASE WHEN ready_pressed_at IS NOT NULL THEN 1 ELSE 0 END) ready_cnt,
        SUM(CASE WHEN ready_pressed_at IS NULL THEN 1 ELSE 0 END) missing_cnt,
        SUM(CASE WHEN {$barCond} THEN 1 ELSE 0 END) total_bar,
        SUM(CASE WHEN {$barCond} AND ready_pressed_at IS NOT NULL THEN 1 ELSE 0 END) ready_bar,
        SUM(CASE WHEN {$barCond} AND ready_pressed_at IS NULL THEN 1 ELSE 0 END) missing_bar,
        SUM(CASE WHEN {$kitchenCond} THEN 1 ELSE 0 END) total_kitchen,
        SUM(CASE WHEN {$kitchenCond} AND ready_pressed_at IS NOT NULL THEN 1 ELSE 0 END) ready_kitchen,
        SUM(CASE WHEN {$kitchenCond} AND ready_pressed_at IS NULL THEN 1 ELSE 0 END) missing_kitchen
     FROM {$ks}
     WHERE {$baseWhere}",
    [$monthStart, $monthEnd]
)->fetch();
$monthRow = is_array($monthRow) ? $monthRow : [];

$daysRows = $db->query(
    "SELECT
        transaction_date d,
        COUNT(*) total,
        SUM(CASE WHEN ready_pressed_at IS NULL THEN 1 ELSE 0 END) missing
     FROM {$ks}
     WHERE {$baseWhere}
     GROUP BY transaction_date
     ORDER BY transaction_date ASC",
    [$monthStart, $monthEnd]
)->fetchAll();
if (!is_array($daysRows)) $daysRows = [];
$daysMap = [];
foreach ($daysRows as $r) {
    $d = (string)($r['d'] ?? '');
    if ($d === '') continue;
    $daysMap[$d] = ['total' => (int)($r['total'] ?? 0), 'missing' => (int)($r['missing'] ?? 0)];
}

$selected = (string)($_GET['date'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selected) || $selected < $monthStart || $selected > $monthEnd) {
    $today = date('Y-m-d');
    $selected = ($today >= $monthStart && $today <= $monthEnd) ? $today : $monthStart;
}

$initial = ['date' => $selected, 'total' => 0, 'ready' => 0, 'missing' => 0, 'stations' => ['bar' => ['total' => 0, 'ready' => 0, 'missing' => 0], 'kitchen' => ['total' => 0, 'ready' => 0, 'missing' => 0]], 'hours' => array_fill(0, 24, ['total' => 0, 'missing' => 0])];
try {
    $row = $db->query(
        "SELECT
            COUNT(*) total,
            SUM(CASE WHEN ready_pressed_at IS NOT NULL THEN 1 ELSE 0 END) ready_cnt,
            SUM(CASE WHEN ready_pressed_at IS NULL THEN 1 ELSE 0 END) missing_cnt,
            SUM(CASE WHEN {$barCond} THEN 1 ELSE 0 END) total_bar,
            SUM(CASE WHEN {$barCond} AND ready_pressed_at IS NOT NULL THEN 1 ELSE 0 END) ready_bar,
            SUM(CASE WHEN {$barCond} AND ready_pressed_at IS NULL THEN 1 ELSE 0 END) missing_bar,
            SUM(CASE WHEN {$kitchenCond} THEN 1 ELSE 0 END) total_kitchen,
            SUM(CASE WHEN {$kitchenCond} AND ready_pressed_at IS NOT NULL THEN 1 ELSE 0 END) ready_kitchen,
            SUM(CASE WHEN {$kitchenCond} AND ready_pressed_at IS NULL THEN 1 ELSE 0 END) missing_kitchen
         FROM {$ks}
         WHERE transaction_date = ?
           AND ticket_sent_at IS NOT NULL
           AND COALESCE(was_deleted, 0) = 0
           AND NOT (COALESCE(dish_category_id, 0) = 47 OR COALESCE(dish_sub_category_id, 0) = 47)",
        [$selected]
    )->fetch();
    $row = is_array($row) ? $row : [];
    $hours = $db->query(
        "SELECT
            HOUR(ticket_sent_at) h,
            COUNT(*) total,
            SUM(CASE WHEN ready_pressed_at IS NULL THEN 1 ELSE 0 END) missing
         FROM {$ks}
         WHERE transaction_date = ?
           AND ticket_sent_at IS NOT NULL
           AND COALESCE(was_deleted, 0) = 0
           AND NOT (COALESCE(dish_category_id, 0) = 47 OR COALESCE(dish_sub_category_id, 0) = 47)
         GROUP BY HOUR(ticket_sent_at)
         ORDER BY h ASC",
        [$selected]
    )->fetchAll();
    if (!is_array($hours)) $hours = [];
    $hMap = array_fill(0, 24, ['total' => 0, 'missing' => 0]);
    foreach ($hours as $hRow) {
        $h = (int)($hRow['h'] ?? -1);
        if ($h < 0 || $h > 23) continue;
        $hMap[$h] = ['total' => (int)($hRow['total'] ?? 0), 'missing' => (int)($hRow['missing'] ?? 0)];
    }
    $initial = [
        'date' => $selected,
        'total' => (int)($row['total'] ?? 0),
        'ready' => (int)($row['ready_cnt'] ?? 0),
        'missing' => (int)($row['missing_cnt'] ?? 0),
        'stations' => [
            'bar' => [
                'total' => (int)($row['total_bar'] ?? 0),
                'ready' => (int)($row['ready_bar'] ?? 0),
                'missing' => (int)($row['missing_bar'] ?? 0),
            ],
            'kitchen' => [
                'total' => (int)($row['total_kitchen'] ?? 0),
                'ready' => (int)($row['ready_kitchen'] ?? 0),
                'missing' => (int)($row['missing_kitchen'] ?? 0),
            ],
        ],
        'hours' => $hMap,
    ];
} catch (\Throwable $e) {
}

$firstDow = (int)date('N', strtotime($monthStart));
$daysInMonth = (int)date('t', strtotime($monthStart));

?><!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>March Test Dashboard</title>
    <style>
        body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; margin: 0; background: #f5f5f5; color:#111827; }
        .wrap { max-width: 1180px; margin: 0 auto; padding: 16px; }
        .top { display:flex; align-items:flex-end; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
        h1 { margin: 0; font-size: 20px; }
        .controls { display:flex; gap: 10px; align-items:center; }
        .card { background: #fff; border: 1px solid #e5e7eb; border-radius: 14px; padding: 12px; box-shadow: 0 4px 10px rgba(0,0,0,0.04); }
        .grid { display:grid; grid-template-columns: 420px 1fr; gap: 12px; align-items:start; margin-top: 12px; }
        .kpis { display:grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px; }
        .kpi { border: 1px solid #eef2f7; border-radius: 12px; padding: 10px; background: #fff; }
        .kpi .label { font-size: 12px; color:#6b7280; }
        .kpi .val { font-weight: 900; font-size: 18px; margin-top: 6px; }
        .muted { color:#6b7280; font-size: 12px; }
        .cal { display:grid; grid-template-columns: repeat(7, 1fr); gap: 8px; overflow-x: auto; }
        .cal .dow { font-size: 12px; color:#6b7280; text-align:center; }
        .day { border: 1px solid #e5e7eb; border-radius: 12px; padding: 8px; background: #fff; cursor:pointer; min-height: 56px; display:flex; flex-direction: column; gap: 6px; min-width: 0; }
        .day.disabled { opacity: 0.35; cursor: default; }
        .day.active { outline: 2px solid #1a73e8; outline-offset: -2px; }
        .day .num { font-weight: 900; }
        .day .mini { font-size: 12px; color:#374151; display:flex; align-items:center; gap: 4px; justify-content: flex-start; white-space: nowrap; }
        .day .sep { color:#9ca3af; font-weight: 900; }
        .day .miss { color:#b91c1c; }
        .pill { display:inline-flex; align-items:center; gap:6px; font-size:12px; padding: 4px 8px; border-radius: 999px; border:1px solid #e5e7eb; background:#fff; }
        .pill.bad { border-color: rgba(211,47,47,0.35); background: rgba(211,47,47,0.08); }
        .pill.ok { border-color: rgba(46,125,50,0.35); background: rgba(46,125,50,0.08); }
        .chart { height: 220px; display:flex; align-items:flex-end; gap: 4px; padding-top: 10px; }
        .bar { flex: 1; min-width: 6px; background: rgba(17,24,39,0.08); border-radius: 6px 6px 2px 2px; position: relative; overflow:hidden; }
        .bar .miss { position:absolute; left:0; right:0; bottom:0; background: rgba(211,47,47,0.55); }
        .bar .label { position:absolute; left:50%; transform: translateX(-50%); bottom: -18px; font-size: 10px; color:#6b7280; white-space: nowrap; }
        .chart-wrap { position: relative; padding-bottom: 22px; }
        .legend { display:flex; gap: 10px; flex-wrap: wrap; margin-top: 8px; }
        .dot { width: 10px; height: 10px; border-radius: 50%; display:inline-block; }
        .dot.total { background: rgba(17,24,39,0.08); border: 1px solid rgba(17,24,39,0.18); }
        .dot.miss { background: rgba(211,47,47,0.55); border: 1px solid rgba(211,47,47,0.75); }
        @media (max-width: 980px) { .grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<div class="wrap">
    <div class="top">
        <div>
            <h1>/march.php — тестовый дашборд без авторизации</h1>
            <div class="muted">Период: <?= htmlspecialchars($monthStart) ?> — <?= htmlspecialchars($monthEnd) ?> · источник: kitchen_stats</div>
        </div>
        <div class="controls">
            <form method="get" class="card" style="display:flex; gap:8px; align-items:center; padding:8px 10px;">
                <span class="muted">Месяц</span>
                <input type="month" name="ym" value="<?= htmlspecialchars($ym) ?>" />
                <button type="submit">Ок</button>
            </form>
        </div>
    </div>

    <div class="grid">
        <div class="card">
            <div style="display:flex; align-items:center; justify-content: space-between; gap: 10px; flex-wrap: wrap;">
                <div style="font-weight:900;">Календарь</div>
                <div class="pill <?= ((int)($monthRow['missing_cnt'] ?? 0) > 0) ? 'bad' : 'ok' ?>">
                    <span class="dot miss"></span><span>без отметки: <b id="monthMissing" title="Без отметки о готовности"><?= (int)($monthRow['missing_cnt'] ?? 0) ?></b></span>
                    <span class="dot total"></span><span>всего: <b id="monthTotal" title="Всего чеков"><?= (int)($monthRow['total'] ?? 0) ?></b></span>
                </div>
            </div>
            <div class="muted" style="margin-top:6px;">Клик по дню — график по часам (всего / без отметки о готовности).</div>
            <div style="margin-top:10px;" class="cal" id="calGrid">
                <?php
                    $dows = ['Пн','Вт','Ср','Чт','Пт','Сб','Вс'];
                    foreach ($dows as $dw) echo '<div class="dow">' . htmlspecialchars($dw) . '</div>';
                    $pad = $firstDow - 1;
                    for ($i = 0; $i < $pad; $i++) echo '<div class="day disabled"></div>';
                    for ($d = 1; $d <= $daysInMonth; $d++) {
                        $date = sprintf('%s-%02d', $ym, $d);
                        $info = $daysMap[$date] ?? ['total' => 0, 'missing' => 0];
                        $isActive = ($date === $selected);
                        $cls = 'day' . ($isActive ? ' active' : '');
                        echo '<div class="' . $cls . '" data-date="' . htmlspecialchars($date) . '">';
                        echo '<div class="num">' . $d . '</div>';
                        $t = (int)$info['total'];
                        $m = (int)$info['missing'];
                        $missCls = $m > 0 ? ' miss' : '';
                        echo '<div class="mini" title="X — всего чеков, Y — без отметки о готовности"><b title="Всего чеков">' . $t . '</b><span class="sep">|</span><b class="' . trim($missCls) . '" title="Без отметки о готовности">' . $m . '</b></div>';
                        echo '</div>';
                    }
                ?>
            </div>
        </div>

        <div class="card">
            <div style="display:flex; align-items:flex-end; justify-content: space-between; gap: 10px; flex-wrap: wrap;">
                <div>
                    <div style="font-weight:900;">День: <span id="dayLabel"><?= htmlspecialchars($selected) ?></span></div>
                    <div class="muted">Отметка о готовности: поле ready_pressed_at</div>
                </div>
                <div class="legend">
                    <span class="pill"><span class="dot total"></span>всего</span>
                    <span class="pill"><span class="dot miss"></span>без отметки</span>
                </div>
            </div>

            <div class="kpis" style="margin-top: 10px;">
                <div class="kpi">
                    <div class="label">Всего</div>
                    <div class="val" id="kpiTotal"><?= (int)$initial['total'] ?></div>
                    <div class="muted">готово: <span id="kpiReady"><?= (int)$initial['ready'] ?></span> · без: <span id="kpiMissing"><?= (int)$initial['missing'] ?></span></div>
                </div>
                <div class="kpi">
                    <div class="label">Бар (station=3)</div>
                    <div class="val" id="kpiBarTotal"><?= (int)$initial['stations']['bar']['total'] ?></div>
                    <div class="muted">готово: <span id="kpiBarReady"><?= (int)$initial['stations']['bar']['ready'] ?></span> · без: <span id="kpiBarMissing"><?= (int)$initial['stations']['bar']['missing'] ?></span></div>
                </div>
                <div class="kpi">
                    <div class="label">Кухня (station=2)</div>
                    <div class="val" id="kpiKitchenTotal"><?= (int)$initial['stations']['kitchen']['total'] ?></div>
                    <div class="muted">готово: <span id="kpiKitchenReady"><?= (int)$initial['stations']['kitchen']['ready'] ?></span> · без: <span id="kpiKitchenMissing"><?= (int)$initial['stations']['kitchen']['missing'] ?></span></div>
                </div>
            </div>

            <div style="margin-top: 12px; font-weight:900;">График по часам</div>
            <div class="chart-wrap">
                <div class="chart" id="hourChart"></div>
            </div>
        </div>
    </div>
</div>

<script>
    const initial = <?= json_encode($initial, JSON_UNESCAPED_UNICODE) ?>;
    const ym = <?= json_encode($ym, JSON_UNESCAPED_UNICODE) ?>;

    const renderChart = (hours, date) => {
        const el = document.getElementById('hourChart');
        if (!el) return;
        el.innerHTML = '';
        const maxTotal = Math.max(1, ...hours.map(x => Number(x.total || 0)));
        for (let h = 0; h < 24; h++) {
            const total = Number(hours[h]?.total || 0);
            const missing = Number(hours[h]?.missing || 0);
            const bar = document.createElement('div');
            bar.className = 'bar';
            const d = String(date || '');
            bar.title = `${d} ${String(h).padStart(2,'0')}:00 — всего ${total} | без ${missing}`;
            const heightPct = (total / maxTotal) * 100;
            bar.style.height = `calc(${heightPct}% + 2px)`;
            const miss = document.createElement('div');
            miss.className = 'miss';
            miss.style.height = total > 0 ? `${(missing / total) * 100}%` : '0%';
            bar.appendChild(miss);
            const lab = document.createElement('div');
            lab.className = 'label';
            lab.textContent = h % 2 === 0 ? String(h) : '';
            bar.appendChild(lab);
            el.appendChild(bar);
        }
    };

    const setKpis = (data) => {
        document.getElementById('dayLabel').textContent = data.date || '';
        document.getElementById('kpiTotal').textContent = String(data.total || 0);
        document.getElementById('kpiReady').textContent = String(data.ready || 0);
        document.getElementById('kpiMissing').textContent = String(data.missing || 0);
        document.getElementById('kpiBarTotal').textContent = String(data.stations?.bar?.total || 0);
        document.getElementById('kpiBarReady').textContent = String(data.stations?.bar?.ready || 0);
        document.getElementById('kpiBarMissing').textContent = String(data.stations?.bar?.missing || 0);
        document.getElementById('kpiKitchenTotal').textContent = String(data.stations?.kitchen?.total || 0);
        document.getElementById('kpiKitchenReady').textContent = String(data.stations?.kitchen?.ready || 0);
        document.getElementById('kpiKitchenMissing').textContent = String(data.stations?.kitchen?.missing || 0);
        renderChart(data.hours || [], data.date || '');
    };

    const loadDay = async (date) => {
        const url = new URL(location.href);
        url.searchParams.set('ym', ym);
        url.searchParams.set('ajax', 'day');
        url.searchParams.set('date', date);
        const res = await fetch(url.toString(), { headers: { 'Accept': 'application/json' } });
        const txt = await res.text();
        let j = null;
        try { j = JSON.parse(txt); } catch (_) {}
        if (!j || !j.ok) throw new Error((j && j.error) ? j.error : 'Ошибка загрузки');
        setKpis(j);
    };

    document.getElementById('calGrid')?.addEventListener('click', (e) => {
        const t = e.target?.closest?.('.day');
        if (!t || t.classList.contains('disabled')) return;
        const date = t.getAttribute('data-date');
        if (!date) return;
        document.querySelectorAll('.day.active').forEach(x => x.classList.remove('active'));
        t.classList.add('active');
        loadDay(date).catch((err) => alert(err && err.message ? err.message : 'Ошибка'));
    });

    setKpis(initial);
</script>
</body>
</html>
