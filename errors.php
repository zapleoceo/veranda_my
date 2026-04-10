<?php
// require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/src/classes/Database.php';
if (file_exists(__DIR__ . '/.env')) {
    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        [$name, $value] = explode('=', $line, 2);
        $_ENV[$name] = trim($value);
    }
}
$db = new \App\Classes\Database(
    $_ENV['DB_HOST'] ?? 'localhost',
    $_ENV['DB_NAME'] ?? 'veranda_my',
    $_ENV['DB_USER'] ?? 'veranda_my',
    $_ENV['DB_PASS'] ?? '',
    (string)($_ENV['DB_TABLE_SUFFIX'] ?? '')
);

require_once __DIR__ . '/src/classes/PosterAPI.php';
// veranda_require('errors');

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

$fetchHistoriesBatch = function (string $posterToken, array $txIds, int $batchSize): array {
    $out = [];
    $ids = [];
    foreach ($txIds as $id) {
        $tid = (int)$id;
        if ($tid > 0) $ids[] = $tid;
    }
    if (!$ids) return $out;
    $batch = max(1, (int)$batchSize);
    $apiBase = 'https://joinposter.com/api/dash.getTransactionHistory';
    $chunks = array_chunk($ids, $batch);
    foreach ($chunks as $chunk) {
        $mh = curl_multi_init();
        $handles = [];
        foreach ($chunk as $tid) {
            $url = $apiBase . '?token=' . urlencode($posterToken) . '&transaction_id=' . urlencode((string)$tid);
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 25);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_ENCODING, '');
            $handles[(int)$tid] = $ch;
            curl_multi_add_handle($mh, $ch);
        }
        do {
            $status = curl_multi_exec($mh, $active);
            if ($active) curl_multi_select($mh, 0.2);
        } while ($active && $status == CURLM_OK);
        foreach ($handles as $tid => $ch) {
            $body = curl_multi_getcontent($ch);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
            $data = is_string($body) && $body !== '' ? json_decode($body, true) : null;
            if (!is_array($data)) {
                $out[(int)$tid] = [];
                continue;
            }
            if (isset($data['error']) || isset($data['error_code'])) {
                $out[(int)$tid] = [];
                continue;
            }
            $resp = $data['response'] ?? $data;
            $out[(int)$tid] = is_array($resp) ? $resp : [];
        }
        curl_multi_close($mh);
    }
    return $out;
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

        $txRowsById = [];
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
                if ($id > 0) $txRowsById[$id] = $tx;
            }
            if ($nextTr !== null && $prevNextTr !== null && (string)$nextTr === (string)$prevNextTr) break;
        } while ($count > 0 && $nextTr !== null);

        $total = count($txRowsById);
        $missing = 0;
        $hours = array_fill(0, 24, ['total' => 0, 'missing' => 0]);

        $historyByTx = $fetchHistoriesBatch($posterToken, array_keys($txRowsById), 10);
        foreach ($txRowsById as $txId => $tx0) {
            $h = -1;
            if (is_array($tx0)) {
                $dateClose = (string)($tx0['date_close_date'] ?? '');
                if ($dateClose !== '') {
                    $ts = strtotime($dateClose);
                    if ($ts !== false) $h = (int)date('G', $ts);
                }
            }
            if ($h < 0 || $h > 23) $h = 0;

            $history = $historyByTx[(int)$txId] ?? [];

            $sent = $extractSentCounts($history, $catByPid);
            $hours[$h]['total'] = (int)$hours[$h]['total'] + 1;
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
            if ($isMissing) $hours[$h]['missing'] = (int)$hours[$h]['missing'] + 1;
        }

        echo json_encode([
            'ok' => true,
            'date' => $date,
            'total' => $total,
            'missing' => $missing,
            'hours' => $hours,
        ], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if (($_GET['ajax'] ?? '') === 'day_checks') {
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

        $txRowsById = [];
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
                if ($id > 0) $txRowsById[$id] = $tx;
            }
            if ($nextTr !== null && $prevNextTr !== null && (string)$nextTr === (string)$prevNextTr) break;
        } while ($count > 0 && $nextTr !== null);

        $historyByTx = $fetchHistoriesBatch($posterToken, array_keys($txRowsById), 10);
        $checks = [];
        foreach ($txRowsById as $txId => $tx) {
            $history = $historyByTx[(int)$txId] ?? [];

            $sent = $extractSentCounts($history, $catByPid);
            $missing = false;
            if ($sent) {
                $finished = $extractFinishedCounts($history, $catByPid);
                $deleted = $extractDeletedCounts($history, $catByPid);
                foreach ($sent as $pid => $cnt) {
                    $eff = $cnt - (int)($deleted[$pid] ?? 0);
                    if ($eff < 0) $eff = 0;
                    if ($eff <= 0) continue;
                    if (((int)($finished[$pid] ?? 0)) < $eff) {
                        $missing = true;
                        break;
                    }
                }
            }

            $checks[] = [
                'transaction_id' => (int)$txId,
                'receipt' => (string)($tx['receipt_number'] ?? ''),
                'date_close' => (string)($tx['date_close_date'] ?? ''),
                'table' => (string)($tx['table_name'] ?? ''),
                'table_id' => (int)($tx['table_id'] ?? 0),
                'waiter' => (string)($tx['name'] ?? ''),
                'sum_minor' => (int)($tx['payed_sum'] ?? $tx['sum'] ?? 0),
                'missing' => $missing,
            ];
        }

        usort($checks, function ($a, $b) {
            return strcmp((string)($a['date_close'] ?? ''), (string)($b['date_close'] ?? ''));
        });

        echo json_encode([
            'ok' => true,
            'date' => $date,
            'checks' => $checks,
        ], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if (($_GET['ajax'] ?? '') === 'tx_history') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    $txId = (int)($_GET['transaction_id'] ?? 0);
    if ($txId <= 0) {
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
        $history = $api->request('dash.getTransactionHistory', ['transaction_id' => (int)$txId], 'GET');
        if (!is_array($history)) $history = [];
        echo json_encode(['ok' => true, 'transaction_id' => $txId, 'history' => $history], JSON_UNESCAPED_UNICODE);
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
$dateList = [];
for ($d = 1; $d <= $daysInMonth; $d++) {
    $dateList[] = sprintf('%s-%02d', $ym, $d);
}
$todayStr = date('Y-m-d');

?><!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Errors Dashboard</title>
    <link rel="icon" type="image/svg+xml" href="/links/favicon.svg">
    <link rel="stylesheet" href="assets/app.css">
    <script src="/assets/app.js" defer></script>
    <script src="assets/user_menu.js" defer></script>
      <?php include $_SERVER['DOCUMENT_ROOT'] . '/analytics.php'; ?>
  <link rel="stylesheet" href="/assets/css/common.css">
  <link rel="stylesheet" href="/assets/css/errors.css">
</head>
<body>
<div class="wrap">
    <div class="top">
        <div>
            <h1>/errors.php — отчёт по отсутствию cooked</h1>
            <div class="muted">Источник: Poster (dash.getTransactions + dash.getTransactionHistory)</div>
        </div>
        <div class="controls">
            <form method="get" class="card" id="controlsForm" style="display:flex; gap:8px; align-items:center; padding:8px 10px;">
                <span class="muted">Месяц</span>
                <input type="month" id="ymInput" name="ym" value="<?= htmlspecialchars($ym) ?>" />
                <button type="button" id="loadBtn">Загрузить</button>
                <div class="prog-inline" id="monthProg">
                    <div class="pbar"><div id="monthProgFill"></div></div>
                    <div class="pct" id="monthProgPct">0%</div>
                    <div class="desc" id="monthProgDesc"></div>
                </div>
            </form>
            <?php require __DIR__ . '/partials/user_menu.php'; ?>
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
                        echo '<div class="num"><span>' . $d . '</span><span class="pct">—%</span></div>';
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

            <div style="margin-top: 12px; font-weight:900;">График по часам (09:00–23:59)</div>
            <div class="chart-wrap">
                <div class="chart" id="hourChart"></div>
            </div>

            <div class="checks">
                <div style="display:flex; align-items:center; justify-content: space-between; gap: 10px; flex-wrap: wrap;">
                    <div style="font-weight:900;">Чеки дня</div>
                    <div class="toggle-wrap" title="Показывать только проблемные чеки">
                        <span class="toggle-text">проблемные</span>
                        <label class="switch">
                            <input type="checkbox" id="onlyBad">
                            <span class="slider"></span>
                        </label>
                    </div>
                </div>
                <div class="muted" id="checksHint" style="margin-top:6px;">Нажми на чек, чтобы раскрыть историю.</div>
                <div class="prog-inline" id="checksProg" style="margin-top: 8px;">
                    <div class="pbar"><div id="checksProgFill"></div></div>
                    <div class="pct" id="checksProgPct">0%</div>
                    <div class="desc" id="checksProgDesc"></div>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th style="width:170px;">Дата</th>
                            <th style="width:120px;">Чек</th>
                            <th>Стол</th>
                            <th>Официант</th>
                            <th style="width:120px; text-align:right;">Сумма</th>
                            <th style="width:120px;">Статус</th>
                        </tr>
                    </thead>
                    <tbody id="checksBody"></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
    const ym = <?= json_encode($ym, JSON_UNESCAPED_UNICODE) ?>;
    const monthStart = <?= json_encode($monthStart, JSON_UNESCAPED_UNICODE) ?>;
    const monthEnd = <?= json_encode($monthEnd, JSON_UNESCAPED_UNICODE) ?>;
    const dateList = <?= json_encode($dateList, JSON_UNESCAPED_UNICODE) ?>;
    const todayStr = <?= json_encode($todayStr, JSON_UNESCAPED_UNICODE) ?>;
    const ymInput = document.getElementById('ymInput');

    const monthProg = document.getElementById('monthProg');
    const monthProgFill = document.getElementById('monthProgFill');
    const monthProgPct = document.getElementById('monthProgPct');
    const monthProgDesc = document.getElementById('monthProgDesc');
    const checksProg = document.getElementById('checksProg');
    const checksProgFill = document.getElementById('checksProgFill');
    const checksProgPct = document.getElementById('checksProgPct');
    const checksProgDesc = document.getElementById('checksProgDesc');

    const dayLabel = document.getElementById('dayLabel');
    const kpiTotal = document.getElementById('kpiTotal');
    const kpiReady = document.getElementById('kpiReady');
    const kpiMissing = document.getElementById('kpiMissing');
    const monthMissing = document.getElementById('monthMissing');
    const monthTotal = document.getElementById('monthTotal');
    const checksHint = document.getElementById('checksHint');

    const setMonthLoading = (on) => {
        if (!monthProg) return;
        monthProg.style.display = on ? 'inline-flex' : 'none';
    };
    const setMonthProgress = (pct, desc) => {
        const p = Math.max(0, Math.min(100, Math.round(Number(pct || 0))));
        if (monthProgFill) monthProgFill.style.width = p + '%';
        if (monthProgPct) monthProgPct.textContent = p + '%';
        if (monthProgDesc) monthProgDesc.textContent = String(desc || '');
    };
    const setChecksLoading = (on) => {
        if (!checksProg) return;
        checksProg.style.display = on ? 'inline-flex' : 'none';
    };
    const setChecksProgress = (pct, desc) => {
        const p = Math.max(0, Math.min(100, Math.round(Number(pct || 0))));
        if (checksProgFill) checksProgFill.style.width = p + '%';
        if (checksProgPct) checksProgPct.textContent = p + '%';
        if (checksProgDesc) checksProgDesc.textContent = String(desc || '');
    };

    const monthData = new Map();
    let dayChecks = [];
    let onlyBad = false;
    let checksAbort = null;
    let checksProgressTimer = null;

    const updateCalendarCell = (date, total, missing) => {
        const el = document.querySelector(`.day[data-date="${date}"]`);
        if (!el) return;
        const num = el.querySelector('.num .pct');
        const mini = el.querySelector('.mini');
        const pct = total > 0 ? Math.round((missing / total) * 100) : 0;
        if (num) {
            num.textContent = pct + '%';
            num.classList.toggle('bad', pct > 0);
            num.title = 'Процент чеков без cooked';
        }
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

    const renderHourChart = (hours, date) => {
        const el = document.getElementById('hourChart');
        if (!el) return;
        el.innerHTML = '';
        const maxTotal = Math.max(1, ...hours.map(x => Number(x.total || 0)));
        for (let h = 9; h < 24; h++) {
            const total = Number(hours[h]?.total || 0);
            const missing = Number(hours[h]?.missing || 0);
            const bar = document.createElement('div');
            bar.className = 'bar';
            bar.title = `${String(date || '')} ${String(h).padStart(2,'0')}:00 — всего ${total} | без cooked ${missing}`;
            const heightPct = (total / maxTotal) * 100;
            bar.style.height = `calc(${heightPct}% + 2px)`;
            const miss = document.createElement('div');
            miss.className = 'miss';
            miss.style.height = total > 0 ? `${(missing / total) * 100}%` : '0%';
            bar.appendChild(miss);
            const lab = document.createElement('div');
            lab.className = 'label';
            lab.textContent = (h % 2 === 0) ? String(h) : '';
            bar.appendChild(lab);
            el.appendChild(bar);
        }
    };

    const fmtVnd = (minor) => {
        const vnd = Math.round(Number(minor || 0) / 100);
        return String(vnd).replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
    };

    const renderChecks = () => {
        const tbody = document.getElementById('checksBody');
        if (!tbody) return;
        tbody.innerHTML = '';
        const rows = onlyBad ? dayChecks.filter((x) => !!x.missing) : dayChecks.slice();
        if (!rows.length) {
            const tr = document.createElement('tr');
            tr.innerHTML = `<td colspan="6" class="muted">Нет данных</td>`;
            tbody.appendChild(tr);
            return;
        }
        rows.forEach((r) => {
            const tr = document.createElement('tr');
            if (r.missing) tr.className = 'bad';
            tr.innerHTML = `
                <td>${String(r.date_close || '')}</td>
                <td><button type="button" class="secondary small" data-tx="${String(r.transaction_id || '')}">${String(r.receipt || r.transaction_id || '')}</button></td>
                <td>${String(r.table || '')}</td>
                <td>${String(r.waiter || '')}</td>
                <td class="num">${fmtVnd(r.sum_minor || 0)}</td>
                <td>${r.missing ? '<span style="color:#e57373; font-weight:900;">ошибка</span>' : '<span style="color:#81c784; font-weight:900;">ок</span>'}</td>
            `;
            tbody.appendChild(tr);
            const trD = document.createElement('tr');
            trD.style.display = 'none';
            trD.innerHTML = `<td colspan="6"><div class="hist muted">Загрузка…</div></td>`;
            tbody.appendChild(trD);

            const btn = tr.querySelector('button');
            if (btn) {
                btn.addEventListener('click', async () => {
                    const isOpen = trD.style.display !== 'none';
                    if (isOpen) { trD.style.display = 'none'; return; }
                    trD.style.display = '';
                    try {
                        const txId = Number(btn.getAttribute('data-tx') || 0);
                        const url = new URL(location.href);
                        url.searchParams.set('ym', ym);
                        url.searchParams.set('ajax', 'tx_history');
                        url.searchParams.set('transaction_id', String(txId));
                        const res = await fetch(url.toString(), { headers: { 'Accept': 'application/json' } });
                        const j = await res.json();
                        if (!j || !j.ok) throw new Error((j && j.error) ? j.error : 'Ошибка');
                        const h = Array.isArray(j.history) ? j.history : [];
                        const esc = (s) => String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
                        const typeTitle = (t) => ({
                            open: 'Открыт чек',
                            close: 'Закрыт чек',
                            print: 'Печать',
                            additem: 'Добавлен товар',
                            deleteitem: 'Удалён товар',
                            changeitemcount: 'Изменено количество',
                            settable: 'Смена стола',
                            sendtokitchen: 'Отправлено на кухню',
                            finishedcooking: 'Готово (cooked)',
                            delete: 'Удаление',
                        }[t] || t);
                        const html = h.map((ev) => {
                            const type = String(ev.type_history || '');
                            const timeMs = Number(ev.time || 0);
                            let dt = '';
                            if (timeMs) {
                                try {
                                    dt = new Date(timeMs).toLocaleString('sv-SE', { timeZone: 'Asia/Ho_Chi_Minh', hour12: false });
                                } catch (_) {
                                    dt = new Date(timeMs).toISOString().replace('T', ' ').slice(0, 19);
                                }
                            }
                            const v = [ev.value, ev.value2, ev.value3, ev.value4, ev.value5]
                                .filter((x) => x !== undefined && x !== null && String(x) !== '')
                                .map((x) => String(x)).join(' | ');
                            let vt = ev.value_text;
                            if (typeof vt === 'string' && vt.length > 0) {
                                try { vt = JSON.stringify(JSON.parse(vt), null, 2); } catch (_) {}
                            } else if (vt && typeof vt === 'object') {
                                try { vt = JSON.stringify(vt, null, 2); } catch (_) { vt = String(vt); }
                            } else {
                                vt = '';
                            }
                            const body = [v ? ('value: ' + v) : '', vt ? ('value_text:\n' + vt) : ''].filter(Boolean).join('\n');
                            return `<div class="hist-item"><div class="hist-head"><div class="hist-type">${esc(typeTitle(type))}</div><div class="hist-time">${esc(dt)}</div></div><div class="hist-body">${esc(body)}</div></div>`;
                        }).join('');
                        trD.innerHTML = `<td colspan="6"><div class="hist">${html || '<div class="muted">Нет данных</div>'}</div></td>`;
                    } catch (e) {
                        trD.innerHTML = `<td colspan="6"><div class="hist" style="color:#b91c1c; font-weight:800;">${String(e && e.message ? e.message : 'Ошибка')}</div></td>`;
                    }
                });
            }
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
        const hours = Array.isArray(j.hours) ? j.hours : new Array(24).fill({ total: 0, missing: 0 });
        monthData.set(date, { total, missing, hours });
        return monthData.get(date);
    };

    const fetchDayChecks = async (date) => {
        if (checksAbort) {
            try { checksAbort.abort(); } catch (_) {}
        }
        try { checksAbort = new AbortController(); } catch (_) { checksAbort = null; }
        if (checksHint) {
            checksHint.style.color = '#6b7280';
            checksHint.textContent = 'Загрузка чеков…';
        }
        dayChecks = [];
        renderChecks();
        setChecksLoading(true);
        setChecksProgress(0, '');
        if (checksProgressTimer) clearInterval(checksProgressTimer);
        let fake = 0;
        checksProgressTimer = setInterval(() => {
            fake = Math.min(90, fake + 3);
            setChecksProgress(fake, '');
        }, 180);

        const url = new URL(location.href);
        url.searchParams.set('ym', ym);
        url.searchParams.set('ajax', 'day_checks');
        url.searchParams.set('date', date);
        const res = await fetch(url.toString(), { headers: { 'Accept': 'application/json' }, signal: checksAbort ? checksAbort.signal : undefined });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const j = await res.json();
        if (!j || !j.ok) throw new Error((j && j.error) ? j.error : 'Ошибка загрузки');
        const checks = Array.isArray(j.checks) ? j.checks : [];
        dayChecks = checks;
        renderChecks();
        setChecksProgress(100, 'Готово');
        if (checksProgressTimer) clearInterval(checksProgressTimer);
        setTimeout(() => setChecksLoading(false), 250);
        if (checksHint) {
            const bad = checks.filter((x) => !!x.missing).length;
            checksHint.style.color = '#6b7280';
            checksHint.textContent = `Чеков: ${checks.length} · проблемных: ${bad}`;
        }
    };

    const loadMonth = async () => {
        setMonthLoading(true);
        setMonthProgress(0, 'Подготовка…');
        let done = 0;
        const allDays = Array.from(document.querySelectorAll('.day[data-date]')).map((x) => String(x.getAttribute('data-date') || '')).filter((x) => /^\d{4}-\d{2}-\d{2}$/.test(x));
        const fetchDays = allDays.filter((d) => d <= todayStr);
        const futureDays = allDays.filter((d) => d > todayStr);
        futureDays.forEach((d) => updateCalendarCell(d, 0, 0));
        const concurrency = Math.min(12, Math.max(6, fetchDays.length));
        const queue = fetchDays.slice();

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
                const den = fetchDays.length || 1;
                setMonthProgress(Math.round((done / den) * 100), `- день ${done}/${fetchDays.length} (${dd}/${mm})`);
            }
        };

        try {
            if (!fetchDays.length) {
                if (monthTotal) monthTotal.textContent = '0';
                if (monthMissing) monthMissing.textContent = '0';
                setMonthProgress(100, 'Нет дней для загрузки');
                setTimeout(() => setMonthLoading(false), 250);
                return;
            }
            await Promise.all(new Array(Math.min(concurrency, queue.length)).fill(0).map(worker));
            let totalSum = 0;
            let missingSum = 0;
            allDays.forEach((d) => {
                const r = monthData.get(d);
                if (!r) return;
                totalSum += Number(r.total || 0);
                missingSum += Number(r.missing || 0);
            });
            if (monthTotal) monthTotal.textContent = String(totalSum);
            if (monthMissing) monthMissing.textContent = String(missingSum);
            const firstDay = dateList[0] || '';
            if (firstDay) {
                const r = monthData.get(firstDay) || { total: 0, missing: 0, hours: [] };
                setDayKpis(firstDay, r.total, r.missing);
                renderHourChart(r.hours || [], firstDay);
                fetchDayChecks(firstDay).catch((e) => {
                    if (checksHint) {
                        checksHint.style.color = '#b91c1c';
                        checksHint.textContent = String(e && e.message ? e.message : 'Ошибка загрузки чеков');
                    }
                });
                const cell = document.querySelector(`.day[data-date="${firstDay}"]`);
                if (cell) cell.classList.add('active');
            }
            setMonthProgress(100, 'Готово');
            setTimeout(() => setMonthLoading(false), 250);
        } catch (e) {
            setMonthProgress(0, e && e.message ? e.message : 'Failed to fetch');
            setTimeout(() => setMonthLoading(false), 250);
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
            renderHourChart(r.hours || [], date);
            await fetchDayChecks(date);
        })().catch((err) => alert(err && err.message ? err.message : 'Ошибка'));
    });

    document.getElementById('loadBtn')?.addEventListener('click', (e) => {
        e.preventDefault();
        loadMonth();
    });

    if (ymInput) {
        ymInput.addEventListener('change', () => {
            const val = String(ymInput.value || '').trim();
            if (!/^\d{4}-\d{2}$/.test(val)) return;
            const url = new URL(location.href);
            url.searchParams.set('ym', val);
            url.searchParams.delete('ajax');
            location.href = url.toString();
        });
    }

    const onlyBadCb = document.getElementById('onlyBad');
    if (onlyBadCb) {
        onlyBadCb.addEventListener('change', () => {
            onlyBad = !!onlyBadCb.checked;
            renderChecks();
        });
    }
</script>
</body>
</html>
