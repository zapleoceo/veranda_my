<?php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/src/classes/PosterAPI.php';

date_default_timezone_set('Asia/Ho_Chi_Minh');

if (!veranda_can('admin')) {
    veranda_require('roma');
}

$token = trim((string)($_ENV['POSTER_API_TOKEN'] ?? ''));

const ROMA_CATEGORY_ID = 47;
const ROMA_FACTOR = 0.65;

const NO_STORE_HEADERS = [
    'Cache-Control: no-store, no-cache, must-revalidate, max-age=0',
    'Pragma: no-cache',
];

$fmtCount = function ($v): string {
    $n = (float)$v;
    if (abs($n - round($n)) < 0.0001) return (string)((int)round($n));
    return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
};

$fmtMoney = function ($minor): string {
    $n = (float)$minor;
    $vnd = (int)round($n / 100);
    return number_format($vnd, 0, '.', ' ');
};

$parseDate = function (string $s): ?string {
    $t = trim($s);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $t)) return $t;
    return null;
};

if (($_GET['ajax'] ?? '') === 'load') {
    foreach (NO_STORE_HEADERS as $h) header($h);
    header('Content-Type: application/json; charset=utf-8');

    if ($token === '') {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'POSTER_API_TOKEN не задан в .env'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $dateFrom = $parseDate((string)($_GET['date_from'] ?? ''));
    $dateTo = $parseDate((string)($_GET['date_to'] ?? ''));
    if ($dateFrom === null || $dateTo === null || $dateFrom > $dateTo) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Некорректный период'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $poster = new \App\Classes\PosterAPI($token);
    try {
        $resp = $poster->request('dash.getProductsSales', [
            'date_from' => str_replace('-', '', $dateFrom),
            'date_to' => str_replace('-', '', $dateTo),
        ], 'GET');
        if (!is_array($resp)) $resp = [];

        $rows = [];
        $totalCount = 0.0;
        $totalSumMinor = 0.0;

        foreach ($resp as $r) {
            $catId = (int)($r['category_id'] ?? 0);
            if ($catId !== ROMA_CATEGORY_ID) continue;

            $name = trim((string)($r['product_name'] ?? ''));
            if ($name === '') continue;

            $count = (float)($r['count'] ?? 0);
            $sumMinor = (float)($r['product_sum'] ?? 0);

            if (!isset($rows[$name])) {
                $rows[$name] = ['product_name' => $name, 'count' => 0.0, 'sum_minor' => 0.0];
            }
            $rows[$name]['count'] += $count;
            $rows[$name]['sum_minor'] += $sumMinor;

            $totalCount += $count;
            $totalSumMinor += $sumMinor;
        }

        $items = array_values($rows);
        usort($items, function ($a, $b) {
            $sa = (float)($a['sum_minor'] ?? 0);
            $sb = (float)($b['sum_minor'] ?? 0);
            if ($sa === $sb) return strcmp((string)$a['product_name'], (string)$b['product_name']);
            return ($sa < $sb) ? 1 : -1;
        });

        $outItems = [];
        foreach ($items as $it) {
            $outItems[] = [
                'product_name' => (string)$it['product_name'],
                'count' => $fmtCount($it['count']),
                'sum' => $fmtMoney($it['sum_minor']),
                'sum_minor' => (int)round((float)$it['sum_minor']),
            ];
        }

        $romaMinor = $totalSumMinor * ROMA_FACTOR;

        echo json_encode([
            'ok' => true,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'category_id' => ROMA_CATEGORY_ID,
            'items' => $outItems,
            'totals' => [
                'count' => $fmtCount($totalCount),
                'sum' => $fmtMoney($totalSumMinor),
                'sum_minor' => (int)round($totalSumMinor),
            ],
            'roma' => [
                'factor' => ROMA_FACTOR,
                'sum' => $fmtMoney($romaMinor),
                'sum_minor' => (int)round($romaMinor),
            ],
        ], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

$today = date('Y-m-d');
$firstOfMonth = date('Y-m-01');

?><!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Roma</title>
    <script src="/assets/app.js" defer></script>
    <style>
        body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; margin: 0; background: #f5f5f5; color:#111827; }
        .wrap { max-width: 1120px; margin: 0 auto; padding: 16px; }
        .card { background: #fff; border: 1px solid #e5e7eb; border-radius: 14px; padding: 12px; box-shadow: 0 4px 10px rgba(0,0,0,0.04); }
        h1 { margin: 0; font-size: 20px; }
        .row { display:flex; gap: 10px; align-items:end; flex-wrap: wrap; }
        label { font-size: 12px; color:#6b7280; display:flex; flex-direction: column; gap: 6px; }
        input[type="date"] { padding: 8px 10px; border: 1px solid #d1d5db; border-radius: 10px; font-size: 14px; }
        button { padding: 10px 14px; border-radius: 10px; border: 1px solid #111827; background:#111827; color:#fff; font-weight: 800; cursor:pointer; }
        button:disabled { opacity: 0.6; cursor: default; }
        .muted { color:#6b7280; font-size: 12px; }
        .loader { display:none; align-items:center; gap: 10px; margin-left: 10px; }
        .spinner { width: 16px; height: 16px; border: 2px solid rgba(17,24,39,0.20); border-top-color: rgba(17,24,39,0.85); border-radius: 50%; animation: spin 0.8s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
        table { width:100%; border-collapse: collapse; margin-top: 12px; }
        th, td { padding: 10px; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
        th { text-align:left; font-size: 12px; letter-spacing: 0.06em; text-transform: uppercase; color:#6b7280; background:#f9fafb; }
        td.num { text-align:right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        tr.total td { font-weight: 900; background: rgba(26,115,232,0.06); }
        .romaTotal { margin-top: 12px; display:flex; justify-content: flex-end; }
        .romaBox { border: 1px solid rgba(46,125,50,0.35); background: rgba(46,125,50,0.08); padding: 10px 12px; border-radius: 12px; font-weight: 900; }
        .error { margin-top: 10px; color:#b91c1c; font-weight: 700; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <div class="row">
            <div style="min-width: 240px;">
                <h1>/roma — продажи кальянов (категория 47)</h1>
                <div class="muted">Источник: Poster dash.getProductsSales · без кэширования</div>
            </div>
            <label>
                Дата начала (date_from)
                <input type="date" id="dateFrom" value="<?= htmlspecialchars($firstOfMonth) ?>">
            </label>
            <label>
                Дата конца (date_to)
                <input type="date" id="dateTo" value="<?= htmlspecialchars($today) ?>">
            </label>
            <div style="display:flex; align-items:center;">
                <button id="loadBtn" type="button">ЗАГРУЗИТЬ</button>
                <div class="loader" id="loader"><span class="spinner"></span><span class="muted">Загрузка…</span></div>
            </div>
        </div>

        <div class="error" id="err" style="display:none;"></div>

        <table>
            <thead>
                <tr>
                    <th>Название кальяна</th>
                    <th style="width:140px; text-align:right;">Кол‑во</th>
                    <th style="width:180px; text-align:right;">Сальдо</th>
                </tr>
            </thead>
            <tbody id="tbody"></tbody>
            <tfoot id="tfoot"></tfoot>
        </table>

        <div class="romaTotal">
            <div class="romaBox">Итого роме: <span id="romaSum">0</span></div>
        </div>
    </div>
</div>

<script>
    const elFrom = document.getElementById('dateFrom');
    const elTo = document.getElementById('dateTo');
    const btn = document.getElementById('loadBtn');
    const loader = document.getElementById('loader');
    const err = document.getElementById('err');
    const tbody = document.getElementById('tbody');
    const tfoot = document.getElementById('tfoot');
    const romaSum = document.getElementById('romaSum');

    const setLoading = (on) => {
        btn.disabled = on;
        loader.style.display = on ? 'inline-flex' : 'none';
    };

    const setError = (msg) => {
        if (!msg) { err.style.display = 'none'; err.textContent = ''; return; }
        err.style.display = 'block';
        err.textContent = msg;
    };

    const load = async () => {
        setError('');
        setLoading(true);
        tbody.innerHTML = '';
        tfoot.innerHTML = '';
        romaSum.textContent = '0';
        try {
            const url = new URL(location.href);
            url.searchParams.set('ajax', 'load');
            url.searchParams.set('date_from', elFrom.value);
            url.searchParams.set('date_to', elTo.value);
            const res = await fetch(url.toString(), { headers: { 'Accept': 'application/json' } });
            const txt = await res.text();
            let j = null;
            try { j = JSON.parse(txt); } catch (_) {}
            if (!j || !j.ok) throw new Error((j && j.error) ? j.error : 'Ошибка загрузки');

            (j.items || []).forEach((it) => {
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td>${String(it.product_name || '')}</td>
                    <td class="num">${String(it.count || '0')}</td>
                    <td class="num">${String(it.sum || '0')}</td>
                `;
                tbody.appendChild(tr);
            });

            const trTot = document.createElement('tr');
            trTot.className = 'total';
            trTot.innerHTML = `
                <td>Итого</td>
                <td class="num">${String(j.totals?.count || '0')}</td>
                <td class="num">${String(j.totals?.sum || '0')}</td>
            `;
            tfoot.appendChild(trTot);
            romaSum.textContent = String(j.roma?.sum || '0');
        } catch (e) {
            setError(e && e.message ? e.message : 'Ошибка');
        } finally {
            setLoading(false);
        }
    };

    btn.addEventListener('click', () => load());
    load();
</script>
</body>
</html>
