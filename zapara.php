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

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
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
    <script src="assets/user_menu.js" defer></script>
    <style>
        body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; margin: 0; background: #0b0f16; color: rgba(255,250,244,0.92); }
        .wrap { max-width: 1450px; margin: 0 auto; padding: 16px; }
        .top { display:flex; align-items:flex-end; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
        h1 { margin: 0; font-size: 20px; }
        .muted { color: rgba(245,238,228,0.62); font-size: 12px; }
        .controls { display:flex; gap: 10px; align-items:center; flex-wrap: wrap; }
        .card { background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.12); border-radius: 14px; padding: 12px; box-shadow: 0 6px 18px rgba(0,0,0,0.35); }
        .grid { display:grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; margin-top: 12px; }
        .chart { height: 160px; position: relative; }
        .chart canvas { width: 100%; height: 100%; display:block; }
        .row { display:flex; justify-content: space-between; align-items: baseline; gap: 10px; }
        .pill { display:inline-flex; align-items:center; gap:6px; font-size:12px; padding: 4px 8px; border-radius: 999px; border:1px solid rgba(255,255,255,0.12); background: rgba(255,255,255,0.06); }
        .btn { border: 1px solid rgba(255,255,255,0.18); background: rgba(255,255,255,0.10); color: rgba(255,250,244,0.92); border-radius: 10px; padding: 8px 10px; cursor: pointer; font-weight: 800; }
        .btn:disabled { opacity: 0.5; cursor: default; }
        input[type="date"] { border: 1px solid rgba(255,255,255,0.18); background: rgba(255,255,255,0.08); color: rgba(255,250,244,0.92); border-radius: 10px; padding: 7px 10px; }
        @media (max-width: 980px) { .grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<div class="wrap">
    <div class="top">
        <div>
            <h1>Zapara</h1>
            <div class="muted">Источник: Poster (dash.getTransactions), группировка по дню недели и часу открытия чека</div>
        </div>
        <div class="controls">
            <div class="card" style="display:flex; gap: 10px; align-items:center; padding: 10px 12px;">
                <span class="muted">С</span>
                <input type="date" id="dateFrom" value="<?= htmlspecialchars($defaultFrom) ?>">
                <span class="muted">По</span>
                <input type="date" id="dateTo" value="<?= htmlspecialchars($defaultTo) ?>">
                <button class="btn" id="loadBtn">Загрузить</button>
            </div>
            <?php require __DIR__ . '/partials/user_menu.php'; ?>
        </div>
    </div>

    <div class="grid" id="charts"><div class="card muted" style="display:flex; align-items:center; justify-content:center; min-height: 120px;">Выбери период и нажми «Загрузить»</div></div>
</div>

<script>
(() => {
    const chartsEl = document.getElementById('charts');
    const dateFromEl = document.getElementById('dateFrom');
    const dateToEl = document.getElementById('dateTo');
    const loadBtn = document.getElementById('loadBtn');

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

    const hours = [];
    for (let hh = 9; hh <= 23; hh++) hours.push(hh);

    const render = (data) => {
        clearCharts();
        const counts = (data && data.counts_by_dow) ? data.counts_by_dow : {};
        dows.forEach((d) => {
            const { wrap, canvas } = makeCanvasCard(d.name);
            chartsEl.appendChild(wrap);
            drawBars(canvas, hours, counts[d.key] || {});
        });
    };

    const load = async () => {
        const df = String(dateFromEl.value || '').trim();
        const dt = String(dateToEl.value || '').trim();
        if (!df || !dt) return;
        loadBtn.disabled = true;
        try {
            const url = new URL(location.href);
            url.searchParams.set('ajax', 'data');
            url.searchParams.set('date_from', df);
            url.searchParams.set('date_to', dt);
            const res = await fetch(url.toString(), { headers: { 'Accept': 'application/json' } });
            const j = await res.json().catch(() => null);
            if (!j || !j.ok) throw new Error((j && j.error) ? j.error : 'Ошибка');
            render(j);
        } catch (e) {
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
