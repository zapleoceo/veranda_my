<?php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/src/classes/PosterAPI.php';
veranda_require('dashboard');

date_default_timezone_set('Asia/Ho_Chi_Minh');

$posterToken = trim((string)($_ENV['POSTER_API_TOKEN'] ?? ''));

$parseYm = function (string $s): ?string {
    $t = trim($s);
    return preg_match('/^\d{4}-\d{2}$/', $t) ? $t : null;
};

$parseDate = function (string $s): ?string {
    $t = trim($s);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $t) ? $t : null;
};

const HOOKAH_CATEGORY_ID = 47;

$loadProductCategoryMap = function (\App\Classes\Database $db, \App\Classes\PosterAPI $api): array {
    $metaTable = $db->t('system_meta');
    $now = time();
    $ttlSec = 3600;
    $cached = '';
    $cachedAt = 0;
    try {
        $row = $db->query("SELECT meta_value FROM {$metaTable} WHERE meta_key = 'poster_products_cat_map_json' LIMIT 1")->fetch();
        $cached = (string)($row['meta_value'] ?? '');
        $row2 = $db->query("SELECT meta_value FROM {$metaTable} WHERE meta_key = 'poster_products_cat_map_updated_at' LIMIT 1")->fetch();
        $cachedAt = (int)($row2['meta_value'] ?? 0);
    } catch (\Throwable $e) {
    }

    $decoded = null;
    if ($cached !== '' && $cachedAt > 0 && ($now - $cachedAt) < $ttlSec) {
        $tmp = json_decode($cached, true);
        if (is_array($tmp)) $decoded = $tmp;
    }

    if (!is_array($decoded)) {
        $products = $api->request('menu.getProducts', [], 'GET');
        if (!is_array($products)) $products = [];
        $map = [];
        foreach ($products as $p) {
            if (!is_array($p)) continue;
            $pid = (int)($p['product_id'] ?? 0);
            if ($pid <= 0) continue;
            $map[$pid] = (int)($p['menu_category_id'] ?? $p['category_id'] ?? $p['main_category_id'] ?? 0);
        }
        $decoded = $map;
        try {
            $db->query(
                "INSERT INTO {$metaTable} (meta_key, meta_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)",
                ['poster_products_cat_map_json', json_encode($decoded, JSON_UNESCAPED_UNICODE)]
            );
            $db->query(
                "INSERT INTO {$metaTable} (meta_key, meta_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)",
                ['poster_products_cat_map_updated_at', (string)$now]
            );
        } catch (\Throwable $e) {
        }
    }

    return is_array($decoded) ? $decoded : [];
};

$extractSentCounts = function (array $history, array $catByPid): array {
    $sent = [];
    foreach ($history as $ev) {
        if (!is_array($ev)) continue;
        if (($ev['type_history'] ?? '') !== 'sendtokitchen') continue;
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
            $sent[$pid] = ($sent[$pid] ?? 0) + $cnt;
        }
    }
    return $sent;
};

$extractFinishedCounts = function (array $history, array $catByPid): array {
    $fin = [];
    foreach ($history as $ev) {
        if (!is_array($ev)) continue;
        if (($ev['type_history'] ?? '') !== 'finishedcooking') continue;
        $pid = (int)($ev['value'] ?? 0);
        if ($pid <= 0) continue;
        $cat = (int)($catByPid[$pid] ?? 0);
        if ($cat === HOOKAH_CATEGORY_ID) continue;
        $fin[$pid] = ($fin[$pid] ?? 0) + 1;
    }
    return $fin;
};

$extractDeletedCounts = function (array $history, array $catByPid): array {
    $del = [];
    foreach ($history as $ev) {
        if (!is_array($ev)) continue;
        $type = (string)($ev['type_history'] ?? '');
        if ($type !== 'deleteitem' && $type !== 'delete' && $type !== 'changeitemcount') continue;
        $pid = (int)($ev['value'] ?? 0);
        if ($pid <= 0) continue;
        $cat = (int)($catByPid[$pid] ?? 0);
        if ($cat === HOOKAH_CATEGORY_ID) continue;
        if ($type === 'changeitemcount') {
            $cnt = (int)($ev['value2'] ?? 0);
            if ($cnt > 0) continue;
        }
        $del[$pid] = ($del[$pid] ?? 0) + 1;
    }
    return $del;
};

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

