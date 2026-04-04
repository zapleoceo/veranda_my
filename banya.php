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

function banya_load_spot_ids(\App\Classes\PosterAPI $api): array {
    $rows = $api->request('access.getSpots', [], 'GET');
    if (!is_array($rows)) $rows = [];
    $ids = [];
    foreach ($rows as $r) {
        if (!is_array($r)) continue;
        $sid = (int)($r['spot_id'] ?? $r['id'] ?? 0);
        if ($sid > 0) $ids[] = $sid;
    }
    $ids = array_values(array_unique($ids));
    sort($ids);
    return $ids;
}

function banya_load_tables_for_hall(\App\Classes\PosterAPI $api, int $spotId, int $hallId): array {
    if ($spotId <= 0 || $hallId <= 0) return [];
    $rows = $api->request('spots.getTableHallTables', [
        'spot_id' => $spotId,
        'hall_id' => $hallId,
        'without_deleted' => BANYA_TABLES_WITHOUT_DELETED,
    ], 'GET');
    if (!is_array($rows)) $rows = [];
    $out = [];
    foreach ($rows as $r) {
        if (!is_array($r)) continue;
        $tid = (int)($r['table_id'] ?? 0);
        if ($tid <= 0) continue;
        $out[] = [
            'table_id' => $tid,
            'table_num' => (string)($r['table_num'] ?? ''),
            'table_title' => (string)($r['table_title'] ?? ''),
        ];
    }
    return $out;
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
        $items = [];
        $seenTx = [];

        $totalChecks = 0;
        $totalSumMinor = 0;
        $hookahSumMinor = 0;

        $spotIds = banya_load_spot_ids($api);
        if (!$spotIds) $spotIds = [1];

        $hallTables = [];
        foreach ($spotIds as $sid) {
            $rows = banya_load_tables_for_hall($api, (int)$sid, BANYA_HALL_ID);
            foreach ($rows as $r) $hallTables[] = ['spot_id' => (int)$sid] + $r;
        }
        $extraTables = [];
        $extraById = [];
        foreach ($spotIds as $sid) {
            $all = $api->request('spots.getTableHallTables', [
                'spot_id' => (int)$sid,
                'without_deleted' => BANYA_TABLES_WITHOUT_DELETED,
            ], 'GET');
            if (!is_array($all)) $all = [];
            foreach ($all as $r) {
                if (!is_array($r)) continue;
                $tid = (int)($r['table_id'] ?? 0);
                if ($tid <= 0) continue;
                $num = trim((string)($r['table_num'] ?? ''));
                $title = trim((string)($r['table_title'] ?? ''));
                $hay = $num . ' ' . $title;
                if (!preg_match('/\b141\b/u', $hay)) continue;
                if (isset($extraById[$tid])) continue;
                $extraById[$tid] = true;
                $extraTables[] = [
                    'spot_id' => (int)$sid,
                    'table_id' => $tid,
                    'table_num' => $num,
                    'table_title' => $title,
                ];
            }
        }
        if ($extraTables) {
            $seen = [];
            foreach ($hallTables as $t) $seen[(int)($t['table_id'] ?? 0)] = true;
            foreach ($extraTables as $t) {
                $tid = (int)($t['table_id'] ?? 0);
                if ($tid <= 0 || isset($seen[$tid])) continue;
                $hallTables[] = $t;
            }
        }

        foreach ($hallTables as $t) {
            $spotId = (int)($t['spot_id'] ?? 0);
            $tableId = (int)($t['table_id'] ?? 0);
            if ($spotId <= 0 || $tableId <= 0) continue;

            $nextTr = null;
            $prevNextTr = null;
            $guard = 0;
            do {
                $guard++;
                if ($guard > 2000) break;
                $params = [
                    'dateFrom' => str_replace('-', '', $dateFrom),
                    'dateTo' => str_replace('-', '', $dateTo),
                    'include_products' => 'true',
                    'status' => 0,
                    'table_id' => $tableId,
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
                    $txId = (int)($tx['transaction_id'] ?? 0);
                    if ($txId <= 0) continue;
                    if (isset($seenTx[$txId])) continue;
                    $seenTx[$txId] = true;

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
                    $spotIdRow = (int)($tx['spot_id'] ?? $spotId);
                    $tableIdRow = (int)($tx['table_id'] ?? $tableId);
                    $tableName = (string)($tx['table_name'] ?? $t['table_title'] ?? $t['table_num'] ?? $tableIdRow);
                    $waiter = (string)($tx['name'] ?? $tx['employee_name'] ?? '');

                    if ($sumMinor <= 0) {
                        continue;
                    }
                    $items[] = [
                        'date' => $dateStr,
                        'hall' => (string)BANYA_HALL_ID,
                        'spot_id' => $spotIdRow,
                        'table_id' => $tableIdRow,
                        'table' => $tableName,
                        'receipt' => $receipt,
                        'sum' => $fmtVnd($sumMinor),
                        'sum_minor' => $sumMinor,
                        'hookah_sum_minor' => $hookahMinorInCheck,
                        'waiter' => $waiter,
                        'transaction_id' => $txId,
                    ];

                    $totalChecks++;
                    $totalSumMinor += $sumMinor;
                    $hookahSumMinor += $hookahMinorInCheck;
                }
                if ($nextTr !== null && $prevNextTr !== null && (string)$nextTr === (string)$prevNextTr) break;
            } while ($count > 0 && $nextTr !== null);
        }

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
            'include_products' => 'true',
            'include_history' => 'false',
            'include_delivery' => 'false',
        ], 'GET');
        $tx = is_array($txArr) && isset($txArr[0]) && is_array($txArr[0]) ? $txArr[0] : (is_array($txArr) ? $txArr : []);
        $products = is_array($tx['products'] ?? null) ? $tx['products'] : [];
        $lines = [];
        foreach ($products as $p) {
            if (!is_array($p)) continue;
            $pid = (int)($p['product_id'] ?? 0);
            $numRaw = $p['num'] ?? $p['count'] ?? 0;
            $num = is_numeric($numRaw) ? (float)$numRaw : 0;
            $lineMinor = isset($p['payed_sum']) ? (int)$p['payed_sum'] : (int)($p['product_sum'] ?? 0);
            if ($lineMinor <= 0) {
                $lineMinor = (int)($p['product_price'] ?? 0);
            }
            $name = (string)($productMap[$pid]['name'] ?? ('#' . $pid));
            $lines[] = [
                'name' => $name,
                'qty' => $num,
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
        :root { --brand-text: #b65930; --brand-bg: #efdbce; }
        body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; margin: 0; background: #fbf4ef; color:#1f2937; }
        .wrap { max-width: 1200px; margin: 0 auto; padding: 16px; }
        .card { background: #fff; border: 1px solid rgba(182,89,48,0.20); border-radius: 14px; padding: 12px; box-shadow: 0 6px 16px rgba(182,89,48,0.10); }
        h1 { margin: 0; font-size: 20px; }
        .muted { color:#6b7280; font-size: 12px; }
        .row { display:flex; gap: 10px; align-items:end; flex-wrap: wrap; }
        label { font-size: 12px; color:#6b7280; display:flex; flex-direction: column; gap: 6px; }
        input[type="date"], select { padding: 7px 10px; border: 1px solid rgba(182,89,48,0.25); border-radius: 10px; font-size: 14px; background:#fff; color:#1f2937; }
        button { padding: 8px 14px; border-radius: 10px; border: 1px solid rgba(182,89,48,0.35); background: var(--brand-bg); color: var(--brand-text); font-weight: 900; cursor:pointer; }
        button.secondary { background:#fff; color: var(--brand-text); border-color: rgba(182,89,48,0.25); }
        button.small { padding: 5px 10px; border-radius: 9px; font-weight: 900; }
        button:disabled { opacity: 0.6; cursor: default; }
        .loader { display:none; align-items:center; gap: 10px; margin-left: 10px; }
        .spinner { width: 16px; height: 16px; border: 2px solid rgba(182,89,48,0.25); border-top-color: rgba(182,89,48,0.95); border-radius: 50%; animation: spin 0.8s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
        table { width:100%; border-collapse: collapse; margin-top: 12px; }
        th, td { padding: 7px 10px; border-bottom: 1px solid rgba(182,89,48,0.14); vertical-align: top; }
        th { text-align:left; font-size: 12px; letter-spacing: 0.06em; text-transform: uppercase; color: var(--brand-text); background: rgba(239,219,206,0.55); }
        td.num { text-align:right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .totals { margin-top: 12px; display:flex; gap: 12px; flex-wrap: wrap; justify-content: flex-end; }
        .pill { border: 1px solid rgba(182,89,48,0.22); border-radius: 12px; padding: 10px 12px; background:#fff; font-weight: 900; }
        .pill.bad { border-color: rgba(182,89,48,0.40); background: rgba(239,219,206,0.55); color: var(--brand-text); }
        .pill.ok { border-color: rgba(182,89,48,0.22); background: rgba(239,219,206,0.25); color: #1f2937; }
        .error { margin-top: 10px; color:#b91c1c; font-weight: 700; }
        .details-row td { background: rgba(17,24,39,0.02); }
        .details-box { padding: 10px 12px; border: 1px solid rgba(182,89,48,0.18); border-radius: 12px; background: #fff; }
        .detail-line { display:flex; justify-content: space-between; gap: 10px; padding: 6px 0; border-bottom: 1px dashed rgba(17,24,39,0.10); }
        .detail-line:last-child { border-bottom: 0; }
        .detail-sum { font-variant-numeric: tabular-nums; white-space: nowrap; font-weight: 900; }
        .pager { display:flex; align-items:center; gap: 6px; flex-wrap: wrap; }
        .page-btn { border: 1px solid rgba(182,89,48,0.25); background: #fff; color: var(--brand-text); padding: 4px 9px; border-radius: 10px; font-weight: 900; cursor: pointer; }
        .page-btn.active { background: var(--brand-bg); border-color: rgba(182,89,48,0.35); }
        .page-dots { color: rgba(182,89,48,0.75); padding: 0 4px; font-weight: 900; }
        .hookah-ico { width: 18px; height: 18px; vertical-align: middle; margin-left: 8px; }

        .user-menu { position: relative; }
        .user-chip { display: inline-flex; align-items: center; gap: 8px; padding: 6px 10px; border: 1px solid rgba(182,89,48,0.22); border-radius: 999px; background: #fff; color: #1f2937; font-weight: 900; cursor: default; }
        .user-icon { width: 22px; height: 22px; border-radius: 50%; background: rgba(239,219,206,0.75); display: inline-flex; align-items: center; justify-content: center; color: var(--brand-text); font-weight: 900; font-size: 12px; overflow: hidden; }
        .user-icon img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .user-dropdown { position: absolute; right: 0; top: calc(100% + 8px); background: #fff; border: 1px solid rgba(182,89,48,0.22); border-radius: 12px; box-shadow: 0 10px 24px rgba(182,89,48,0.18); padding: 8px; min-width: 220px; display: none; z-index: 1000; }
        .user-menu.open .user-dropdown { display: block; }
        .user-dropdown a { display: block; padding: 8px 10px; border-radius: 10px; color: #1f2937; text-decoration: none; font-weight: 900; }
        .user-dropdown a:hover { background: rgba(239,219,206,0.55); }
        .user-dropdown .ud-title { padding: 6px 10px 4px; color: rgba(107,114,128,0.9); font-size: 11px; font-weight: 900; text-transform: uppercase; letter-spacing: 0.06em; }
        .user-dropdown .ud-subtitle { padding: 6px 10px 4px; color: var(--brand-text); font-size: 11px; font-weight: 900; text-transform: uppercase; letter-spacing: 0.06em; }
        .user-dropdown .ud-link.ud-l1 { padding-left: 18px; }
        .user-dropdown .ud-link.ud-l2 { padding-left: 32px; }
        .user-dropdown .ud-sep { height: 1px; background: rgba(182,89,48,0.14); margin: 6px 8px; border-radius: 999px; }
        .user-dropdown .ud-details { margin: 2px 0; }
        .user-dropdown .ud-summary { list-style: none; padding: 8px 10px; border-radius: 10px; color: #1f2937; font-weight: 900; cursor: pointer; user-select: none; }
        .user-dropdown .ud-summary::-webkit-details-marker { display: none; }
        .user-dropdown details[open] .ud-summary { background: rgba(239,219,206,0.55); }
        .user-dropdown .ud-summary::after { content: "›"; float: right; color: rgba(182,89,48,0.65); font-weight: 900; }
        .user-dropdown details[open] .ud-summary::after { content: "⌄"; }
    </style>
</head>
<body>
<div class="container" style="max-width: 1200px; margin: 0 auto; padding: 0 16px;">
    <div class="top-nav" style="display:flex; justify-content: space-between; align-items:center; gap: 16px; flex-wrap: wrap; padding: 12px 0;">
        <div class="nav-left" style="display:flex; gap: 14px; align-items:center; flex-wrap: wrap;">
            <div class="nav-title" style="font-weight: 800; color: var(--brand-text);">Отчет баня</div>
        </div>
        <div class="nav-mid"></div>
        <?php require __DIR__ . '/partials/user_menu.php'; ?>
    </div>
</div>
<div class="wrap">
    <div class="card">
        <div class="row">
            <div style="min-width: 260px;">
                <h1>Отчет баня</h1>
            </div>
            <label>
                Дата начала
                <input type="date" id="dateFrom" value="<?= htmlspecialchars($firstOfMonth) ?>">
            </label>
            <label>
                Дата конца
                <input type="date" id="dateTo" value="<?= htmlspecialchars($today) ?>">
            </label>
            <div style="display:flex; align-items:center; gap:10px; flex-wrap: wrap;">
                <button id="loadBtn" type="button">ЗАГРУЗИТЬ</button>
                <div class="loader" id="loader"><span class="spinner"></span><span class="muted">Загрузка…</span></div>
                <label class="muted" style="flex-direction: row; align-items:center; gap: 8px;">
                    <input type="checkbox" id="noPages">
                    без страниц
                </label>
                <div class="pager" id="pagerTop"></div>
            </div>
        </div>
        <div class="error" id="err" style="display:none;"></div>

        <table>
            <thead>
                <tr>
                    <th id="thDate" data-sort="date" style="width:170px; cursor:pointer;">Дата</th>
                    <th id="thHall" data-sort="hall" style="width:80px; cursor:pointer;">Hall</th>
                    <th id="thTable" data-sort="table" style="width:120px; cursor:pointer;">Стол</th>
                    <th id="thReceipt" data-sort="receipt" style="width:120px; cursor:pointer;">Чек</th>
                    <th id="thWaiter" data-sort="waiter" style="cursor:pointer;">Официант</th>
                    <th id="thSum" data-sort="sum_minor" style="width:140px; text-align:right; cursor:pointer;">Сумма</th>
                    <th style="width:120px;"></th>
                </tr>
            </thead>
            <tbody id="tbody"></tbody>
        </table>
        <div style="display:flex; justify-content:flex-end; margin-top: 10px;">
            <div class="pager" id="pagerBottom"></div>
        </div>

        <div class="totals">
            <div class="pill" id="totChecks">Итого чеков: 0</div>
            <div class="pill ok" id="totSum">Итого сумма: 0</div>
            <div class="pill bad" id="totHookah">Сумма кальянов: 0</div>
            <div class="pill ok" id="totWithout">Сумма без кальянов: 0</div>
        </div>
        <div class="muted" style="margin-top: 8px; text-align:right;">Включены только столы Бани · кальяны: категория <?= (int)HOOKAH_CATEGORY_ID ?></div>
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
    const hookahSvg = '<svg class="hookah-ico" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M11 2h2l-1 3h2l-2 5" stroke="#b65930" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M9.2 10.8c1-.9 4.6-.9 5.6 0 1 .9.8 2.6.3 3.4-.6 1-1 1.6-1 2.8 0 1.9-1.3 3-2.1 3s-2.1-1.1-2.1-3c0-1.2-.4-1.8-1-2.8-.5-.8-.7-2.5.3-3.4Z" stroke="#b65930" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M14.8 13.8h2.6c1.2 0 2.1 1 2.1 2.2v3.1c0 1.1-.9 2-2 2h-3.7" stroke="#b65930" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M6 20h8" stroke="#b65930" stroke-width="1.8" stroke-linecap="round"/><path d="M18.5 16.2h-1.6" stroke="#b65930" stroke-width="1.8" stroke-linecap="round"/></svg>';

    const PREFS_COOKIE = 'banya_prefs_v1';
    const getCookie = (name) => {
        const parts = String(document.cookie || '').split(';').map(s => s.trim());
        for (const p of parts) {
            if (!p) continue;
            const eq = p.indexOf('=');
            if (eq < 0) continue;
            const k = p.slice(0, eq).trim();
            if (k !== name) continue;
            return decodeURIComponent(p.slice(eq + 1));
        }
        return '';
    };
    const setCookie = (name, value, days = 180) => {
        const maxAge = Math.max(0, Math.round(days * 24 * 60 * 60));
        document.cookie = `${name}=${encodeURIComponent(value)}; Max-Age=${maxAge}; Path=/; SameSite=Lax`;
    };
    const loadPrefs = () => {
        try {
            const raw = getCookie(PREFS_COOKIE);
            if (!raw) return null;
            const obj = JSON.parse(raw);
            return (obj && typeof obj === 'object') ? obj : null;
        } catch (_) {
            return null;
        }
    };
    const savePrefs = (prefs) => {
        try {
            setCookie(PREFS_COOKIE, JSON.stringify(prefs || {}));
        } catch (_) {}
    };

    // Пагинация и сортировка
    const pagerTop = document.getElementById('pagerTop');
    const pagerBottom = document.getElementById('pagerBottom');
    const noPagesCb = document.getElementById('noPages');
    const ths = Array.from(document.querySelectorAll('th[data-sort]'));
    let dataItems = [];
    let sortBy = 'date';
    let sortDir = 'asc';
    let page = 1;
    const pageSize = 20;
    let noPages = false;

    const applyPrefsToUi = () => {
        const p = loadPrefs();
        if (!p) return;
        if (typeof p.date_from === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(p.date_from)) elFrom.value = p.date_from;
        if (typeof p.date_to === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(p.date_to)) elTo.value = p.date_to;
        if (typeof p.no_pages === 'boolean') {
            noPages = p.no_pages;
            if (noPagesCb) noPagesCb.checked = !!p.no_pages;
        }
        if (typeof p.sort_by === 'string') sortBy = p.sort_by;
        if (p.sort_dir === 'asc' || p.sort_dir === 'desc') sortDir = p.sort_dir;
        if (typeof p.page === 'number' && isFinite(p.page) && p.page > 0) page = Math.floor(p.page);
    };
    const persistPrefsFromUi = () => {
        savePrefs({
            date_from: elFrom.value,
            date_to: elTo.value,
            no_pages: !!noPages,
            sort_by: sortBy,
            sort_dir: sortDir,
            page: page,
        });
    };

    const applySort = (arr) => {
        const coll = new Intl.Collator('ru', {numeric:true, sensitivity:'base'});
        const dir = sortDir === 'desc' ? -1 : 1;
        const get = (o, k) => (o && Object.prototype.hasOwnProperty.call(o, k)) ? o[k] : '';
        return arr.slice().sort((a, b) => {
            const av = get(a, sortBy);
            const bv = get(b, sortBy);
            if (typeof av === 'number' || typeof bv === 'number') {
                const an = Number(av || 0), bn = Number(bv || 0);
                if (an === bn) return 0;
                return an < bn ? -1*dir : 1*dir;
            }
            const s = coll.compare(String(av || ''), String(bv || ''));
            return s * dir;
        });
    };

    const buildPageList = (pages, current) => {
        if (pages <= 1) return [1];
        const keep = new Set([1, pages, current, current - 1, current - 2, current + 1, current + 2]);
        const out = [];
        let last = 0;
        for (let i = 1; i <= pages; i++) {
            if (!keep.has(i)) continue;
            if (last && i - last > 1) out.push('…');
            out.push(i);
            last = i;
        }
        return out;
    };

    const renderPager = (el, pages, current) => {
        if (!el) return;
        if (noPages || pages <= 1) {
            el.innerHTML = '';
            return;
        }
        const items = buildPageList(pages, current);
        el.innerHTML = '';
        items.forEach((it) => {
            if (it === '…') {
                const span = document.createElement('span');
                span.className = 'page-dots';
                span.textContent = '…';
                el.appendChild(span);
                return;
            }
            const b = document.createElement('button');
            b.type = 'button';
            b.className = 'page-btn' + (it === current ? ' active' : '');
            b.textContent = String(it);
            b.setAttribute('data-page', String(it));
            el.appendChild(b);
        });
    };

    const renderTable = () => {
        const items = applySort(dataItems);
        const total = items.length;
        const pages = noPages ? 1 : Math.max(1, Math.ceil(total / pageSize));
        if (page > pages) page = pages;
        const start = noPages ? 0 : (page - 1) * pageSize;
        const slice = noPages ? items : items.slice(start, start + pageSize);

        tbody.innerHTML = '';
        slice.forEach((row) => {
            const tr = document.createElement('tr');
            const txId = Number(row.transaction_id || 0);
            const hasHookah = Number(row.hookah_sum_minor || 0) > 0;
            tr.innerHTML = `
                <td>${esc(row.date || '')}</td>
                <td>${esc(row.hall || '')}</td>
                <td>${esc(row.table || '')}</td>
                <td>${esc(row.receipt || '')}</td>
                <td>${esc(row.waiter || '')}</td>
                <td class="num">${esc(row.sum || '')}</td>
                <td><button type="button" class="secondary small" data-tx="${esc(txId)}">Детали</button>${hasHookah ? hookahSvg : ''}</td>
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
                                const qty = Number(ln.qty || 0);
                                const qtyTxt = (qty && Math.abs(qty - Math.round(qty)) < 0.0001) ? String(Math.round(qty)) : String(qty);
                                line.innerHTML = `<div>${esc(ln.name || '')}${qty ? ' × ' + esc(qtyTxt) : ''}</div><div class="detail-sum">${esc(ln.sum || '0')}</div>`;
                                box.appendChild(line);
                            });
                        }
                        const td = trD.querySelector('td');
                        if (td) { td.innerHTML = ''; td.appendChild(box); }
                    } catch (e) {
                        trD.innerHTML = `<td colspan="7"><div class="details-box" style="color:#b91c1c; font-weight:700;">${esc(e && e.message ? e.message : 'Ошибка')}</div></td>`;
                    }
                });
            }
        });
        renderPager(pagerTop, pages, page);
        renderPager(pagerBottom, pages, page);
        persistPrefsFromUi();
    };

    const onPagerClick = (e) => {
        const btn = e.target.closest?.('.page-btn');
        if (!btn) return;
        const p = Number(btn.getAttribute('data-page') || 0);
        if (!p) return;
        page = p;
        renderTable();
    };
    if (pagerTop) pagerTop.addEventListener('click', onPagerClick);
    if (pagerBottom) pagerBottom.addEventListener('click', onPagerClick);
    if (noPagesCb) noPagesCb.addEventListener('change', () => {
        noPages = !!noPagesCb.checked;
        page = 1;
        renderTable();
    });
    ths.forEach((th) => {
        th.addEventListener('click', () => {
            const key = th.getAttribute('data-sort');
            if (!key) return;
            if (sortBy === key) sortDir = (sortDir === 'asc') ? 'desc' : 'asc';
            else { sortBy = key; sortDir = 'asc'; }
            renderTable();
        });
    });

    elFrom.addEventListener('change', () => persistPrefsFromUi());
    elTo.addEventListener('change', () => persistPrefsFromUi());

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

            dataItems = j.items || [];
            page = 1;
            renderTable();

            totChecks.textContent = `Итого чеков: ${String(j.totals?.checks || 0)}`;
            totSum.textContent = `Итого сумма: ${String(j.totals?.sum || '0')}`;
            totHookah.textContent = `Сумма кальянов: ${String(j.totals?.hookah_sum || '0')}`;
            totWithout.textContent = `Сумма без кальянов: ${String(j.totals?.without_hookah_sum || '0')}`;
            persistPrefsFromUi();
        } catch (e) {
            setError(e && e.message ? e.message : 'Ошибка');
        } finally {
            setLoading(false);
        }
    };

    btn.addEventListener('click', load);
    applyPrefsToUi();
    persistPrefsFromUi();
</script>
<script src="/assets/user_menu.js" defer></script>
</body>
</html>
