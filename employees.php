<?php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/src/classes/PosterAPI.php';

date_default_timezone_set('Asia/Ho_Chi_Minh');

if (!veranda_can('admin')) {
    veranda_require('employees');
}

$posterToken = (string)($_ENV['POSTER_API_TOKEN'] ?? '');
if ($posterToken === '') {
    http_response_code(500);
    echo 'POSTER_API_TOKEN не задан';
    exit;
}

$ratesTable = $db->t('employee_rates');
try {
    $db->query(
        "CREATE TABLE IF NOT EXISTS {$ratesTable} (
            user_id INT NOT NULL,
            rate BIGINT NOT NULL DEFAULT 0,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            updated_by VARCHAR(255) NULL,
            PRIMARY KEY (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
} catch (\Throwable $e) {
}

$parseDate = function (string $s): ?string {
    $t = trim($s);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $t) ? $t : null;
};

if (($_GET['ajax'] ?? '') === 'save_rate') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    $raw = file_get_contents('php://input');
    $j = json_decode((string)$raw, true);
    if (!is_array($j)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Bad request'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $userId = (int)($j['user_id'] ?? 0);
    $rate = (string)($j['rate'] ?? '');
    $rateDigits = preg_replace('/\D+/', '', $rate);
    $rateInt = (int)($rateDigits !== '' ? $rateDigits : '0');
    if ($userId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Bad request'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    try {
        $by = trim((string)($_SESSION['user_email'] ?? $_SESSION['user_name'] ?? ''));
        $db->query(
            "INSERT INTO {$ratesTable} (user_id, rate, updated_by) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE rate = VALUES(rate), updated_by = VALUES(updated_by)",
            [$userId, $rateInt, ($by !== '' ? $by : null)]
        );
        echo json_encode(['ok' => true, 'user_id' => $userId, 'rate' => $rateInt], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if (($_GET['ajax'] ?? '') === 'load') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    $dateFrom = $parseDate((string)($_GET['date_from'] ?? ''));
    $dateTo = $parseDate((string)($_GET['date_to'] ?? ''));
    if ($dateFrom === null || $dateTo === null || $dateFrom > $dateTo) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Некорректный период'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $api = new \App\Classes\PosterAPI($posterToken);
        $emps = $api->request('access.getEmployees', [], 'GET');
        if (!is_array($emps)) $emps = [];
        $roleByUser = [];
        foreach ($emps as $e) {
            if (!is_array($e)) continue;
            $uid = (int)($e['user_id'] ?? 0);
            if ($uid <= 0) continue;
            $roleByUser[$uid] = (string)($e['role_name'] ?? '');
        }
        $rows = $api->request('dash.getWaitersSales', [
            'dateFrom' => str_replace('-', '', $dateFrom),
            'dateTo' => str_replace('-', '', $dateTo),
        ], 'GET');
        if (!is_array($rows)) $rows = [];

        $tipsMode = null;
        $tipsByUserId = [];
        $tipsByName = [];
        $seenTx = [];
        $nextTr = null;
        $prevNextTr = null;
        $page = 0;
        do {
            $page++;
            if ($page > 2000) break;
            $params = [
                'dateFrom' => str_replace('-', '', $dateFrom),
                'dateTo' => str_replace('-', '', $dateTo),
                'status' => 2,
                'include_history' => 'false',
                'include_products' => 'false',
                'include_delivery' => 'false',
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
                if ($txId <= 0 || isset($seenTx[$txId])) continue;
                $seenTx[$txId] = true;

                if ($tipsMode === null) {
                    if (array_key_exists('tips_card', $tx)) $tipsMode = 'tips_card';
                    elseif (array_key_exists('tip_sum', $tx)) $tipsMode = 'tip_sum';
                    else $tipsMode = 'none';
                }
                $val = 0;
                if ($tipsMode === 'tips_card') {
                    $val = (int)($tx['tips_card'] ?? 0);
                } elseif ($tipsMode === 'tip_sum') {
                    $val = (int)($tx['tip_sum'] ?? 0);
                }
                if ($val <= 0) continue;

                $uidTx = (int)($tx['user_id'] ?? 0);
                $wName = trim((string)($tx['name'] ?? ''));
                if ($uidTx > 0) {
                    if (!isset($tipsByUserId[$uidTx])) $tipsByUserId[$uidTx] = 0;
                    $tipsByUserId[$uidTx] += $val;
                } elseif ($wName !== '') {
                    $k = mb_strtolower($wName, 'UTF-8');
                    if (!isset($tipsByName[$k])) $tipsByName[$k] = 0;
                    $tipsByName[$k] += $val;
                }
            }
            if ($nextTr !== null && $prevNextTr !== null && (string)$nextTr === (string)$prevNextTr) break;
        } while ($count > 0 && $nextTr !== null);

        $out = [];
        $uids = [];
        foreach ($rows as $r) {
            if (!is_array($r)) continue;
            $uid = (int)($r['user_id'] ?? 0);
            if ($uid > 0) $uids[$uid] = true;
            $name = (string)($r['name'] ?? '');
            $clients = (int)($r['clients'] ?? 0);
            $worked = $r['worked_time'] ?? null;
            if ($worked === null) $worked = $r['workedTime'] ?? null;
            if ($worked === null) $worked = $r['middle_time'] ?? null;
            $workedMin = is_numeric($worked) ? (int)round((float)$worked) : 0;
            $workedHours = $workedMin > 0 ? round($workedMin / 60, 2) : 0;
            $role = $uid > 0 ? (string)($roleByUser[$uid] ?? '') : '';
            $nk = mb_strtolower(trim($name), 'UTF-8');
            $tipsMinor = $uid > 0 && isset($tipsByUserId[$uid]) ? (int)$tipsByUserId[$uid] : (int)($tipsByName[$nk] ?? 0);
            $out[] = [
                'user_id' => $uid,
                'name' => $name,
                'role_name' => $role,
                'checks' => $clients,
                'worked_hours' => $workedHours,
                'tips_card' => $tipsMinor,
            ];
        }
        $rateByUser = [];
        $uidList = array_keys($uids);
        if ($uidList) {
            $place = implode(',', array_fill(0, count($uidList), '?'));
            $rateRows = $db->query(
                "SELECT user_id, rate FROM {$ratesTable} WHERE user_id IN ({$place})",
                $uidList
            )->fetchAll();
            foreach ($rateRows as $rr) {
                $u = (int)($rr['user_id'] ?? 0);
                if ($u <= 0) continue;
                $rateByUser[$u] = (int)($rr['rate'] ?? 0);
            }
        }
        foreach ($out as &$row) {
            $u = (int)($row['user_id'] ?? 0);
            $row['rate'] = (int)($rateByUser[$u] ?? 0);
        }
        unset($row);
        usort($out, fn($a, $b) => ($b['checks'] <=> $a['checks']));
        echo json_encode(['ok' => true, 'rows' => $out, 'tips_mode' => ($tipsMode ?? 'none')], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

$mkDays = function (string $from, string $to): array {
    $out = [];
    $t1 = strtotime($from . ' 00:00:00');
    $t2 = strtotime($to . ' 00:00:00');
    if ($t1 === false || $t2 === false) return $out;
    if ($t2 < $t1) return $out;
    for ($t = $t1; $t <= $t2; $t += 86400) {
        $out[] = date('Y-m-d', $t);
    }
    return $out;
};

if (($_GET['ajax'] ?? '') === 'tips_prepare') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    $dateFrom = $parseDate((string)($_GET['date_from'] ?? ''));
    $dateTo = $parseDate((string)($_GET['date_to'] ?? ''));
    if ($dateFrom === null || $dateTo === null || $dateFrom > $dateTo) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Некорректный период'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $days = $mkDays($dateFrom, $dateTo);
    if (!$days) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Нет дней'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $jobId = 'tips_' . uniqid('', true);
    $_SESSION[$jobId] = [
        'days' => $days,
        'index' => 0,
        'agg_user' => [],
        'agg_name' => [],
        'tips_mode' => null,
    ];
    echo json_encode(['ok' => true, 'job_id' => $jobId, 'total' => count($days)], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_GET['ajax'] ?? '') === 'tips_run') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    @set_time_limit(120);
    $jobId = (string)($_GET['job_id'] ?? '');
    $batchSize = max(1, min(20, (int)($_GET['batch'] ?? 12)));
    if ($jobId === '' || !isset($_SESSION[$jobId])) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Bad job'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $st = $_SESSION[$jobId];
    $days = (array)($st['days'] ?? []);
    $idx = (int)($st['index'] ?? 0);
    $aggUser = (array)($st['agg_user'] ?? []);
    $aggName = (array)($st['agg_name'] ?? []);
    $tipsMode = $st['tips_mode'] ?? null;
    if (!empty($st['canceled'])) {
        echo json_encode(['ok' => true, 'done' => $idx, 'total' => count($days), 'finished' => true, 'tips_mode' => ($tipsMode ?? 'none'), 'agg_user' => $aggUser, 'agg_name' => $aggName], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($idx >= count($days)) {
        echo json_encode(['ok' => true, 'done' => $idx, 'total' => count($days), 'finished' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $apiBase = 'https://joinposter.com/api/dash.getTransactions';
    $token = $posterToken;
    $chs = [];
    $map = [];
    $end = min(count($days), $idx + $batchSize);
    for ($i = $idx; $i < $end; $i++) {
        $d = str_replace('-', '', $days[$i]);
        $url = $apiBase . '?token=' . urlencode($token) . '&dateFrom=' . $d . '&dateTo=' . $d . '&status=2&include_history=false&include_products=false&include_delivery=false';
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        $chs[] = $ch;
        $map[(int)$i] = $ch;
    }
    $mh = curl_multi_init();
    foreach ($chs as $ch) curl_multi_add_handle($mh, $ch);
    do {
        $status = curl_multi_exec($mh, $active);
        if ($active) curl_multi_select($mh, 0.2);
    } while ($active && $status == CURLM_OK);
    foreach ($map as $i => $ch) {
        $body = curl_multi_getcontent($ch);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
        if (!is_string($body) || $body === '') continue;
        $data = json_decode($body, true);
        $resp = is_array($data) && isset($data['response']) ? $data['response'] : $data;
        if (!is_array($resp)) continue;
        foreach ($resp as $tx) {
            if (!is_array($tx)) continue;
            if ($tipsMode === null) {
                $tipsMode = array_key_exists('tips_card', $tx) ? 'tips_card' : 'none';
            }
            $val = (int)($tx['tips_card'] ?? 0);
            if ($val <= 0) continue;
            $uidTx = (int)($tx['user_id'] ?? 0);
            $wName = trim((string)($tx['name'] ?? ''));
            if ($uidTx > 0) {
                if (!isset($aggUser[$uidTx])) $aggUser[$uidTx] = 0;
                $aggUser[$uidTx] += $val;
            } elseif ($wName !== '') {
                $k = mb_strtolower($wName, 'UTF-8');
                if (!isset($aggName[$k])) $aggName[$k] = 0;
                $aggName[$k] += $val;
            }
        }
    }
    curl_multi_close($mh);
    $idx = $end;
    $_SESSION[$jobId] = [
        'days' => $days,
        'index' => $idx,
        'agg_user' => $aggUser,
        'agg_name' => $aggName,
        'tips_mode' => $tipsMode,
    ];
    echo json_encode([
        'ok' => true,
        'done' => $idx,
        'total' => count($days),
        'finished' => ($idx >= count($days)),
        'tips_mode' => $tipsMode,
        'agg_user' => $aggUser,
        'agg_name' => $aggName,
        'job_id' => $jobId,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_GET['ajax'] ?? '') === 'tips_cancel') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    $jobId = (string)($_GET['job_id'] ?? '');
    if ($jobId !== '' && isset($_SESSION[$jobId])) {
        $_SESSION[$jobId]['canceled'] = 1;
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['ok' => false], JSON_UNESCAPED_UNICODE);
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
    <title>ЗП сотрудников</title>
    <link rel="stylesheet" href="/assets/app.css?v=1" />
    <style>
        .filters { display:flex; gap: 10px; align-items:end; flex-wrap: wrap; }
        .filters label { font-size: 12px; color: #6b7280; display:flex; flex-direction: column; gap: 6px; }
        .filters input[type="date"] { padding: 8px 10px; border: 1px solid #ddd; border-radius: 8px; font-size: 14px; background:#fff; }
        .filters button { padding: 10px 16px; border-radius: 8px; border: 0; background: #1a73e8; color:#fff; font-weight: 800; cursor: pointer; }
        .filters button:disabled { opacity: 0.65; cursor: default; }
        .loader { display:none; align-items:center; gap: 10px; }
        .spinner { width: 16px; height: 16px; border: 2px solid rgba(26,115,232,0.22); border-top-color: rgba(26,115,232,0.9); border-radius: 50%; animation: spin 0.8s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
        .error { margin-top: 10px; color:#b91c1c; font-weight: 800; }
        .table-wrap { overflow:auto; }
        .rate-input { width: 120px; padding: 6px 8px; border: 1px solid #ddd; border-radius: 8px; text-align: right; font-variant-numeric: tabular-nums; }
        .progress { display:none; align-items:center; gap: 10px; margin-left: 10px; }
        .progress .bar { width: 240px; height: 10px; border-radius: 999px; background: #eee; overflow: hidden; }
        .progress .bar > span { display:block; height: 100%; width: 0; background: #1a73e8; transition: width 0.15s ease; }
        .progress .label { font-size: 12px; color:#1f2937; font-weight: 800; }
        .progress .desc { font-size: 12px; color:#6b7280; }
    </style>
</head>
<body>
<div class="container">
    <div class="top-nav">
        <div class="nav-left"><div class="nav-title">ЗП сотрудников</div></div>
        <div class="nav-mid"></div>
        <?php require __DIR__ . '/partials/user_menu.php'; ?>
    </div>

    <div class="card">
        <div class="filters">
            <label>
                Дата начала
                <input type="date" id="dateFrom" value="<?= htmlspecialchars($firstOfMonth) ?>">
            </label>
            <label>
                Дата конца
                <input type="date" id="dateTo" value="<?= htmlspecialchars($today) ?>">
            </label>
            <div style="display:flex; gap: 10px; align-items:center; flex-wrap: wrap;">
                <button type="button" id="loadBtn">ЗАГРУЗИТЬ</button>
                <div class="loader" id="loader"><span class="spinner"></span><span class="muted">Загрузка…</span></div>
                <button type="button" class="secondary" id="cancelBtn" style="display:none;">Отменить</button>
                <div class="progress" id="prog">
                    <div class="bar"><span id="progBar"></span></div>
                    <div class="label" id="progLabel">0%</div>
                    <div class="desc" id="progDesc"></div>
                </div>
            </div>
        </div>
        <div class="error" id="err" style="display:none;"></div>
        <div class="muted" id="tipsMode" style="margin-top: 10px; display:none;"></div>
        <div class="table-wrap" style="margin-top: 12px;">
            <table>
                <thead>
                <tr>
                    <th id="thUid" data-sort="user_id" style="cursor:pointer;">user_id</th>
                    <th id="thName" data-sort="name" style="cursor:pointer;">name</th>
                    <th style="text-align:right;">Ставка</th>
                    <th id="thRole" data-sort="role_name" style="cursor:pointer;">role_name</th>
                    <th id="thChecks" data-sort="checks" style="text-align:right; cursor:pointer;">Чеков</th>
                    <th id="thHours" data-sort="worked_hours" style="text-align:right; cursor:pointer;">ЧасыРаботы</th>
                    <th id="thTips" data-sort="tips_minor" style="text-align:right; cursor:pointer;">Tips</th>
                    <th id="thSalary" data-sort="salary_minor" style="text-align:right; cursor:pointer;">Salary</th>
                </tr>
                </thead>
                <tbody id="tbody"></tbody>
            </table>
        </div>
    </div>
</div>

<script>
(() => {
    const dateFrom = document.getElementById('dateFrom');
    const dateTo = document.getElementById('dateTo');
    const btn = document.getElementById('loadBtn');
    const loader = document.getElementById('loader');
    const err = document.getElementById('err');
    const tbody = document.getElementById('tbody');
    const tipsModeEl = document.getElementById('tipsMode');
    const prog = document.getElementById('prog');
    const progBar = document.getElementById('progBar');
    const progLabel = document.getElementById('progLabel');
    const progDesc = document.getElementById('progDesc');
    const cancelBtn = document.getElementById('cancelBtn');
    let runAbort = null;
    let currentJobId = '';

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
    const digitsOnly = (s) => String(s || '').replace(/\D+/g, '');
    const fmtSpaces = (digits) => {
        const d = String(digits || '').replace(/\D+/g, '');
        if (!d) return '';
        const norm = d.replace(/^0+(?=\d)/, '');
        return norm.replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
    };
    const fmtMoney = (n) => fmtSpaces(String(Math.round(Number(n || 0))));
    const calcSalary = (rate, hours) => Math.round(Number(rate || 0) * Number(hours || 0));
    const vndFromMinor = (minor) => Math.round(Number(minor || 0) / 100);
    const LS_KEY = 'employees_prefs_v1';
    const savePrefs = (obj) => { try { localStorage.setItem(LS_KEY, JSON.stringify(obj || {})); } catch (_) {} };
    const loadPrefs = () => { try { const raw = localStorage.getItem(LS_KEY) || ''; return raw ? JSON.parse(raw) : {}; } catch (_) { return {}; } };
    const prefs = loadPrefs();
    if (prefs.date_from) dateFrom.value = prefs.date_from;
    if (prefs.date_to) dateTo.value = prefs.date_to;
    dateFrom.addEventListener('change', () => { const p = loadPrefs(); p.date_from = dateFrom.value; savePrefs(p); });
    dateTo.addEventListener('change', () => { const p = loadPrefs(); p.date_to = dateTo.value; savePrefs(p); });
    let sortBy = prefs.sort_by || 'checks';
    let sortDir = prefs.sort_dir || 'desc';
    const setSort = (by) => {
        if (!by) return;
        if (sortBy === by) sortDir = (sortDir === 'asc' ? 'desc' : 'asc');
        else { sortBy = by; sortDir = 'asc'; }
        const p = loadPrefs(); p.sort_by = sortBy; p.sort_dir = sortDir; savePrefs(p);
        renderTable();
    };
    const ths = Array.from(document.querySelectorAll('th[data-sort]'));
    ths.forEach((th) => th.addEventListener('click', () => setSort(th.getAttribute('data-sort') || '')));
    let dataRows = [];
    function renderTable() {
        const coll = new Intl.Collator('ru', { numeric: true, sensitivity: 'base' });
        const dir = sortDir === 'desc' ? -1 : 1;
        const items = dataRows.slice().sort((a, b) => {
            const av = a[sortBy];
            const bv = b[sortBy];
            if (typeof av === 'number' || typeof bv === 'number') {
                const an = Number(av || 0), bn = Number(bv || 0);
                if (an === bn) return 0;
                return an < bn ? -1 * dir : 1 * dir;
            }
            const s = coll.compare(String(av || ''), String(bv || ''));
            return s * dir;
        });
        tbody.innerHTML = '';
        items.forEach((r) => {
            const tipsVnd = vndFromMinor(r.tips_minor || 0);
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>${esc(r.user_id)}</td>
                <td>${esc(r.name)}</td>
                <td style="text-align:right;"><input class="rate-input" inputmode="numeric" data-user-id="${esc(r.user_id)}" data-hours="${esc(r.worked_hours)}" data-rate="${esc(r.rate)}" value="${esc(fmtSpaces(String(r.rate || '')))}"></td>
                <td>${esc(r.role_name)}</td>
                <td style="text-align:right;">${esc(r.checks)}</td>
                <td style="text-align:right;">${esc(r.worked_hours)}</td>
                <td style="text-align:right;">${esc(fmtMoney(tipsVnd))}</td>
                <td style="text-align:right;" class="salary-cell" data-user-id="${esc(r.user_id)}">${esc(fmtMoney(r.salary_minor))}</td>
            `;
            tbody.appendChild(tr);
        });
    }

    const withTimeout = (ms = 30000) => {
        const ctrl = new AbortController();
        const t = setTimeout(() => ctrl.abort('timeout'), ms);
        return { signal: ctrl.signal, cleanup: () => clearTimeout(t), controller: ctrl };
    };

    const load = async () => {
        setError('');
        setLoading(true);
        tbody.innerHTML = '';
        prog.style.display = 'flex';
        loader.style.display = 'none';
        progBar.style.width = '0%';
        progLabel.textContent = '0%';
        progDesc.textContent = 'Загрузка данных официантов…';
        cancelBtn.style.display = 'inline-block';
        cancelBtn.disabled = false;
        try {
            const url = new URL(location.href);
            url.searchParams.set('ajax', 'load');
            url.searchParams.set('date_from', dateFrom.value);
            url.searchParams.set('date_to', dateTo.value);
            const res = await fetch(url.toString(), { headers: { 'Accept': 'application/json' } });
            const txt = await res.text();
            let j = null;
            try { j = JSON.parse(txt); } catch (_) {}
            if (!j || !j.ok) throw new Error((j && j.error) ? j.error : 'Ошибка');
            const rows = j.rows || [];
            let aggUser = {};
            let aggName = {};
            let tipsMode = '';
            const prepare = async () => {
                const url = new URL(location.href);
                url.searchParams.set('ajax', 'tips_prepare');
                url.searchParams.set('date_from', dateFrom.value);
                url.searchParams.set('date_to', dateTo.value);
                const { signal, cleanup } = withTimeout(20000);
                const res = await fetch(url.toString(), { headers: { 'Accept': 'application/json' }, signal });
                const t = await res.text();
                let j2 = null;
                try { j2 = JSON.parse(t); } catch (_) {}
                cleanup();
                if (!j2 || !j2.ok) throw new Error((j2 && j2.error) ? j2.error : 'Ошибка подготовки');
                currentJobId = String(j2.job_id || '');
                prog.style.display = 'flex';
                loader.style.display = 'none';
                progBar.style.width = '0%';
                progLabel.textContent = '0%';
                const total = Number(j2.total || 0);
                progDesc.textContent = `Подготовка… дней: 0 из ${total}`;
                return j2;
            };
            const run = async (jobId, total) => {
                let done = 0;
                cancelBtn.style.display = 'inline-block';
                cancelBtn.disabled = false;
                runAbort = new AbortController();
                const abortSignal = runAbort.signal;
                while (done < total) {
                    if (abortSignal.aborted) break;
                    const url = new URL(location.href);
                    url.searchParams.set('ajax', 'tips_run');
                    url.searchParams.set('job_id', jobId);
                    url.searchParams.set('batch', '12');
                    const { signal, cleanup } = withTimeout(30000);
                    const res = await fetch(url.toString(), { headers: { 'Accept': 'application/json' }, signal });
                    const t = await res.text();
                    let j3 = null;
                    try { j3 = JSON.parse(t); } catch (_) {}
                    cleanup();
                    if (!j3 || !j3.ok) throw new Error((j3 && j3.error) ? j3.error : 'Ошибка загрузки чаевых');
                    done = Number(j3.done || 0);
                    const mode = String(j3.tips_mode || '');
                    if (mode) tipsMode = mode;
                    aggUser = j3.agg_user || aggUser;
                    aggName = j3.agg_name || aggName;
                    const pct = total ? Math.round((done / total) * 100) : 100;
                    progBar.style.width = pct + '%';
                    progLabel.textContent = pct + '%';
                    progDesc.textContent = `Дни: ${done} из ${total}`;
                }
                prog.style.display = 'none';
                cancelBtn.style.display = 'none';
                runAbort = null;
            };
            const p = await prepare();
            await run(p.job_id, Number(p.total || 0));
            if (tipsModeEl) {
                const m = String(tipsMode || '');
                if (m) {
                    tipsModeEl.style.display = 'block';
                    tipsModeEl.textContent = m === 'tips_card'
                        ? 'Tips: берутся из dash.getTransactions.tips_card'
                        : (m === 'tip_sum' ? 'Tips: берутся из dash.getTransactions.tip_sum (в getTransactions нет tips_card)' : 'Tips: не найдены в dash.getTransactions');
                } else {
                    tipsModeEl.style.display = 'none';
                    tipsModeEl.textContent = '';
                }
            }
            dataRows = rows.map((r) => {
                const rate = Number(r.rate || 0);
                const hours = Number(r.worked_hours || 0);
                const salary = calcSalary(rate, hours);
                const tipsMinor = (r.user_id && aggUser[String(r.user_id)]) ? Number(aggUser[String(r.user_id)]) : Number(aggName[String((r.name || '').toLowerCase())] || 0);
                return {
                    user_id: Number(r.user_id || 0),
                    name: String(r.name || ''),
                    role_name: String(r.role_name || ''),
                    rate,
                    checks: Number(r.checks || 0),
                    worked_hours: hours,
                    tips_minor: tipsMinor,
                    salary_minor: salary,
                };
            });
            renderTable();

            Array.from(tbody.querySelectorAll('.rate-input')).forEach((inp) => {
                let saving = false;
                const applyFormat = () => {
                    inp.value = fmtSpaces(digitsOnly(inp.value));
                };
                const updateSalary = (rateVal) => {
                    const uid = inp.getAttribute('data-user-id') || '';
                    const hours = Number(inp.getAttribute('data-hours') || 0);
                    const salary = calcSalary(rateVal, hours);
                    const cell = tbody.querySelector(`.salary-cell[data-user-id="${CSS.escape(uid)}"]`);
                    if (cell) cell.textContent = fmtMoney(salary);
                };
                const save = async () => {
                    if (saving) return;
                    const uid = Number(inp.getAttribute('data-user-id') || 0);
                    if (!uid) return;
                    const prev = Number(inp.getAttribute('data-rate') || 0);
                    const next = Number(digitsOnly(inp.value) || 0);
                    if (prev === next) { updateSalary(next); return; }
                    saving = true;
                    inp.disabled = true;
                    try {
                        const url = new URL(location.href);
                        url.searchParams.set('ajax', 'save_rate');
                        const res = await fetch(url.toString(), {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                            body: JSON.stringify({ user_id: uid, rate: String(next) }),
                        });
                        const txt = await res.text();
                        let j = null;
                        try { j = JSON.parse(txt); } catch (_) {}
                        if (!j || !j.ok) throw new Error((j && j.error) ? j.error : 'Ошибка сохранения');
                        const saved = Number(j.rate || 0);
                        inp.setAttribute('data-rate', String(saved));
                        inp.value = fmtSpaces(String(saved));
                        updateSalary(saved);
                    } catch (e) {
                        setError(e && e.message ? e.message : 'Ошибка сохранения');
                        inp.value = fmtSpaces(String(prev));
                        updateSalary(prev);
                    } finally {
                        inp.disabled = false;
                        saving = false;
                    }
                };

                inp.addEventListener('input', () => {
                    applyFormat();
                    updateSalary(Number(digitsOnly(inp.value) || 0));
                }, { passive: true });
                inp.addEventListener('blur', () => { save(); });
                inp.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        save();
                    }
                });
            });
        } catch (e) {
            setError(e && e.message ? e.message : 'Ошибка');
        } finally {
            setLoading(false);
        }
    };

    btn.addEventListener('click', load);
    cancelBtn.addEventListener('click', async () => {
        try {
            cancelBtn.disabled = true;
            if (runAbort) {
                runAbort.abort('user-cancel');
            }
            const url = new URL(location.href);
            url.searchParams.set('ajax', 'tips_cancel');
            url.searchParams.set('job_id', currentJobId || '');
            // best-effort cancel (no need to await)
            fetch(url.toString(), { headers: { 'Accept': 'application/json' } }).catch(() => {});
        } catch (_) {}
        prog.style.display = 'none';
        cancelBtn.style.display = 'none';
        setLoading(false);
    });
})();
</script>
<script src="/assets/user_menu.js" defer></script>
</body>
</html>
