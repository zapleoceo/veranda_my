<?php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/src/classes/PosterAPI.php';

date_default_timezone_set('Asia/Ho_Chi_Minh');

if (!veranda_can('admin')) {
    veranda_require('banya');
}

const BANYA_HALL_ID = 9;
const HOOKAH_CATEGORY_ID = 47;

$token = trim((string)($_ENV['POSTER_API_TOKEN'] ?? ''));

$parseDate = function (string $s): ?string {
    $t = trim($s);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $t) ? $t : null;
};

$fmtVnd = function ($minor): string {
    $vnd = (int)round(((float)$minor) / 100);
    return number_format($vnd, 0, '.', ' ');
};

$fmtTs = function (?int $ms): string {
    if (!$ms || $ms <= 0) return '';
    $dt = new DateTime('@' . (int)round($ms / 1000));
    $dt->setTimezone(new DateTimeZone('Asia/Ho_Chi_Minh'));
    return $dt->format('Y-m-d H:i:s');
};

$loadProductMap = function (\App\Classes\PosterAPI $api): array {
    $products = $api->request('menu.getProducts', []);
    if (!is_array($products)) $products = [];
    $map = [];
    foreach ($products as $p) {
        if (!is_array($p)) continue;
        $pid = (int)($p['product_id'] ?? 0);
        if ($pid <= 0) continue;
        $map[$pid] = [
            'name' => (string)($p['product_name'] ?? ''),
            'category_id' => (int)($p['category_id'] ?? $p['menu_category_id'] ?? $p['main_category_id'] ?? 0),
            'sub_category_id' => (int)($p['sub_category_id'] ?? $p['menu_category_id2'] ?? $p['category2_id'] ?? 0),
        ];
    }
    return $map;
};

const BANYA_TABLES_WITHOUT_DELETED = 1;

function banya_load_table_halls(\App\Classes\PosterAPI $api, int $spotId): array {
    if ($spotId <= 0) return [];
    $rows = $api->request('spots.getTableHallTables', [
        'spot_id' => $spotId,
        'without_deleted' => BANYA_TABLES_WITHOUT_DELETED,
    ], 'GET');
    if (!is_array($rows)) $rows = [];
    $map = [];
    foreach ($rows as $r) {
        if (!is_array($r)) continue;
        $tid = (int)($r['table_id'] ?? 0);
        $hid = (int)($r['hall_id'] ?? 0);
        if ($tid > 0 && $hid > 0) $map[$tid] = $hid;
    }
    return $map;
}

