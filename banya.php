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
        $items = [];

        $totalChecks = 0;
        $totalSumMinor = 0;
        $hookahSumMinor = 0;

        do {
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
                $nextTr = is_array($last) ? ($last['transaction_id'] ?? null) : null;
            }

            foreach ($batch as $tx) {
                if (!is_array($tx)) continue;
                $tableId = (int)($tx['table_id'] ?? 0);
                $spotId = (int)($tx['spot_id'] ?? 0);
                $hallId = isset($tx['hall_id']) ? (int)$tx['hall_id'] : 0;
                $tableNameForFilter = (string)($tx['table_name'] ?? '');
                $isNameBanya = stripos($tableNameForFilter, 'banya') !== false;
                if ($spotId !== BANYA_HALL_ID && $hallId !== BANYA_HALL_ID && !$isNameBanya) continue;

                $products = is_array($tx['products'] ?? null) ? $tx['products'] : [];
                $detailRows = [];
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

                    $detailRows[] = [
                        'name' => $name,
                        'qty' => $num,
                        'price' => $fmtVnd($priceMinor),
                        'sum' => $fmtVnd($lineMinor),
                        'is_hookah' => $isHookah,
                    ];
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
                    'table' => $tableName,
                    'receipt' => $receipt,
                    'sum' => $fmtVnd($sumMinor),
                    'sum_minor' => $sumMinor,
                    'hookah_sum_minor' => $hookahMinorInCheck,
                    'waiter' => $waiter,
                    'spot_id' => $spotId,
                    'hall_id' => $hallId,
                    'details' => $detailRows,
                ];

                $totalChecks++;
                $totalSumMinor += $sumMinor;
                $hookahSumMinor += $hookahMinorInCheck;
            }
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
        .modal { position: fixed; inset: 0; background: rgba(0,0,0,0.55); display:none; align-items:center; justify-content:center; padding: 14px; }
        .modal-inner { width: min(860px, 100%); max-height: min(78vh, 720px); overflow:auto; }
        .modal-title { font-weight: 900; font-size: 16px; }
        .detail-table td { padding: 8px 10px; }
        .hookah { color:#b91c1c; font-weight: 900; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <div class="row">
            <div style="min-width: 260px;">
                <h1>Отчет баня</h1>
                <div class="muted">ID зоны: <?= (int)BANYA_HALL_ID ?> (ищем и по spot_id, и по hall_id) · кальяны: категория <?= (int)HOOKAH_CATEGORY_ID ?></div>
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

<div class="modal" id="modal">
    <div class="card modal-inner">
        <div style="display:flex; justify-content: space-between; gap: 10px; align-items:center;">
            <div class="modal-title" id="modalTitle">Детали</div>
            <button type="button" class="secondary" id="modalClose">Закрыть</button>
        </div>
        <div class="muted" id="modalMeta" style="margin-top: 6px;"></div>
        <table class="detail-table" style="margin-top: 10px;">
            <thead>
                <tr>
                    <th>Блюдо</th>
                    <th style="width: 90px; text-align:right;">Кол‑во</th>
                    <th style="width: 140px; text-align:right;">Цена</th>
                    <th style="width: 140px; text-align:right;">Сумма</th>
                </tr>
            </thead>
            <tbody id="modalBody"></tbody>
        </table>
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

    const modal = document.getElementById('modal');
    const modalClose = document.getElementById('modalClose');
    const modalTitle = document.getElementById('modalTitle');
    const modalMeta = document.getElementById('modalMeta');
    const modalBody = document.getElementById('modalBody');

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

    const openDetails = (row) => {
        modalTitle.textContent = `Детали чека ${row.receipt || ''}`;
        modalMeta.textContent = `${row.date || ''} · стол ${row.table || ''} · официант ${row.waiter || ''} · сумма ${row.sum || ''}`;
        modalBody.innerHTML = '';
        (row.details || []).forEach((d) => {
            const tr = document.createElement('tr');
            const cls = d.is_hookah ? 'hookah' : '';
            tr.innerHTML = `
                <td class="${cls}">${esc(d.name || '')}</td>
                <td class="num">${esc(d.qty || '')}</td>
                <td class="num">${esc(d.price || '')}</td>
                <td class="num">${esc(d.sum || '')}</td>
            `;
            modalBody.appendChild(tr);
        });
        modal.style.display = 'flex';
    };

    modalClose.addEventListener('click', () => { modal.style.display = 'none'; });
    modal.addEventListener('click', (e) => { if (e.target === modal) modal.style.display = 'none'; });

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
                tr.innerHTML = `
                    <td>${esc(row.date || '')}</td>
                    <td>${esc(row.table || '')}</td>
                    <td>${esc(row.receipt || '')}</td>
                    <td>${esc(row.waiter || '')}</td>
                    <td class="num">${esc(row.sum || '')}</td>
                    <td><button type="button" class="secondary">Детали</button></td>
                `;
                tr.querySelector('button')?.addEventListener('click', () => openDetails(row));
                tbody.appendChild(tr);
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
