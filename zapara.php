<?php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/src/classes/PosterAPI.php';
veranda_require('zapara');
date_default_timezone_set('Asia/Ho_Chi_Minh');

$posterToken = trim((string)($_ENV['POSTER_API_TOKEN'] ?? ''));

$parseDate = function (string $s): ?string {
    $t = trim($s);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $t) ? $t : null;
};

if (!function_exists('zapara_poster_request')) {
    function zapara_poster_request(string $token, string $method, array $params, int $timeoutSec = 35): array {
        $params['token'] = $token;
        $url = 'https://joinposter.com/api/' . $method . '?' . http_build_query($params);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSec);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
        curl_setopt($ch, CURLOPT_ENCODING, '');
        $body = curl_exec($ch);
        $err = curl_error($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($err) {
            throw new Exception('CURL Error: ' . $err);
        }
        if (!is_string($body) || $body === '') {
            throw new Exception('Poster API Error: empty response (http=' . $http . ', method=' . $method . ')');
        }
        $j = json_decode($body, true);
        if (!is_array($j)) {
            throw new Exception('Poster API Error: invalid json (http=' . $http . ', method=' . $method . ')');
        }
        if (isset($j['error']) || isset($j['error_code'])) {
            $msg = (string)($j['error'] ?? $j['message'] ?? 'Poster API error');
            $code = (string)($j['error_code'] ?? '');
            throw new Exception(trim(($code !== '' ? ($code . ': ') : '') . $msg));
        }
        $resp = $j['response'] ?? $j;
        return is_array($resp) ? $resp : [];
    }
}

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

    $hours = [];
    for ($h = 9; $h <= 23; $h++) $hours[] = $h;
    $countsByHour = [];
    foreach ($hours as $h) $countsByHour[(string)$h] = 0;

    $tz = new DateTimeZone('Asia/Ho_Chi_Minh');
    $total = 0;

    try {
        $nextTr = null;
        $prevNextTr = null;
        $guard = 0;
        do {
            $guard++;
            if ($guard > 20000) break;
            $params = [
                'dateFrom' => str_replace('-', '', $date),
                'dateTo' => str_replace('-', '', $date),
                'status' => 0,
                'include_products' => 'false',
                'include_history' => 'false',
                'include_delivery' => 'false',
                'timezone' => 'client',
            ];
            if ($nextTr !== null) $params['next_tr'] = $nextTr;
            $batch = zapara_poster_request($posterToken, 'dash.getTransactions', $params, 40);
            if (!is_array($batch)) $batch = [];
            if (count($batch) === 0) break;

            $last = end($batch);
            $nextTrCandidate = is_array($last) ? ($last['transaction_id'] ?? null) : null;

            foreach ($batch as $tx) {
                if (!is_array($tx)) continue;
                $v = $tx['date_start_new'] ?? $tx['date_start'] ?? null;
                if ($v === null) continue;
                $ts = (int)$v;
                if ($ts > 10000000000) $ts = (int)round($ts / 1000);
                if ($ts <= 0) continue;
                $dt = (new DateTimeImmutable('@' . $ts))->setTimezone($tz);
                $hour = (int)$dt->format('G');
                if ($hour < 9 || $hour > 23) continue;
                $countsByHour[(string)$hour] += 1;
                $total++;
            }

            $prevNextTr = $nextTr;
            $nextTr = $nextTrCandidate;
            if ($nextTr === null || $nextTr === $prevNextTr) break;
        } while (true);

        $dow = (int)(new DateTimeImmutable($date . ' 12:00:00', $tz))->format('N');

        echo json_encode([
            'ok' => true,
            'date' => $date,
            'dow' => $dow,
            'hours' => $hours,
            'counts_by_hour' => $countsByHour,
            'total' => $total,
        ], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if (($_GET['ajax'] ?? '') === 'data') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    $dateFrom = $parseDate((string)($_GET['date_from'] ?? ''));
    $dateTo = $parseDate((string)($_GET['date_to'] ?? ''));
    if ($dateFrom === null || $dateTo === null || $dateFrom > $dateTo) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Bad request'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($posterToken === '') {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'POSTER_API_TOKEN не задан'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $fromTs = strtotime($dateFrom . ' 00:00:00');
    $toTs = strtotime($dateTo . ' 00:00:00');
    if ($fromTs === false || $toTs === false) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Bad request'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $days = (int)floor(($toTs - $fromTs) / 86400) + 1;
    if ($days <= 0 || $days > 366) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Слишком большой диапазон'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $hours = [];
    for ($h = 9; $h <= 23; $h++) $hours[] = $h;

    $counts = [];
    for ($dow = 1; $dow <= 7; $dow++) {
        $counts[(string)$dow] = [];
        foreach ($hours as $h) $counts[(string)$dow][(string)$h] = 0;
    }

    $api = new \App\Classes\PosterAPI($posterToken);
    $tz = new DateTimeZone('Asia/Ho_Chi_Minh');
    $total = 0;
    $seen = [];
    $nextTr = null;
    $prevNextTr = null;
    $guard = 0;

    try {
        do {
            $guard++;
            if ($guard > 2000) break;
            $params = [
                'dateFrom' => str_replace('-', '', $dateFrom),
                'dateTo' => str_replace('-', '', $dateTo),
                'status' => 0,
                'include_products' => 'false',
                'include_history' => 'false',
                'include_delivery' => 'false',
                'timezone' => 'client',
            ];
            if ($nextTr !== null) $params['next_tr'] = $nextTr;
            $batch = $api->request('dash.getTransactions', $params, 'GET');
            if (!is_array($batch)) $batch = [];
            $count = count($batch);
            if ($count === 0) break;

            $last = end($batch);
            $nextTrCandidate = is_array($last) ? ($last['transaction_id'] ?? null) : null;

            foreach ($batch as $tx) {
                if (!is_array($tx)) continue;
                $txId = (int)($tx['transaction_id'] ?? 0);
                if ($txId <= 0) continue;
                if (isset($seen[$txId])) continue;
                $seen[$txId] = true;

                $v = $tx['date_start_new'] ?? $tx['date_start'] ?? null;
                if ($v === null) continue;
                $ts = (int)$v;
                if ($ts > 10000000000) $ts = (int)round($ts / 1000);
                if ($ts <= 0) continue;

                $dt = (new DateTimeImmutable('@' . $ts))->setTimezone($tz);
                $hour = (int)$dt->format('G');
                if ($hour < 9 || $hour > 23) continue;
                $dow = (int)$dt->format('N');
                $dk = (string)$dow;
                $hk = (string)$hour;
                if (!isset($counts[$dk]) || !array_key_exists($hk, $counts[$dk])) continue;
                $counts[$dk][$hk] += 1;
                $total++;
            }

            $prevNextTr = $nextTr;
            $nextTr = $nextTrCandidate;
            if ($nextTr === null || $nextTr === $prevNextTr) break;
        } while (true);

        echo json_encode([
            'ok' => true,
            'request' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'days' => $days,
            ],
            'hours' => $hours,
            'counts_by_dow' => $counts,
            'total' => $total,
        ], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

$today = date('Y-m-d');
$defaultFrom = date('Y-m-d', strtotime('-14 days'));
$defaultTo = $today;

?><!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Zapara</title>
    <link rel="stylesheet" href="assets/app.css">
    <script src="/assets/app.js" defer></script>
    <script src="assets/user_menu.js" defer></script>
    <style>
        body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; margin: 0; background: #0b0f16; color: rgba(255,250,244,0.92); }
        .wrap { max-width: 1450px; margin: 0 auto; padding: 16px; }
        .top { display:flex; align-items:flex-start; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
        @media (max-width: 680px) {
            .top { flex-direction: column; }
            .top .user-menu { order: 1; align-self: flex-end; }
            .top .controls { order: 2; width: 100%; }
        }
        h1 { margin: 0; font-size: 20px; }
        .muted { color: rgba(245,238,228,0.62); font-size: 12px; }
        .controls { display:flex; gap: 10px; align-items:flex-start; flex-wrap: wrap; }
        .card { background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.12); border-radius: 14px; padding: 12px; box-shadow: 0 6px 18px rgba(0,0,0,0.35); }
        .grid { display:grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; margin-top: 12px; }
        .chart { height: 160px; position: relative; }
        .chart canvas { width: 100%; height: 100%; display:block; }
        .row { display:flex; justify-content: space-between; align-items: baseline; gap: 10px; }
        .pill { display:inline-flex; align-items:center; gap:6px; font-size:12px; padding: 4px 8px; border-radius: 999px; border:1px solid rgba(255,255,255,0.12); background: rgba(255,255,255,0.06); }
        .btn { border: 1px solid rgba(255,255,255,0.18); background: rgba(255,255,255,0.10); color: rgba(255,250,244,0.92); border-radius: 10px; padding: 8px 10px; cursor: pointer; font-weight: 800; }
        .btn:disabled { opacity: 0.5; cursor: default; }
        input[type="date"] { border: 1px solid rgba(255,255,255,0.18); background: rgba(255,255,255,0.08); color: rgba(255,250,244,0.92); border-radius: 10px; padding: 7px 10px; }
        .filters-card { display:flex; flex-direction: column; gap: 8px; padding: 10px 12px; }
        .filters-row { display:flex; gap: 10px; align-items:center; flex-wrap: wrap; }
        .chart-switch { display:inline-flex; align-items:center; gap: 8px; margin-left: 2px; }
        .chart-switch span { font-size: 12px; font-weight: 800; color: rgba(245,238,228,0.62); user-select: none; }
        .chart-switch input { display:none; }
        .chart-switch .track { width: 40px; height: 22px; border-radius: 999px; background: rgba(255,255,255,0.10); border: 1px solid rgba(255,255,255,0.14); position: relative; cursor: pointer; flex: 0 0 auto; }
        .chart-switch .knob { width: 18px; height: 18px; border-radius: 999px; background: rgba(255,250,244,0.92); position: absolute; left: 2px; top: 1px; transition: left 0.15s ease, background 0.15s ease; }
        .chart-switch input:checked + .track .knob { left: 20px; background: rgba(255, 120, 120, 0.90); }
        .prog { display:flex; align-items:center; gap: 10px; opacity: 0; pointer-events: none; height: 22px; }
        .prog.on { opacity: 1; }
        .progbar { height: 10px; flex: 1; border-radius: 999px; background: rgba(255,255,255,0.10); overflow: hidden; }
        .progbar > div { height: 100%; width: 0; background: rgba(255, 120, 120, 0.86); transition: width 0.15s ease; }
        .progPct { font-weight: 900; font-size: 12px; color: rgba(255,250,244,0.85); min-width: 40px; text-align: right; }
        .progText { font-weight: 700; font-size: 12px; color: rgba(245,238,228,0.62); min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        @media (max-width: 980px) { .grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<div class="wrap">
    <div class="top">
        <div class="controls">
            <div class="card filters-card">
                <div class="filters-row">
                    <span class="muted">С</span>
                    <input type="date" id="dateFrom" value="<?= htmlspecialchars($defaultFrom) ?>">
                    <span class="muted">По</span>
                    <input type="date" id="dateTo" value="<?= htmlspecialchars($defaultTo) ?>">
                    <button class="btn" id="loadBtn">Загрузить</button>
                    <label class="chart-switch">
                        <span>Колонки</span>
                        <input type="checkbox" id="chartTypeToggle">
                        <span class="track"><span class="knob"></span></span>
                        <span>Линия</span>
                    </label>
                </div>
                <div class="prog" id="prog">
                    <div class="progbar"><div id="progFill"></div></div>
                    <div class="progPct" id="progPct">0%</div>
                    <div class="progText" id="progText">—</div>
                </div>
            </div>
        </div>
        <?php require __DIR__ . '/partials/user_menu.php'; ?>
    </div>
    <div style="margin-top: 12px;">
        <h1>Zapara</h1>
        <div class="muted">Источник: Poster (dash.getTransactions), группировка по дню недели и часу открытия чека</div>
    </div>

    <div class="grid" id="charts"><div class="card muted" style="display:flex; align-items:center; justify-content:center; min-height: 120px;">Выбери период и нажми «Загрузить»</div></div>
</div>

<script>
(() => {
    const chartsEl = document.getElementById('charts');
    const dateFromEl = document.getElementById('dateFrom');
    const dateToEl = document.getElementById('dateTo');
    const loadBtn = document.getElementById('loadBtn');
    const chartTypeToggle = document.getElementById('chartTypeToggle');
    const prog = document.getElementById('prog');
    const progFill = document.getElementById('progFill');
    const progPct = document.getElementById('progPct');
    const progText = document.getElementById('progText');
    let chartType = 'bar';
    let lastData = null;

    try {
        chartType = (localStorage.getItem('zapara_chart_type') || '') === 'line' ? 'line' : 'bar';
    } catch (_) {}
    if (chartTypeToggle) {
        chartTypeToggle.checked = chartType === 'line';
        chartTypeToggle.addEventListener('change', () => {
            chartType = chartTypeToggle.checked ? 'line' : 'bar';
            try { localStorage.setItem('zapara_chart_type', chartType); } catch (_) {}
            if (lastData) render(lastData);
        });
    }

    const dows = [
        { key: '1', name: 'Пн' },
        { key: '2', name: 'Вт' },
        { key: '3', name: 'Ср' },
        { key: '4', name: 'Чт' },
        { key: '5', name: 'Пт' },
        { key: '6', name: 'Сб' },
        { key: '7', name: 'Вс' },
    ];

    const makeCanvasCard = (title) => {
        const wrap = document.createElement('div');
        wrap.className = 'card';
        const head = document.createElement('div');
        head.className = 'row';
        const t = document.createElement('div');
        t.textContent = title;
        t.style.fontWeight = '900';
        const meta = document.createElement('div');
        meta.className = 'muted';
        meta.textContent = '09:00 — 24:00';
        head.appendChild(t);
        head.appendChild(meta);
        const box = document.createElement('div');
        box.className = 'chart';
        const canvas = document.createElement('canvas');
        canvas.width = 800;
        canvas.height = 220;
        box.appendChild(canvas);
        wrap.appendChild(head);
        wrap.appendChild(box);
        return { wrap, canvas };
    };

    const clearCharts = () => { chartsEl.innerHTML = ''; };

    const drawBars = (canvas, hours, countsByHour) => {
        const ctx = canvas.getContext('2d');
        if (!ctx) return;
        const w = canvas.width;
        const h = canvas.height;
        ctx.clearRect(0, 0, w, h);

        const padL = 44;
        const padR = 16;
        const padT = 12;
        const padB = 28;
        const iw = w - padL - padR;
        const ih = h - padT - padB;

        const vals = hours.map((hh) => Number(countsByHour[String(hh)] || 0));
        const maxV = Math.max(1, ...vals);

        ctx.strokeStyle = 'rgba(255,255,255,0.10)';
        ctx.lineWidth = 1;
        for (let i = 0; i <= 4; i++) {
            const y = padT + (ih * i / 4);
            ctx.beginPath();
            ctx.moveTo(padL, y);
            ctx.lineTo(padL + iw, y);
            ctx.stroke();
        }

        ctx.fillStyle = 'rgba(245,238,228,0.62)';
        ctx.font = '12px system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif';
        ctx.textAlign = 'right';
        ctx.textBaseline = 'middle';
        for (let i = 0; i <= 4; i++) {
            const v = Math.round(maxV * (1 - i / 4));
            const y = padT + (ih * i / 4);
            ctx.fillText(String(v), padL - 8, y);
        }

        const barGap = 6;
        const barW = Math.max(6, Math.floor((iw - barGap * (hours.length - 1)) / hours.length));
        const usedW = barW * hours.length + barGap * (hours.length - 1);
        const startX = padL + Math.floor((iw - usedW) / 2);

        ctx.textAlign = 'center';
        ctx.textBaseline = 'top';
        hours.forEach((hh, idx) => {
            const v = vals[idx];
            const x = startX + idx * (barW + barGap);
            const bh = Math.round((v / maxV) * ih);
            const y = padT + (ih - bh);

            ctx.fillStyle = 'rgba(255, 120, 120, 0.86)';
            ctx.fillRect(x, y, barW, bh);

            const label = String(hh);
            ctx.fillStyle = 'rgba(245,238,228,0.62)';
            if (hh % 2 === 1) ctx.fillText(label, x + barW / 2, padT + ih + 6);
        });
    };

    const drawLine = (canvas, hours, countsByHour) => {
        const ctx = canvas.getContext('2d');
        if (!ctx) return;
        const w = canvas.width;
        const h = canvas.height;
        ctx.clearRect(0, 0, w, h);

        const padL = 44;
        const padR = 16;
        const padT = 12;
        const padB = 28;
        const iw = w - padL - padR;
        const ih = h - padT - padB;

        const vals = hours.map((hh) => Number(countsByHour[String(hh)] || 0));
        const maxV = Math.max(1, ...vals);

        ctx.strokeStyle = 'rgba(255,255,255,0.10)';
        ctx.lineWidth = 1;
        for (let i = 0; i <= 4; i++) {
            const y = padT + (ih * i / 4);
            ctx.beginPath();
            ctx.moveTo(padL, y);
            ctx.lineTo(padL + iw, y);
            ctx.stroke();
        }

        ctx.fillStyle = 'rgba(245,238,228,0.62)';
        ctx.font = '12px system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif';
        ctx.textAlign = 'right';
        ctx.textBaseline = 'middle';
        for (let i = 0; i <= 4; i++) {
            const v = Math.round(maxV * (1 - i / 4));
            const y = padT + (ih * i / 4);
            ctx.fillText(String(v), padL - 8, y);
        }

        const stepX = iw / Math.max(1, (hours.length - 1));
        const x0 = padL;

        ctx.strokeStyle = 'rgba(255, 120, 120, 0.92)';
        ctx.lineWidth = 2;
        ctx.beginPath();
        hours.forEach((hh, idx) => {
            const v = vals[idx];
            const x = x0 + stepX * idx;
            const y = padT + ih - (v / maxV) * ih;
            if (idx === 0) ctx.moveTo(x, y);
            else ctx.lineTo(x, y);
        });
        ctx.stroke();

        ctx.fillStyle = 'rgba(255, 120, 120, 0.92)';
        hours.forEach((hh, idx) => {
            const v = vals[idx];
            const x = x0 + stepX * idx;
            const y = padT + ih - (v / maxV) * ih;
            ctx.beginPath();
            ctx.arc(x, y, 2.5, 0, Math.PI * 2);
            ctx.fill();
        });

        ctx.textAlign = 'center';
        ctx.textBaseline = 'top';
        hours.forEach((hh, idx) => {
            if (hh % 2 !== 1) return;
            const x = x0 + stepX * idx;
            ctx.fillStyle = 'rgba(245,238,228,0.62)';
            ctx.fillText(String(hh), x, padT + ih + 6);
        });
    };

    const drawChart = (canvas, hours, countsByHour) => {
        if (chartType === 'line') drawLine(canvas, hours, countsByHour);
        else drawBars(canvas, hours, countsByHour);
    };

    const hours = [];
    for (let hh = 9; hh <= 23; hh++) hours.push(hh);

    const render = (data) => {
        clearCharts();
        const counts = (data && data.counts_by_dow) ? data.counts_by_dow : {};
        const avg = {};
        hours.forEach((h) => {
            const hk = String(h);
            let sum = 0;
            let n = 0;
            dows.forEach((d) => {
                const v = counts && counts[d.key] ? Number(counts[d.key][hk] || 0) : 0;
                if (isFinite(v)) { sum += v; n += 1; }
            });
            avg[hk] = n > 0 ? (sum / n) : 0;
        });

        dows.forEach((d) => {
            const { wrap, canvas } = makeCanvasCard(d.name);
            chartsEl.appendChild(wrap);
            drawChart(canvas, hours, counts[d.key] || {});
        });
        {
            const { wrap, canvas } = makeCanvasCard('Среднее');
            chartsEl.appendChild(wrap);
            drawChart(canvas, hours, avg);
        }
    };

    const setProgress = (done, total, text) => {
        if (!prog || !progFill || !progPct || !progText) return;
        const pct = total > 0 ? Math.round((done / total) * 100) : 0;
        prog.classList.add('on');
        progFill.style.width = String(Math.max(0, Math.min(100, pct))) + '%';
        progPct.textContent = String(pct) + '%';
        progText.textContent = String(text || '');
    };
    const hideProgress = () => {
        if (!prog) return;
        prog.classList.remove('on');
    };

    const parseYmd = (s) => {
        const m = String(s || '').trim().match(/^(\d{4})-(\d{2})-(\d{2})$/);
        if (!m) return null;
        return { y: Number(m[1]), mo: Number(m[2]), d: Number(m[3]) };
    };
    const fmtYmd = (dt) => {
        const y = dt.getFullYear();
        const m = String(dt.getMonth() + 1).padStart(2, '0');
        const d = String(dt.getDate()).padStart(2, '0');
        return String(y) + '-' + m + '-' + d;
    };
    const buildDateList = (fromStr, toStr) => {
        const a = parseYmd(fromStr);
        const b = parseYmd(toStr);
        if (!a || !b) return [];
        const from = new Date(a.y, a.mo - 1, a.d, 12, 0, 0);
        const to = new Date(b.y, b.mo - 1, b.d, 12, 0, 0);
        if (!(from instanceof Date) || !(to instanceof Date) || !isFinite(from.getTime()) || !isFinite(to.getTime())) return [];
        if (from.getTime() > to.getTime()) return [];
        const out = [];
        for (let cur = new Date(from.getTime()); cur.getTime() <= to.getTime(); cur.setDate(cur.getDate() + 1)) {
            out.push(fmtYmd(cur));
            if (out.length > 366) break;
        }
        return out;
    };

    const load = async () => {
        const df = String(dateFromEl.value || '').trim();
        const dt = String(dateToEl.value || '').trim();
        const dates = buildDateList(df, dt);
        if (!df || !dt || dates.length === 0) return;
        if (dates.length > 366) { alert('Слишком большой диапазон'); return; }
        loadBtn.disabled = true;
        clearCharts();
        chartsEl.innerHTML = '<div class="card muted" style="display:flex; align-items:center; justify-content:center; min-height: 120px;">Загрузка…</div>';
        try {
            const counts = {};
            for (let dow = 1; dow <= 7; dow++) {
                counts[String(dow)] = {};
                hours.forEach((h) => { counts[String(dow)][String(h)] = 0; });
            }

            const errors = [];
            let done = 0;
            setProgress(0, dates.length, 'Подготовка…');

            const concurrency = 8;
            let idx = 0;

            const runOne = async (date) => {
                const url = new URL(location.href);
                url.searchParams.set('ajax', 'day');
                url.searchParams.set('date', date);
                const res = await fetch(url.toString(), { headers: { 'Accept': 'application/json' } });
                const txt = await res.text();
                let j = null;
                try { j = JSON.parse(txt); } catch (_) {}
                if (!j || !j.ok) throw new Error((j && j.error) ? j.error : ('Ошибка (' + String(res.status) + ')'));
                const dow = String(j.dow || '');
                const byHour = j.counts_by_hour || {};
                if (!counts[dow]) return;
                hours.forEach((h) => {
                    const hk = String(h);
                    const v = Number(byHour[hk] || 0) || 0;
                    counts[dow][hk] += v;
                });
            };

            const workers = Array.from({ length: concurrency }, async () => {
                while (true) {
                    const my = idx;
                    idx += 1;
                    if (my >= dates.length) break;
                    const date = dates[my];
                    setProgress(done, dates.length, 'Запросы Poster: ' + String(done) + '/' + String(dates.length));
                    try {
                        await runOne(date);
                    } catch (e) {
                        errors.push({ date, error: String(e && e.message ? e.message : e) });
                    } finally {
                        done += 1;
                        setProgress(done, dates.length, 'Запросы Poster: ' + String(done) + '/' + String(dates.length));
                    }
                }
            });

            await Promise.all(workers);
            hideProgress();
            lastData = { counts_by_dow: counts };
            render(lastData);
            if (errors.length) {
                const head = errors.slice(0, 3).map((x) => x.date + ': ' + x.error).join('\n');
                alert('Ошибки загрузки: ' + String(errors.length) + '\n' + head);
            }
        } catch (e) {
            hideProgress();
            alert(e && e.message ? e.message : 'Ошибка');
        } finally {
            loadBtn.disabled = false;
        }
    };

    loadBtn.addEventListener('click', load);
})();
</script>
</body>
</html>