if (($_GET['ajax'] ?? '') === 'load') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    if ($token === '') {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'POSTER_API_TOKEN не задан'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $dateFrom = $parseDate((string)($_GET['date_from'] ?? ''));
    $dateTo = $parseDate((string)($_GET['date_to'] ?? ''));
    if ($dateFrom === null || $dateTo === null || $dateFrom > $dateTo) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Некорректный период'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $api = new \App\Classes\PosterAPI($token);
    try {
        $productMap = $loadProductMap($api);

        $nextTr = null;
        $prevNextTr = null;
        $page = 0;
        $items = [];
        $tableHallMapBySpot = [];

        $totalChecks = 0;
        $totalSumMinor = 0;
        $hookahSumMinor = 0;

        do {
            $page++;
            if ($page > 3000) break;
            $params = [
                'dateFrom' => str_replace('-', '', $dateFrom),
                'dateTo' => str_replace('-', '', $dateTo),
                'include_products' => 'true',
                'status' => 0,
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
                $tableId = (int)($tx['table_id'] ?? 0);
                $spotId = (int)($tx['spot_id'] ?? 0);
                $hallId = isset($tx['hall_id']) ? (int)$tx['hall_id'] : 0;

                if ($spotId > 0 && !isset($tableHallMapBySpot[$spotId])) {
                    $tableHallMapBySpot[$spotId] = banya_load_table_halls($api, $spotId);
                }
                $hallByTable = 0;
                if ($spotId > 0 && $tableId > 0 && isset($tableHallMapBySpot[$spotId][$tableId])) {
                    $hallByTable = (int)$tableHallMapBySpot[$spotId][$tableId];
                }
                $hall = $hallByTable > 0 ? (string)$hallByTable : ($hallId > 0 ? (string)$hallId : '');

                $products = is_array($tx['products'] ?? null) ? $tx['products'] : [];
                $hookahMinorInCheck = 0;

                foreach ($products as $p) {
                    if (!is_array($p)) continue;
                    $pid = (int)($p['product_id'] ?? 0);
                    $numRaw = $p['num'] ?? $p['count'] ?? 0;
                    $num = is_numeric($numRaw) ? (float)$numRaw : 0;
                    $priceMinor = (int)($p['product_price'] ?? 0);
                    $lineMinor = (int)round($priceMinor * $num);
                    $name = (string)($productMap[$pid]['name'] ?? $p['product_name'] ?? ('#' . $pid));
                    $cat = (int)($productMap[$pid]['category_id'] ?? 0);
                    $sub = (int)($productMap[$pid]['sub_category_id'] ?? 0);
                    $isHookah = ($cat === HOOKAH_CATEGORY_ID || $sub === HOOKAH_CATEGORY_ID);
                    if ($isHookah) $hookahMinorInCheck += $lineMinor;
                }

                $sumMinor = (int)($tx['payed_sum'] ?? $tx['sum'] ?? 0);
                $dateCloseStr = (string)($tx['date_close_date'] ?? '');
                $dateStr = $dateCloseStr !== '' ? $dateCloseStr : $fmtTs(isset($tx['date_start']) ? (int)$tx['date_start'] : 0);
                if ($dateStr === '') $dateStr = '';

                $receipt = (string)($tx['receipt_number'] ?? $tx['transaction_id'] ?? '');
                $tableName = (string)($tx['table_name'] ?? $tx['table_id'] ?? '');
                $waiter = (string)($tx['name'] ?? $tx['employee_name'] ?? '');

                $items[] = [
                    'date' => $dateStr,
                    'hall' => $hall,
                    'spot_id' => $spotId,
                    'table_id' => $tableId,
                    'table' => $tableName,
                    'receipt' => $receipt,
                    'sum' => $fmtVnd($sumMinor),
                    'sum_minor' => $sumMinor,
                    'hookah_sum_minor' => $hookahMinorInCheck,
                    'waiter' => $waiter,
                    'hall_id' => $hallId,
                    'transaction_id' => (int)($tx['transaction_id'] ?? 0),
                ];

                $totalChecks++;
                $totalSumMinor += $sumMinor;
                $hookahSumMinor += $hookahMinorInCheck;
            }
            if ($nextTr !== null && $prevNextTr !== null && (string)$nextTr === (string)$prevNextTr) break;
        } while ($count > 0 && $nextTr !== null);

        usort($items, function ($a, $b) {
            return strcmp((string)$a['date'], (string)$b['date']);
        });

        $out = [
            'ok' => true,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'hall_id' => BANYA_HALL_ID,
            'items' => $items,
            'totals' => [
                'checks' => (int)$totalChecks,
                'sum' => $fmtVnd($totalSumMinor),
                'hookah_sum' => $fmtVnd($hookahSumMinor),
                'without_hookah_sum' => $fmtVnd($totalSumMinor - $hookahSumMinor),
            ]
        ];
        echo json_encode($out, JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if (($_GET['ajax'] ?? '') === 'tx') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    if ($token === '') {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'POSTER_API_TOKEN не задан'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $trId = (int)($_GET['transaction_id'] ?? 0);
    if ($trId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Bad request'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $api = new \App\Classes\PosterAPI($token);
    try {
        $productMap = $loadProductMap($api);
        $txArr = $api->request('dash.getTransaction', [
            'transaction_id' => $trId,
            'include_products' => 1,
            'include_history' => 0,
            'include_delivery' => 0,
        ], 'GET');
        $tx = is_array($txArr) && isset($txArr[0]) && is_array($txArr[0]) ? $txArr[0] : (is_array($txArr) ? $txArr : []);
        $products = is_array($tx['products'] ?? null) ? $tx['products'] : [];
        $lines = [];
        foreach ($products as $p) {
            if (!is_array($p)) continue;
            $pid = (int)($p['product_id'] ?? 0);
            $numRaw = $p['num'] ?? $p['count'] ?? 0;
            $num = is_numeric($numRaw) ? (float)$numRaw : 0;
            $priceMinor = (int)($p['product_price'] ?? 0);
            $lineMinor = (int)round($priceMinor * $num);
            $name = (string)($productMap[$pid]['name'] ?? ('#' . $pid));
            $lines[] = [
                'name' => $name,
                'sum' => $fmtVnd($lineMinor),
                'sum_minor' => $lineMinor,
            ];
        }
        echo json_encode(['ok' => true, 'transaction_id' => $trId, 'lines' => $lines], JSON_UNESCAPED_UNICODE);
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
    <title>Отчет баня</title>
    <style>
        body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; margin: 0; background: #f5f5f5; color:#111827; }
        .wrap { max-width: 1200px; margin: 0 auto; padding: 16px; }
        .card { background: #fff; border: 1px solid #e5e7eb; border-radius: 14px; padding: 12px; box-shadow: 0 4px 10px rgba(0,0,0,0.04); }
        h1 { margin: 0; font-size: 20px; }
        .muted { color:#6b7280; font-size: 12px; }
        .row { display:flex; gap: 10px; align-items:end; flex-wrap: wrap; }
        label { font-size: 12px; color:#6b7280; display:flex; flex-direction: column; gap: 6px; }
        input[type="date"] { padding: 8px 10px; border: 1px solid #d1d5db; border-radius: 10px; font-size: 14px; }
        button { padding: 10px 14px; border-radius: 10px; border: 1px solid #111827; background:#111827; color:#fff; font-weight: 800; cursor:pointer; }
        button.secondary { background:#fff; color:#111827; }
        button:disabled { opacity: 0.6; cursor: default; }
        .loader { display:none; align-items:center; gap: 10px; margin-left: 10px; }
        .spinner { width: 16px; height: 16px; border: 2px solid rgba(17,24,39,0.20); border-top-color: rgba(17,24,39,0.85); border-radius: 50%; animation: spin 0.8s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
        table { width:100%; border-collapse: collapse; margin-top: 12px; }
        th, td { padding: 10px; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
        th { text-align:left; font-size: 12px; letter-spacing: 0.06em; text-transform: uppercase; color:#6b7280; background:#f9fafb; }
        td.num { text-align:right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .totals { margin-top: 12px; display:flex; gap: 12px; flex-wrap: wrap; justify-content: flex-end; }
        .pill { border: 1px solid #e5e7eb; border-radius: 12px; padding: 10px 12px; background:#fff; font-weight: 900; }
        .pill.bad { border-color: rgba(211,47,47,0.35); background: rgba(211,47,47,0.08); }
        .pill.ok { border-color: rgba(46,125,50,0.35); background: rgba(46,125,50,0.08); }
        .error { margin-top: 10px; color:#b91c1c; font-weight: 700; }
        .details-row td { background: rgba(17,24,39,0.02); }
        .details-box { padding: 10px 12px; border: 1px solid #e5e7eb; border-radius: 12px; background: #fff; }
        .detail-line { display:flex; justify-content: space-between; gap: 10px; padding: 6px 0; border-bottom: 1px dashed rgba(17,24,39,0.10); }
        .detail-line:last-child { border-bottom: 0; }
        .detail-sum { font-variant-numeric: tabular-nums; white-space: nowrap; font-weight: 900; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <div class="row">
            <div style="min-width: 260px;">
                <h1>Отчет баня</h1>
                <div class="muted">Фильтры: выключены · кальяны: категория <?= (int)HOOKAH_CATEGORY_ID ?></div>
            </div>
            <label>
                Дата начала
                <input type="date" id="dateFrom" value="<?= htmlspecialchars($firstOfMonth) ?>">
            </label>
            <label>
                Дата конца
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
                    <th style="width:170px;">Дата</th>
                    <th style="width:80px;">Hall</th>
                    <th style="width:120px;">Стол</th>
                    <th style="width:120px;">Чек</th>
                    <th>Официант</th>
                    <th style="width:140px; text-align:right;">Сумма</th>
                    <th style="width:120px;"></th>
                </tr>
            </thead>
            <tbody id="tbody"></tbody>
        </table>

        <div class="totals">
            <div class="pill" id="totChecks">Итого чеков: 0</div>
            <div class="pill ok" id="totSum">Итого сумма: 0</div>
            <div class="pill bad" id="totHookah">Сумма кальянов: 0</div>
            <div class="pill ok" id="totWithout">Сумма без кальянов: 0</div>
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
    const totChecks = document.getElementById('totChecks');
    const totSum = document.getElementById('totSum');
    const totHookah = document.getElementById('totHookah');
    const totWithout = document.getElementById('totWithout');

    const setLoading = (on) => {
        btn.disabled = on;
        loader.style.display = on ? 'inline-flex' : 'none';
    };

    const setError = (msg) => {
        if (!msg) { err.style.display = 'none'; err.textContent = ''; return; }
        err.style.display = 'block';
        err.textContent = msg;
    };

    const esc = (s) => String(s || '').replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

    const loadDetails = async (transactionId) => {
        const url = new URL(location.href);
        url.searchParams.set('ajax', 'tx');
        url.searchParams.set('transaction_id', String(transactionId || ''));
        const res = await fetch(url.toString(), { headers: { 'Accept': 'application/json' } });
        const txt = await res.text();
        let j = null;
        try { j = JSON.parse(txt); } catch (_) {}
        if (!j || !j.ok) throw new Error((j && j.error) ? j.error : 'Ошибка деталей');
        return j.lines || [];
    };

    const load = async () => {
        setError('');
        setLoading(true);
        tbody.innerHTML = '';
        totChecks.textContent = 'Итого чеков: 0';
        totSum.textContent = 'Итого сумма: 0';
        totHookah.textContent = 'Сумма кальянов: 0';
        totWithout.textContent = 'Сумма без кальянов: 0';
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

            (j.items || []).forEach((row) => {
                const tr = document.createElement('tr');
                const txId = Number(row.transaction_id || row.receipt || 0);
                tr.innerHTML = `
                    <td>${esc(row.date || '')}</td>
                    <td>${esc(row.hall || '')}</td>
                    <td>${esc(row.table || '')}</td>
                    <td>${esc(row.receipt || '')}</td>
                    <td>${esc(row.waiter || '')}</td>
                    <td class="num">${esc(row.sum || '')}</td>
                    <td><button type="button" class="secondary" data-tx="${esc(txId)}">Детали</button></td>
                `;
                tbody.appendChild(tr);

                const trD = document.createElement('tr');
                trD.className = 'details-row';
                trD.style.display = 'none';
                trD.innerHTML = `<td colspan="7"><div class="details-box muted">Загрузка…</div></td>`;
                tbody.appendChild(trD);

                const btnDetails = tr.querySelector('button');
                if (btnDetails) {
                    btnDetails.addEventListener('click', async () => {
                        const isOpen = trD.style.display !== 'none';
                        if (isOpen) {
                            trD.style.display = 'none';
                            return;
                        }
                        trD.style.display = '';
                        const tx = Number(btnDetails.getAttribute('data-tx') || 0);
                        try {
                            const lines = await loadDetails(tx);
                            const box = document.createElement('div');
                            box.className = 'details-box';
                            if (!lines.length) {
                                box.innerHTML = `<div class="muted">Нет данных</div>`;
                            } else {
                                lines.forEach((ln) => {
                                    const line = document.createElement('div');
                                    line.className = 'detail-line';
                                    line.innerHTML = `<div>${esc(ln.name || '')}</div><div class="detail-sum">${esc(ln.sum || '0')}</div>`;
                                    box.appendChild(line);
                                });
                            }
                            trD.firstChild.replaceWith(box);
                        } catch (e) {
                            trD.innerHTML = `<td colspan="7"><div class="details-box" style="color:#b91c1c; font-weight:700;">${esc(e && e.message ? e.message : 'Ошибка')}</div></td>`;
                        }
                    });
                }
            });

            totChecks.textContent = `Итого чеков: ${String(j.totals?.checks || 0)}`;
            totSum.textContent = `Итого сумма: ${String(j.totals?.sum || '0')}`;
            totHookah.textContent = `Сумма кальянов: ${String(j.totals?.hookah_sum || '0')}`;
            totWithout.textContent = `Сумма без кальянов: ${String(j.totals?.without_hookah_sum || '0')}`;
        } catch (e) {
            setError(e && e.message ? e.message : 'Ошибка');
        } finally {
            setLoading(false);
        }
    };

    btn.addEventListener('click', load);
    load();
</script>
</body>
</html>
