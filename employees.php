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

        $tipsByWaiter = [];
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

                $txArr = $api->request('dash.getTransaction', [
                    'transaction_id' => $txId,
                    'include_history' => 'false',
                    'include_products' => 'false',
                    'include_delivery' => 'false',
                ], 'GET');
                $txFull = is_array($txArr) && isset($txArr[0]) && is_array($txArr[0]) ? $txArr[0] : (is_array($txArr) ? $txArr : []);
                $wName = trim((string)($txFull['name'] ?? $tx['name'] ?? ''));
                if ($wName === '') continue;
                $k = mb_strtolower($wName, 'UTF-8');
                $tipSum = (int)($txFull['tip_sum'] ?? 0);
                $tipsCard = (int)($txFull['tips_card'] ?? 0);
                $tipsCash = (int)($txFull['tips_cash'] ?? 0);
                if (!isset($tipsByWaiter[$k])) {
                    $tipsByWaiter[$k] = ['name' => $wName, 'tip_sum' => 0, 'tips_card' => 0, 'tips_cash' => 0];
                }
                $tipsByWaiter[$k]['tip_sum'] += $tipSum;
                $tipsByWaiter[$k]['tips_card'] += $tipsCard;
                $tipsByWaiter[$k]['tips_cash'] += $tipsCash;
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
            $tips = $tipsByWaiter[$nk] ?? null;
            $out[] = [
                'user_id' => $uid,
                'name' => $name,
                'role_name' => $role,
                'checks' => $clients,
                'worked_hours' => $workedHours,
                'tip_sum' => (int)($tips['tip_sum'] ?? 0),
                'tips_card' => (int)($tips['tips_card'] ?? 0),
                'tips_cash' => (int)($tips['tips_cash'] ?? 0),
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
        echo json_encode(['ok' => true, 'rows' => $out], JSON_UNESCAPED_UNICODE);
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
            <div style="display:flex; gap: 10px; align-items:center;">
                <button type="button" id="loadBtn">ЗАГРУЗИТЬ</button>
                <div class="loader" id="loader"><span class="spinner"></span><span class="muted">Загрузка…</span></div>
            </div>
        </div>
        <div class="error" id="err" style="display:none;"></div>
        <div class="table-wrap" style="margin-top: 12px;">
            <table>
                <thead>
                <tr>
                    <th>user_id</th>
                    <th>name</th>
                    <th style="text-align:right;">Ставка</th>
                    <th>role_name</th>
                    <th style="text-align:right;">Чеков</th>
                    <th style="text-align:right;">ЧасыРаботы</th>
                    <th style="text-align:right;">Tips</th>
                    <th style="text-align:right;">Salary</th>
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

    const load = async () => {
        setError('');
        setLoading(true);
        tbody.innerHTML = '';
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
            (j.rows || []).forEach((r) => {
                const tr = document.createElement('tr');
                const rate = Number(r.rate || 0);
                const hours = Number(r.worked_hours || 0);
                const salary = calcSalary(rate, hours);
                const tipSum = vndFromMinor(r.tip_sum || 0);
                const tipsCash = vndFromMinor(r.tips_cash || 0);
                const tipsCard = vndFromMinor(r.tips_card || 0);
                const tipsTxt = `${fmtMoney(tipSum)} (нал ${fmtMoney(tipsCash)}, карта ${fmtMoney(tipsCard)})`;
                tr.innerHTML = `
                    <td>${esc(r.user_id)}</td>
                    <td>${esc(r.name)}</td>
                    <td style="text-align:right;"><input class="rate-input" inputmode="numeric" data-user-id="${esc(r.user_id)}" data-hours="${esc(hours)}" data-rate="${esc(rate)}" value="${esc(fmtSpaces(String(rate || '')))}"></td>
                    <td>${esc(r.role_name)}</td>
                    <td style="text-align:right;">${esc(r.checks)}</td>
                    <td style="text-align:right;">${esc(r.worked_hours)}</td>
                    <td style="text-align:right;">${esc(tipsTxt)}</td>
                    <td style="text-align:right;" class="salary-cell" data-user-id="${esc(r.user_id)}">${esc(fmtMoney(salary))}</td>
                `;
                tbody.appendChild(tr);
            });

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
})();
</script>
<script src="/assets/user_menu.js" defer></script>
</body>
</html>