if (($_GET['ajax'] ?? '') === 'day') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    $date = $parseDate((string)($_GET['date'] ?? ''));
    if ($date === null) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Bad request'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($posterToken === '') {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'POSTER_API_TOKEN не задан'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $api = new \App\Classes\PosterAPI($posterToken);
    try {
        $catByPid = $loadProductCategoryMap($db, $api);

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

        $total = count($txIds);
        $missing = 0;

        foreach (array_keys($txIds) as $txId) {
            $history = $api->request('dash.getTransactionHistory', ['transaction_id' => (int)$txId], 'GET');
            if (!is_array($history)) $history = [];

            $sent = $extractSentCounts($history, $catByPid);
            if (!$sent) continue;
            $finished = $extractFinishedCounts($history, $catByPid);
            $deleted = $extractDeletedCounts($history, $catByPid);

            $isMissing = false;
            foreach ($sent as $pid => $cnt) {
                $eff = $cnt - (int)($deleted[$pid] ?? 0);
                if ($eff < 0) $eff = 0;
                if ($eff <= 0) continue;
                if (((int)($finished[$pid] ?? 0)) < $eff) {
                    $isMissing = true;
                    break;
                }
            }
            if ($isMissing) $missing++;
        }

        echo json_encode([
            'ok' => true,
            'date' => $date,
            'total' => $total,
            'missing' => $missing,
        ], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

$ym = $parseYm((string)($_GET['ym'] ?? '')) ?? date('Y-m');

$monthStart = $ym . '-01';
$monthStartTs = strtotime($monthStart . ' 00:00:00');
$monthEndTs = strtotime('+1 month', $monthStartTs);
$monthEndTs = $monthEndTs !== false ? strtotime('-1 day', $monthEndTs) : false;
$monthEnd = $monthEndTs !== false ? date('Y-m-d', $monthEndTs) : $monthStart;
$firstDow = (int)date('N', strtotime($monthStart));
$daysInMonth = (int)date('t', strtotime($monthStart));

?><!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Errors Dashboard</title>
    <link rel="stylesheet" href="assets/app.css">
    <style>
        body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; margin: 0; background: #f5f5f5; color:#111827; }
        .wrap { max-width: 1450px; margin: 0 auto; padding: 16px; }
        .top { display:flex; align-items:flex-end; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
        h1 { margin: 0; font-size: 20px; }
        .controls { display:flex; gap: 10px; align-items:center; flex-wrap: wrap; }
        .card { background: #fff; border: 1px solid #e5e7eb; border-radius: 14px; padding: 12px; box-shadow: 0 4px 10px rgba(0,0,0,0.04); }
        .grid { display:grid; grid-template-columns: 572px 1fr; gap: 12px; align-items:start; margin-top: 12px; }
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
        .kpis { display:grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px; }
        .kpi { border: 1px solid #eef2f7; border-radius: 12px; padding: 10px; background: #fff; }
        .kpi .label { font-size: 12px; color:#6b7280; }
        .kpi .val { font-weight: 900; font-size: 18px; margin-top: 6px; }
        .pill { display:inline-flex; align-items:center; gap:6px; font-size:12px; padding: 4px 8px; border-radius: 999px; border:1px solid #e5e7eb; background:#fff; }
        .pill.bad { border-color: rgba(211,47,47,0.35); background: rgba(211,47,47,0.08); }
        .pill.ok { border-color: rgba(46,125,50,0.35); background: rgba(46,125,50,0.08); }
        .dot { width: 10px; height: 10px; border-radius: 50%; display:inline-block; }
        .dot.total { background: rgba(17,24,39,0.08); border: 1px solid rgba(17,24,39,0.18); }
        .dot.miss { background: rgba(211,47,47,0.55); border: 1px solid rgba(211,47,47,0.75); }
        .chart { height: 220px; display:flex; align-items:flex-end; gap: 4px; padding-top: 10px; }
        .bar { flex: 1; min-width: 6px; background: rgba(17,24,39,0.08); border-radius: 6px 6px 2px 2px; position: relative; overflow:hidden; }
        .bar .miss { position:absolute; left:0; right:0; bottom:0; background: rgba(211,47,47,0.55); }
        .bar .label { position:absolute; left:50%; transform: translateX(-50%); bottom: -18px; font-size: 10px; color:#6b7280; white-space: nowrap; }
        .chart-wrap { position: relative; padding-bottom: 22px; }
        .legend { display:flex; gap: 10px; flex-wrap: wrap; margin-top: 8px; }
        .overlay { position: fixed; inset: 0; background: rgba(17,24,39,0.55); display:none; align-items: center; justify-content: center; padding: 16px; z-index: 3000; }
        .modal { width: 100%; max-width: 520px; background: #fff; border-radius: 14px; border: 1px solid rgba(0,0,0,0.08); box-shadow: 0 18px 45px rgba(0,0,0,0.22); padding: 14px; }
        .modal h3 { margin: 0 0 10px; font-size: 16px; color: #111827; }
        .pbar { width: 100%; height: 10px; border-radius: 999px; background: rgba(17,24,39,0.08); overflow: hidden; }
        .pbar > div { height: 100%; width: 0; background: rgba(26,115,232,0.95); transition: width 0.15s ease; }
        .pmeta { display:flex; justify-content: space-between; gap: 10px; margin-top: 10px; align-items:center; }
        .pmeta .pct { font-weight: 900; color:#111827; }
        .pmeta .desc { font-weight: 800; color:#6b7280; font-size: 12px; min-width: 0; }
        .err { color:#b91c1c; font-weight: 800; font-size: 12px; margin-top: 10px; }
        @media (max-width: 980px) { .grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<div class="wrap">
    <div class="top">
        <div>
            <h1>/errors.php — отчёт по отсутствию cooked</h1>
            <div class="muted">Источник: Poster (dash.getTransactions + dash.getTransactionHistory)</div>
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
                <div class="pill bad">
                    <span class="dot miss"></span><span>без отметки: <b id="monthMissing">—</b></span>
                    <span class="dot total"></span><span>всего: <b id="monthTotal">—</b></span>
                </div>
            </div>
            <div class="muted" style="margin-top:6px;">X|Y: X — всего чеков, Y — чеков без отметки cooked (по истории).</div>
            <div style="margin-top:10px;" class="cal" id="calGrid">
                <?php
                    $dows = ['Пн','Вт','Ср','Чт','Пт','Сб','Вс'];
                    foreach ($dows as $dw) echo '<div class="dow">' . htmlspecialchars($dw) . '</div>';
                    $pad = $firstDow - 1;
                    for ($i = 0; $i < $pad; $i++) echo '<div class="day disabled"></div>';
                    for ($d = 1; $d <= $daysInMonth; $d++) {
                        $date = sprintf('%s-%02d', $ym, $d);
                        echo '<div class="day" data-date="' . htmlspecialchars($date) . '">';
                        echo '<div class="num">' . $d . '</div>';
                        echo '<div class="mini" title="X — всего чеков, Y — без cooked"><b>—</b><span class="sep">|</span><b class="miss">—</b></div>';
                        echo '</div>';
                    }
                ?>
            </div>
        </div>

        <div class="card">
            <div style="display:flex; align-items:flex-end; justify-content: space-between; gap: 10px; flex-wrap: wrap;">
                <div>
                    <div style="font-weight:900;">День: <span id="dayLabel"><?= htmlspecialchars($monthStart) ?></span></div>
                    <div class="muted">Проверяем: sendtokitchen → finishedcooking (исключая кальяны category_id=47).</div>
                </div>
                <div class="legend">
                    <span class="pill"><span class="dot total"></span>всего</span>
                    <span class="pill"><span class="dot miss"></span>без cooked</span>
                </div>
            </div>

            <div class="kpis" style="margin-top: 10px;">
                <div class="kpi">
                    <div class="label">Всего чеков</div>
                    <div class="val" id="kpiTotal">—</div>
                    <div class="muted">за выбранный день</div>
                </div>
                <div class="kpi">
                    <div class="label">Готово</div>
                    <div class="val" id="kpiReady">—</div>
                    <div class="muted">все отправленные блюда cooked</div>
                </div>
                <div class="kpi">
                    <div class="label">Без cooked</div>
                    <div class="val" id="kpiMissing">—</div>
                    <div class="muted">есть пропуски cooked</div>
                </div>
            </div>

            <div style="margin-top: 12px; font-weight:900;">График по дням</div>
            <div class="chart-wrap">
                <div class="chart" id="dayChart"></div>
            </div>
        </div>
    </div>
</div>

<div class="overlay" id="overlay">
    <div class="modal" role="dialog" aria-modal="true" aria-label="Загрузка">
        <h3>Загрузка…</h3>
        <div class="pbar"><div id="pbarFill"></div></div>
        <div class="pmeta">
            <div class="pct" id="pbarPct">0%</div>
            <div class="desc" id="pbarDesc"></div>
        </div>
        <div class="err" id="pbarErr" style="display:none;"></div>
    </div>
</div>

<script>
    const ym = <?= json_encode($ym, JSON_UNESCAPED_UNICODE) ?>;
    const monthStart = <?= json_encode($monthStart, JSON_UNESCAPED_UNICODE) ?>;
    const monthEnd = <?= json_encode($monthEnd, JSON_UNESCAPED_UNICODE) ?>;

    const overlay = document.getElementById('overlay');
    const pbarFill = document.getElementById('pbarFill');
    const pbarPct = document.getElementById('pbarPct');
    const pbarDesc = document.getElementById('pbarDesc');
    const pbarErr = document.getElementById('pbarErr');

    const dayLabel = document.getElementById('dayLabel');
    const kpiTotal = document.getElementById('kpiTotal');
    const kpiReady = document.getElementById('kpiReady');
    const kpiMissing = document.getElementById('kpiMissing');
    const monthMissing = document.getElementById('monthMissing');
    const monthTotal = document.getElementById('monthTotal');

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

    const setOverlay = (on) => {
        if (!overlay) return;
        overlay.style.display = on ? 'flex' : 'none';
    };
    const setProgress = (pct, desc) => {
        const p = Math.max(0, Math.min(100, Math.round(Number(pct || 0))));
        if (pbarFill) pbarFill.style.width = p + '%';
        if (pbarPct) pbarPct.textContent = p + '%';
        if (pbarDesc) pbarDesc.textContent = String(desc || '');
    };
    const setErr = (msg) => {
        if (!pbarErr) return;
        if (!msg) { pbarErr.style.display = 'none'; pbarErr.textContent = ''; return; }
        pbarErr.style.display = '';
        pbarErr.textContent = String(msg);
    };

    const monthData = new Map();

    const updateCalendarCell = (date, total, missing) => {
        const el = document.querySelector(`.day[data-date="${date}"]`);
        if (!el) return;
        const mini = el.querySelector('.mini');
        if (!mini) return;
        mini.title = 'X — всего чеков, Y — чеков без cooked';
        mini.innerHTML = `<b title="Всего чеков">${String(total)}</b><span class="sep">|</span><b class="${missing > 0 ? 'miss' : ''}" title="Без cooked">${String(missing)}</b>`;
    };

    const setDayKpis = (date, total, missing) => {
        if (dayLabel) dayLabel.textContent = String(date || '');
        if (kpiTotal) kpiTotal.textContent = String(total || 0);
        if (kpiMissing) kpiMissing.textContent = String(missing || 0);
        if (kpiReady) kpiReady.textContent = String(Math.max(0, (total || 0) - (missing || 0)));
    };

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
            bar.title = `${row.date} — всего ${total} | без cooked ${missing}`;
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
                setDayKpis(row.date, total, missing);
            });
            el.appendChild(bar);
        });
    };

    const fetchDay = async (date) => {
        if (monthData.has(date)) return monthData.get(date);
        const url = new URL(location.href);
        url.searchParams.set('ym', ym);
        url.searchParams.set('ajax', 'day');
        url.searchParams.set('date', date);
        const res = await fetch(url.toString(), { headers: { 'Accept': 'application/json' } });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const j = await res.json();
        if (!j || !j.ok) throw new Error((j && j.error) ? j.error : 'Ошибка загрузки');
        const total = Number(j.total || 0);
        const missing = Number(j.missing || 0);
        monthData.set(date, { total, missing });
        return monthData.get(date);
    };

    const loadMonth = async () => {
        setErr('');
        setOverlay(true);
        setProgress(0, 'Подготовка…');
        let done = 0;
        const concurrency = 4;
        const queue = dateList.slice();

        const worker = async () => {
            while (queue.length) {
                const d = queue.shift();
                if (!d) continue;
                await fetchDay(d);
                const r = monthData.get(d);
                updateCalendarCell(d, r.total, r.missing);
                done++;
                const dd = String(d).slice(8, 10);
                const mm = String(d).slice(5, 7);
                setProgress(Math.round((done / dateList.length) * 100), `- день ${done}/${dateList.length} (${dd}/${mm})`);
            }
        };

        try {
            await Promise.all(new Array(Math.min(concurrency, queue.length)).fill(0).map(worker));
            let totalSum = 0;
            let missingSum = 0;
            dateList.forEach((d) => {
                const r = monthData.get(d);
                if (!r) return;
                totalSum += Number(r.total || 0);
                missingSum += Number(r.missing || 0);
            });
            if (monthTotal) monthTotal.textContent = String(totalSum);
            if (monthMissing) monthMissing.textContent = String(missingSum);
            renderChart();
            const firstDay = dateList[0] || '';
            if (firstDay) {
                const r = monthData.get(firstDay) || { total: 0, missing: 0 };
                setDayKpis(firstDay, r.total, r.missing);
                const cell = document.querySelector(`.day[data-date="${firstDay}"]`);
                if (cell) cell.classList.add('active');
            }
            setProgress(100, 'Готово');
            setTimeout(() => setOverlay(false), 250);
        } catch (e) {
            setErr(e && e.message ? e.message : 'Failed to fetch');
        }
    };

    document.getElementById('calGrid')?.addEventListener('click', (e) => {
        const t = e.target?.closest?.('.day');
        if (!t || t.classList.contains('disabled')) return;
        const date = t.getAttribute('data-date');
        if (!date) return;
        document.querySelectorAll('.day.active').forEach(x => x.classList.remove('active'));
        t.classList.add('active');
        (async () => {
            const r = await fetchDay(date);
            setDayKpis(date, r.total, r.missing);
        })().catch((err) => alert(err && err.message ? err.message : 'Ошибка'));
    });

    loadMonth();
</script>
</body>
</html>
