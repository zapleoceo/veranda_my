<?php
require_once __DIR__ . '/src/classes/PosterAPI.php';

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

$token = trim((string)($_ENV['POSTER_API_TOKEN'] ?? ''));

$ym = (string)($_GET['ym'] ?? '');
if (!preg_match('/^\d{4}-\d{2}$/', $ym)) {
    $ym = date('Y-m');
}

$monthStart = $ym . '-01';
$monthStartTs = strtotime($monthStart . ' 00:00:00');
$monthEndTs = strtotime('+1 month', $monthStartTs);
$monthEndTs = $monthEndTs !== false ? strtotime('-1 day', $monthEndTs) : false;
$monthEnd = $monthEndTs !== false ? date('Y-m-d', $monthEndTs) : $monthStart;

const HOOKAH_CATEGORY_ID = 47;

if (($_GET['ajax'] ?? '') === 'day') {
    header('Content-Type: application/json; charset=utf-8');
    $date = trim((string)($_GET['date'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Bad request'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($token === '') {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'POSTER_API_TOKEN не задан'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $api = new \App\Classes\PosterAPI($token);
    try {
        $products = $api->request('menu.getProducts', [], 'GET');
        if (!is_array($products)) $products = [];
        $catByPid = [];
        foreach ($products as $p) {
            if (!is_array($p)) continue;
            $pid = (int)($p['product_id'] ?? 0);
            if ($pid <= 0) continue;
            $catByPid[$pid] = (int)($p['menu_category_id'] ?? $p['category_id'] ?? $p['main_category_id'] ?? 0);
        }

        $txIds = [];
        $nextTr = null;
        $prevNextTr = null;
        $guard = 0;
        do {
            $guard++;
            if ($guard > 20000) break;
            $params = [
                'dateFrom' => str_replace('-', '', $date),
                'dateTo' => str_replace('-', '', $date),
                'include_products' => 'false',
                'include_history' => 'false',
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
                $id = (int)($tx['transaction_id'] ?? 0);
                if ($id > 0) $txIds[$id] = true;
            }
            if ($nextTr !== null && $prevNextTr !== null && (string)$nextTr === (string)$prevNextTr) break;
        } while ($count > 0 && $nextTr !== null);

        $totalChecks = count($txIds);
        $missingChecks = 0;

        foreach (array_keys($txIds) as $txId) {
            $history = $api->request('dash.getTransactionHistory', ['transaction_id' => (int)$txId], 'GET');
            if (!is_array($history)) $history = [];

            $send = [];
            $finished = [];
            $deleted = [];

            foreach ($history as $ev) {
                if (!is_array($ev)) continue;
                $type = (string)($ev['type_history'] ?? '');
                if ($type === 'sendtokitchen') {
                    $items = $ev['value_text'] ?? null;
                    if (is_string($items)) {
                        $decoded = json_decode($items, true);
                        $items = is_array($decoded) ? $decoded : null;
                    }
                    if (!is_array($items)) continue;
                    foreach ($items as $it) {
                        if (!is_array($it)) continue;
                        $pid = (int)($it['product_id'] ?? 0);
                        if ($pid <= 0) continue;
                        $cat = (int)($catByPid[$pid] ?? 0);
                        if ($cat === HOOKAH_CATEGORY_ID) continue;
                        $cnt = isset($it['count']) ? (int)$it['count'] : 1;
                        if ($cnt <= 0) continue;
                        $send[$pid] = ($send[$pid] ?? 0) + $cnt;
                    }
                    continue;
                }
                if ($type === 'finishedcooking') {
                    $pid = (int)($ev['value'] ?? 0);
                    if ($pid <= 0) continue;
                    $cat = (int)($catByPid[$pid] ?? 0);
                    if ($cat === HOOKAH_CATEGORY_ID) continue;
                    $finished[$pid] = ($finished[$pid] ?? 0) + 1;
                    continue;
                }
                if ($type === 'deleteitem' || $type === 'delete') {
                    $pid = (int)($ev['value'] ?? 0);
                    if ($pid <= 0) continue;
                    $cat = (int)($catByPid[$pid] ?? 0);
                    if ($cat === HOOKAH_CATEGORY_ID) continue;
                    $deleted[$pid] = ($deleted[$pid] ?? 0) + 1;
                    continue;
                }
                if ($type === 'changeitemcount') {
                    $pid = (int)($ev['value'] ?? 0);
                    if ($pid <= 0) continue;
                    $cat = (int)($catByPid[$pid] ?? 0);
                    if ($cat === HOOKAH_CATEGORY_ID) continue;
                    $cnt = (int)($ev['value2'] ?? 0);
                    if ($cnt <= 0) $deleted[$pid] = ($deleted[$pid] ?? 0) + 1;
                    continue;
                }
            }

            $isMissing = false;
            foreach ($send as $pid => $cnt) {
                $eff = $cnt - (int)($deleted[$pid] ?? 0);
                if ($eff < 0) $eff = 0;
                if ($eff <= 0) continue;
                $fin = (int)($finished[$pid] ?? 0);
                if ($fin < $eff) {
                    $isMissing = true;
                    break;
                }
            }
            if ($isMissing) $missingChecks++;
        }

        echo json_encode([
            'ok' => true,
            'date' => $date,
            'total' => $totalChecks,
            'missing' => $missingChecks,
            'ready' => max(0, $totalChecks - $missingChecks),
        ], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

$monthRow = ['total' => 0, 'missing_cnt' => 0];
$daysMap = [];

$selected = (string)($_GET['date'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selected) || $selected < $monthStart || $selected > $monthEnd) {
    $today = date('Y-m-d');
    $selected = ($today >= $monthStart && $today <= $monthEnd) ? $today : $monthStart;
}

$initial = ['date' => $selected, 'total' => 0, 'ready' => 0, 'missing' => 0];

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
        .wrap { max-width: 1450px; margin: 0 auto; padding: 16px; }
        .top { display:flex; align-items:flex-end; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
        h1 { margin: 0; font-size: 20px; }
        .controls { display:flex; gap: 10px; align-items:center; }
        .card { background: #fff; border: 1px solid #e5e7eb; border-radius: 14px; padding: 12px; box-shadow: 0 4px 10px rgba(0,0,0,0.04); }
        .grid { display:grid; grid-template-columns: 572px 1fr; gap: 12px; align-items:start; margin-top: 12px; }
        .kpis { display:grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px; }
        .kpi { border: 1px solid #eef2f7; border-radius: 12px; padding: 10px; background: #fff; }
        .kpi .label { font-size: 12px; color:#6b7280; }
        .kpi .val { font-weight: 900; font-size: 18px; margin-top: 6px; }
        .muted { color:#6b7280; font-size: 12px; }
        .cal { display:grid; grid-template-columns: repeat(7, 1fr); gap: 8px; overflow-x: auto; min-width: 660px; }
        .cal .dow { font-size: 12px; color:#6b7280; text-align:center; }
        .day { border: 1px solid #e5e7eb; border-radius: 10px; padding: 6px; background: #fff; cursor:pointer; min-height: 45px; display:flex; flex-direction: column; gap: 4px; min-width: 0; }
        .day.disabled { opacity: 0.35; cursor: default; }
        .day.active { outline: 2px solid #1a73e8; outline-offset: -2px; }
        .day .num { font-weight: 900; }
        .day .mini { font-size: 10px; color:#374151; display:flex; align-items:center; gap: 4px; justify-content: flex-start; white-space: nowrap; }
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
            <div class="muted">Период: <?= htmlspecialchars($monthStart) ?> — <?= htmlspecialchars($monthEnd) ?> · источник: Poster (dash.getTransactions + dash.getTransactionHistory)</div>
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
            <div class="muted" style="margin-top:6px;">X|Y: X — всего чеков, Y — чеков без отметки о готовности блюд.</div>
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
                    <div class="muted">Готовность: в истории чека должны быть finishedcooking по всем отправленным на кухню позициям.</div>
                </div>
                <div class="legend">
                    <span class="pill"><span class="dot total"></span>всего</span>
                    <span class="pill"><span class="dot miss"></span>без отметки</span>
                </div>
            </div>

            <div class="kpis" style="margin-top: 10px;">
                <div class="kpi">
                    <div class="label">Всего чеков</div>
                    <div class="val" id="kpiTotal"><?= (int)$initial['total'] ?></div>
                    <div class="muted">готово: <span id="kpiReady"><?= (int)$initial['ready'] ?></span> · без: <span id="kpiMissing"><?= (int)$initial['missing'] ?></span></div>
                </div>
                <div class="kpi">
                    <div class="label">Готово</div>
                    <div class="val" id="kpiReadyCard"><?= (int)$initial['ready'] ?></div>
                    <div class="muted">чеки без пропусков cooked</div>
                </div>
                <div class="kpi">
                    <div class="label">Без отметки</div>
                    <div class="val" id="kpiMissingCard"><?= (int)$initial['missing'] ?></div>
                    <div class="muted">есть позиции без finishedcooking</div>
                </div>
            </div>

            <div style="margin-top: 12px; font-weight:900;">График по дням</div>
            <div class="chart-wrap">
                <div class="chart" id="dayChart"></div>
            </div>
        </div>
    </div>
</div>

<script>
    const initial = <?= json_encode($initial, JSON_UNESCAPED_UNICODE) ?>;
    const ym = <?= json_encode($ym, JSON_UNESCAPED_UNICODE) ?>;
    const monthStart = <?= json_encode($monthStart, JSON_UNESCAPED_UNICODE) ?>;
    const monthEnd = <?= json_encode($monthEnd, JSON_UNESCAPED_UNICODE) ?>;
    const selectedInitial = <?= json_encode($selected, JSON_UNESCAPED_UNICODE) ?>;

    const dateList = (() => {
        const out = [];
        const a = new Date(String(monthStart) + 'T00:00:00Z');
        const b = new Date(String(monthEnd) + 'T00:00:00Z');
        if (isNaN(a.getTime()) || isNaN(b.getTime()) || a.getTime() > b.getTime()) return out;
        for (let t = a.getTime(); t <= b.getTime(); t += 86400000) {
            out.push(new Date(t).toISOString().slice(0, 10));
        }
        return out;
    })();

    const monthData = new Map();

    const renderChart = () => {
        const el = document.getElementById('dayChart');
        if (!el) return;
        el.innerHTML = '';
        const rows = dateList.map((d) => ({ date: d, ...(monthData.get(d) || { total: 0, missing: 0 }) }));
        const maxTotal = Math.max(1, ...rows.map(x => Number(x.total || 0)));
        rows.forEach((row, idx) => {
            const total = Number(row.total || 0);
            const missing = Number(row.missing || 0);
            const bar = document.createElement('div');
            bar.className = 'bar';
            bar.title = `${row.date} — всего ${total} | без ${missing}`;
            const heightPct = (total / maxTotal) * 100;
            bar.style.height = `calc(${heightPct}% + 2px)`;
            const miss = document.createElement('div');
            miss.className = 'miss';
            miss.style.height = total > 0 ? `${(missing / total) * 100}%` : '0%';
            bar.appendChild(miss);
            const lab = document.createElement('div');
            lab.className = 'label';
            const dayNum = idx + 1;
            lab.textContent = (dayNum % 2 === 0) ? String(dayNum) : '';
            bar.appendChild(lab);
            bar.addEventListener('click', () => {
                document.querySelectorAll('.day.active').forEach(x => x.classList.remove('active'));
                const cell = document.querySelector(`.day[data-date="${row.date}"]`);
                if (cell) cell.classList.add('active');
                setKpis({ date: row.date, total, missing, ready: Math.max(0, total - missing) });
            });
            el.appendChild(bar);
        });
    };

    const setKpis = (data) => {
        document.getElementById('dayLabel').textContent = data.date || '';
        document.getElementById('kpiTotal').textContent = String(data.total || 0);
        document.getElementById('kpiReady').textContent = String(data.ready || 0);
        document.getElementById('kpiMissing').textContent = String(data.missing || 0);
        document.getElementById('kpiReadyCard').textContent = String(data.ready || 0);
        document.getElementById('kpiMissingCard').textContent = String(data.missing || 0);
    };

    const loadDay = async (date) => {
        if (monthData.has(date)) return monthData.get(date);
        const url = new URL(location.href);
        url.searchParams.set('ym', ym);
        url.searchParams.set('ajax', 'day');
        url.searchParams.set('date', date);
        const res = await fetch(url.toString(), { headers: { 'Accept': 'application/json' } });
        const txt = await res.text();
        let j = null;
        try { j = JSON.parse(txt); } catch (_) {}
        if (!j || !j.ok) throw new Error((j && j.error) ? j.error : 'Ошибка загрузки');
        monthData.set(date, { total: Number(j.total || 0), missing: Number(j.missing || 0), ready: Number(j.ready || 0) });
        return monthData.get(date);
    };

    const updateCalendarCell = (date, total, missing) => {
        const el = document.querySelector(`.day[data-date="${date}"]`);
        if (!el) return;
        const mini = el.querySelector('.mini');
        if (!mini) return;
        mini.title = 'X — всего чеков, Y — чеков без отметки о готовности блюд';
        mini.innerHTML = `<b title="Всего чеков">${String(total)}</b><span class="sep">|</span><b class="${missing > 0 ? 'miss' : ''}" title="Без отметки о готовности">${String(missing)}</b>`;
    };

    const loadMonth = async () => {
        const monthMissingEl = document.getElementById('monthMissing');
        const monthTotalEl = document.getElementById('monthTotal');
        let done = 0;
        let totalSum = 0;
        let missingSum = 0;
        const concurrency = 4;

        const worker = async (queue) => {
            while (queue.length) {
                const d = queue.shift();
                if (!d) continue;
                const r = await loadDay(d);
                const total = Number(r?.total || 0);
                const missing = Number(r?.missing || 0);
                updateCalendarCell(d, total, missing);
                done++;
            }
        };

        const queue = dateList.slice();
        const workers = new Array(Math.min(concurrency, queue.length)).fill(0).map(() => worker(queue));
        await Promise.all(workers);

        dateList.forEach((d) => {
            const r = monthData.get(d);
            if (!r) return;
            totalSum += Number(r.total || 0);
            missingSum += Number(r.missing || 0);
        });
        if (monthMissingEl) monthMissingEl.textContent = String(missingSum);
        if (monthTotalEl) monthTotalEl.textContent = String(totalSum);
        renderChart();
    };

    document.getElementById('calGrid')?.addEventListener('click', (e) => {
        const t = e.target?.closest?.('.day');
        if (!t || t.classList.contains('disabled')) return;
        const date = t.getAttribute('data-date');
        if (!date) return;
        document.querySelectorAll('.day.active').forEach(x => x.classList.remove('active'));
        t.classList.add('active');
        (async () => {
            const r = await loadDay(date);
            setKpis({ date, total: r.total, missing: r.missing, ready: Math.max(0, r.total - r.missing) });
        })().catch((err) => alert(err && err.message ? err.message : 'Ошибка'));
    });

    setKpis(initial);
    loadMonth()
        .then(async () => {
            const r = await loadDay(selectedInitial);
            setKpis({ date: selectedInitial, total: r.total, missing: r.missing, ready: Math.max(0, r.total - r.missing) });
        })
        .catch((err) => alert(err && err.message ? err.message : 'Ошибка'));
</script>
</body>
</html>
