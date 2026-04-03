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
            $out[] = [
                'user_id' => $uid,
                'name' => $name,
                'role_name' => $role,
                'checks' => $clients,
                'worked_hours' => $workedHours,
                'tips_card' => 0,
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
        echo json_encode(['ok' => true, 'rows' => $out, 'tips_mode' => 'client'], JSON_UNESCAPED_UNICODE);
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

if (($_GET['ajax'] ?? '') === 'ltp_load') {
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
        $txs = $api->request('finance.getTransactions', [
            'dateFrom' => str_replace('-', '', $dateFrom),
            'dateTo' => str_replace('-', '', $dateTo),
            'type' => 0,
        ], 'GET');
        if (!is_array($txs)) $txs = [];

        $latest = [];
        foreach ($txs as $t) {
            if (!is_array($t)) continue;
            $type = (string)($t['type'] ?? '');
            if ($type !== '0' && $type !== 'expense' && $type !== 'out') continue;
            $uid = (int)($t['user_id'] ?? 0);
            if ($uid !== 10 && $uid !== 4) continue;
            $comment = trim((string)($t['comment'] ?? ''));
            if ($comment === '' || stripos($comment, 'TIPS') !== 0) continue;
            if (!preg_match('/\bID\s*=\s*(\d+)\b/i', $comment, $m)) continue;
            $waiterId = (int)$m[1];
            if ($waiterId <= 0) continue;
            $dt = (string)($t['date'] ?? '');
            $ts = $dt !== '' ? strtotime($dt) : false;
            if ($ts === false || $ts <= 0) continue;
            $amount = (int)($t['amount'] ?? 0);
            $cur = $latest[$waiterId] ?? null;
            if (!$cur || (int)$cur['ts'] < $ts) {
                $latest[$waiterId] = ['ts' => $ts, 'date' => date('Y-m-d H:i:s', $ts), 'amount' => $amount];
            }
        }
        $out = [];
        foreach ($latest as $wid => $v) {
            $out[(string)$wid] = ['date' => (string)$v['date'], 'amount' => (int)$v['amount']];
        }
        echo json_encode(['ok' => true, 'items' => $out], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if (($_GET['ajax'] ?? '') === 'pay_meta') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    try {
        $api = new \App\Classes\PosterAPI($posterToken);
        $categoryName = '';
        $accountName = '';
        $payerName = '';

        try {
            $cats = $api->request('finance.getCategories', [], 'GET');
            if (is_array($cats)) {
                foreach ($cats as $c) {
                    if (!is_array($c)) continue;
                    if ((int)($c['category_id'] ?? 0) === 4) {
                        $categoryName = trim((string)($c['name'] ?? ''));
                        break;
                    }
                }
            }
        } catch (\Throwable $e) {
        }

        try {
            $accs = $api->request('finance.getAccounts', [], 'GET');
            if (is_array($accs)) {
                foreach ($accs as $a) {
                    if (!is_array($a)) continue;
                    if ((int)($a['account_id'] ?? $a['id'] ?? 0) === 8) {
                        $accountName = trim((string)($a['name'] ?? $a['title'] ?? ''));
                        break;
                    }
                }
            }
        } catch (\Throwable $e) {
        }

        try {
            $emps = $api->request('access.getEmployees', [], 'GET');
            if (is_array($emps)) {
                foreach ($emps as $e) {
                    if (!is_array($e)) continue;
                    if ((int)($e['user_id'] ?? 0) === 10) {
                        $payerName = trim((string)($e['name'] ?? ''));
                        break;
                    }
                }
            }
        } catch (\Throwable $e) {
        }

        echo json_encode([
            'ok' => true,
            'category' => ['id' => 4, 'name' => $categoryName],
            'account_from' => ['id' => 8, 'name' => $accountName],
            'payer' => ['id' => 10, 'name' => $payerName],
        ], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if (($_GET['ajax'] ?? '') === 'pay_tips') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    if (!veranda_can('admin')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $raw = file_get_contents('php://input');
    $j = json_decode((string)$raw, true);
    if (!is_array($j)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Bad request'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $waiterId = (int)($j['waiter_id'] ?? 0);
    $tipsMinor = (int)($j['tips_minor'] ?? 0);
    if ($waiterId <= 0 || $tipsMinor <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Bad request'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $amount = (int)round($tipsMinor / 100);
    if ($amount <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Bad request'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $api = new \App\Classes\PosterAPI($posterToken);
        $now = date('Y-m-d H:i:s');
        $by = trim((string)($_SESSION['user_email'] ?? $_SESSION['user_name'] ?? ''));
        $comment = 'TIPS ID=' . $waiterId . ($by !== '' ? (' by ' . $by) : '');
        $res = $api->request('finance.createTransactions', [
            'id' => (int)(time() * 1000 + random_int(0, 999)),
            'type' => 0,
            'category' => 4,
            'user_id' => 10,
            'amount_from' => $amount,
            'account_from' => 8,
            'date' => $now,
            'comment' => $comment,
        ], 'POST');
        echo json_encode(['ok' => true, 'created_id' => (int)$res, 'date' => $now, 'amount' => -abs($tipsMinor), 'waiter_id' => $waiterId], JSON_UNESCAPED_UNICODE);
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
        .rate-input { width: 58px; padding: 6px 8px; border: 1px solid #ddd; border-radius: 8px; text-align: right; font-variant-numeric: tabular-nums; }
        .progress { display:none; align-items:center; gap: 10px; margin-left: 10px; }
        .progress .bar { width: 160px; height: 10px; border-radius: 999px; background: #eee; overflow: hidden; }
        .progress .bar > span { display:block; height: 100%; width: 0; background: #1a73e8; transition: width 0.15s ease; }
        .progress .label { font-size: 12px; color:#1f2937; font-weight: 800; }
        .progress .desc { font-size: 12px; color:#6b7280; }
        #totals { white-space: nowrap; overflow-x: auto; }
        .ltp { margin-top: 2px; font-size: 11px; color: #6b7280; font-weight: 800; white-space: nowrap; }
        .paid-btn { border: 1px solid rgba(26,115,232,0.35); background: rgba(26,115,232,0.08); color: #1a73e8; border-radius: 8px; padding: 2px 7px; font-weight: 900; font-size: 11px; cursor: pointer; line-height: 1.2; }
        .paid-btn:disabled { opacity: 0.6; cursor: default; }
        .modal-backdrop { position: fixed; inset: 0; background: rgba(17, 24, 39, 0.55); display: none; align-items: center; justify-content: center; z-index: 2000; padding: 16px; }
        .modal { width: 100%; max-width: 460px; background: #fff; border-radius: 14px; border: 1px solid rgba(0,0,0,0.08); box-shadow: 0 18px 45px rgba(0,0,0,0.22); padding: 14px; }
        .modal h3 { margin: 0 0 10px; font-size: 16px; }
        .modal .body { color: #111827; font-weight: 700; line-height: 1.35; }
        .modal .sub { margin-top: 10px; color: #6b7280; font-weight: 800; font-size: 12px; }
        .modal .actions { display:flex; gap: 10px; justify-content: flex-end; margin-top: 12px; }
        .modal .btn2 { padding: 10px 14px; border-radius: 10px; border: 1px solid #d0d5dd; background: #fff; font-weight: 900; cursor: pointer; }
        .modal .btn2.primary { background: #1a73e8; border-color: #1a73e8; color: #fff; }
        .modal .btn2:disabled { opacity: 0.6; cursor: default; }
        .help-btn { width: 28px; height: 28px; border-radius: 999px; border: 1px solid rgba(26,115,232,0.35); background: rgba(26,115,232,0.08); color: #1a73e8; font-weight: 900; cursor: pointer; display:inline-flex; align-items:center; justify-content:center; line-height: 1; padding: 0; }
        .help-btn:hover { background: rgba(26,115,232,0.12); }
        .toggle-wrap { display:flex; align-items:center; gap: 8px; font-weight: 900; font-size: 12px; color:#374151; }
        .toggle-wrap .toggle-text { user-select:none; }
        .switch { position: relative; display:inline-block; width: 52px; height: 28px; flex: 0 0 auto; }
        .switch input { opacity: 0; width: 0; height: 0; }
        .slider { position:absolute; cursor:pointer; top:0; left:0; right:0; bottom:0; background:#d1d5db; transition: 180ms; border-radius: 999px; }
        .slider:before { position:absolute; content:""; height: 22px; width: 22px; left: 3px; bottom: 3px; background: #fff; transition: 180ms; border-radius: 999px; box-shadow: 0 1px 2px rgba(0,0,0,0.2); }
        .switch input:checked + .slider { background:#1a73e8; }
        .switch input:checked + .slider:before { transform: translateX(24px); }
        #empTable.lite .col-id,
        #empTable.lite .col-role,
        #empTable.lite .col-checks,
        #empTable.lite .col-hours { display: none; }
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
                <button type="button" class="help-btn" id="helpBtn" title="Инструкция">?</button>
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
        <div style="display:flex; gap: 14px; align-items:center; flex-wrap: wrap; margin-top: 10px;">
            <label class="muted" style="display:flex; align-items:center; gap: 8px; margin: 0;">
                <input type="checkbox" id="hideZero">
                Скрыть нулевые
            </label>
            <div class="toggle-wrap" title="Lite/Full">
                <span class="toggle-text">Lite</span>
                <label class="switch">
                    <input type="checkbox" id="modeLite">
                    <span class="slider"></span>
                </label>
                <span class="toggle-text">Full</span>
            </div>
        </div>
        <div class="table-wrap" style="margin-top: 12px;">
            <table id="empTable">
                <thead>
                <tr>
                    <th id="thUid" class="col-id" data-sort="user_id" style="cursor:pointer;">ID</th>
                    <th id="thName" class="col-name" data-sort="name" style="cursor:pointer;">name</th>
                    <th id="thRate" class="col-rate" data-sort="rate" style="text-align:right; cursor:pointer;">Rate</th>
                    <th id="thRole" class="col-role" data-sort="role_name" style="cursor:pointer;">role_name</th>
                    <th id="thChecks" class="col-checks" data-sort="checks" style="text-align:right; cursor:pointer;">Чеков</th>
                    <th id="thHours" class="col-hours" data-sort="worked_hours" style="text-align:right; cursor:pointer;">ЧасыРаботы</th>
                    <th id="thTips" class="col-tips" data-sort="tips_minor" style="text-align:right; cursor:pointer;">Tips</th>
                    <th id="thSalary" class="col-salary" data-sort="salary_minor" style="text-align:right; cursor:pointer;">Salary</th>
                </tr>
                </thead>
                <tbody id="tbody"></tbody>
            </table>
        </div>
        <div class="muted" id="totals" style="margin-top: 10px; text-align:right; font-weight:900;">Итого: Чеков 0 · ЧасыРаботы 0 · Tips 0 · Salary 0</div>
    </div>
</div>

<div class="modal-backdrop" id="paidModal">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="paidTitle">
        <h3 id="paidTitle">Подтверждение</h3>
        <div class="body" id="paidText"></div>
        <div class="sub">
            <label style="display:flex; align-items:center; gap: 8px; margin: 0;">
                <input type="checkbox" id="paidChecked">
                да проверил
            </label>
        </div>
        <div class="actions">
            <button type="button" class="btn2" id="paidCancel">Отмена</button>
            <button type="button" class="btn2 primary" id="paidOk" disabled>OK</button>
        </div>
    </div>
</div>

<div class="modal-backdrop" id="helpModal">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="helpTitle">
        <h3 id="helpTitle">Инструкция</h3>
        <div class="body">
            <div style="margin-bottom: 10px;">
                <b>ЗАГРУЗИТЬ</b> — загружает данные по официантам за выбранный период и считает Tips.
            </div>
            <div style="margin-bottom: 10px;">
                <b>Отменить</b> — останавливает текущую загрузку, если долго ждём.
            </div>
            <div style="margin-bottom: 10px;">
                <b>Lite / Full</b> — переключает вид таблицы: Lite показывает только самое важное (name, Rate, Tips, Salary).
            </div>
            <div style="margin-bottom: 10px;">
                <b>Скрыть нулевые</b> — скрывает строки, где ЧасыРаботы = 0.
            </div>
            <div style="margin-bottom: 10px;">
                <b>Rate</b> — ставка. Можно редактировать, сохраняется автоматически при выходе из поля или по Enter.
            </div>
            <div style="margin-bottom: 10px;">
                <b>PAID</b> — создаёт финансовую транзакцию “выплата типсов” на сумму Tips для выбранного сотрудника.
                Перед созданием надо подтвердить чекбоксом “да проверил”.
            </div>
            <div>
                <b>LTP</b> — дата и сумма последней выплаты типсов по этому сотруднику (берётся из финансовых транзакций Poster).
            </div>
        </div>
        <div class="actions">
            <button type="button" class="btn2 primary" id="helpClose">OK</button>
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
    const prog = document.getElementById('prog');
    const progBar = document.getElementById('progBar');
    const progLabel = document.getElementById('progLabel');
    const progDesc = document.getElementById('progDesc');
    const cancelBtn = document.getElementById('cancelBtn');
    const hideZeroCb = document.getElementById('hideZero');
    const modeLiteCb = document.getElementById('modeLite');
    const totalsEl = document.getElementById('totals');
    const empTable = document.getElementById('empTable');
    const paidModal = document.getElementById('paidModal');
    const paidText = document.getElementById('paidText');
    const paidChecked = document.getElementById('paidChecked');
    const paidCancel = document.getElementById('paidCancel');
    const paidOk = document.getElementById('paidOk');
    const helpBtn = document.getElementById('helpBtn');
    const helpModal = document.getElementById('helpModal');
    const helpClose = document.getElementById('helpClose');
    let runAbort = null;
    let currentJobId = '';
    let paidResolve = null;

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
    let hideZero = !!prefs.hide_zero;
    if (hideZeroCb) hideZeroCb.checked = hideZero;
    let viewMode = (prefs.view_mode === 'lite') ? 'lite' : 'full';
    if (modeLiteCb) modeLiteCb.checked = viewMode === 'lite';
    if (empTable) empTable.classList.toggle('lite', viewMode === 'lite');
    dateFrom.addEventListener('change', () => { const p = loadPrefs(); p.date_from = dateFrom.value; savePrefs(p); });
    dateTo.addEventListener('change', () => { const p = loadPrefs(); p.date_to = dateTo.value; savePrefs(p); });
    if (hideZeroCb) hideZeroCb.addEventListener('change', () => {
        hideZero = !!hideZeroCb.checked;
        const p = loadPrefs(); p.hide_zero = hideZero; savePrefs(p);
        renderTable();
    });
    if (modeLiteCb) modeLiteCb.addEventListener('change', () => {
        viewMode = modeLiteCb.checked ? 'lite' : 'full';
        const p = loadPrefs(); p.view_mode = viewMode; savePrefs(p);
        if (empTable) empTable.classList.toggle('lite', viewMode === 'lite');
        renderTable();
    });
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
    let ltpById = {};
    let payMeta = null;
    const boundRateIds = new Set();
    function bindRateInputs() {
        Array.from(tbody.querySelectorAll('.rate-input')).forEach((inp) => {
            const key = inp.getAttribute('data-user-id') || '';
            if (!key) return;
            if (inp.getAttribute('data-bound') === '1') return;
            inp.setAttribute('data-bound', '1');

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
                    const row = dataRows.find((x) => Number(x.user_id) === uid);
                    if (row) {
                        row.rate = saved;
                        row.salary_minor = calcSalary(saved, row.worked_hours);
                    }
                    updateSalary(saved);
                    renderTable();
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
    }
    function renderTable() {
        const coll = new Intl.Collator('ru', { numeric: true, sensitivity: 'base' });
        const dir = sortDir === 'desc' ? -1 : 1;
        const filtered = hideZero ? dataRows.filter((r) => Number(r.worked_hours || 0) > 0) : dataRows.slice();
        const items = filtered.slice().sort((a, b) => {
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
        let totChecks = 0;
        let totHours = 0;
        let totTipsMinor = 0;
        let totSalary = 0;
        items.forEach((r) => {
            const tipsVnd = vndFromMinor(r.tips_minor || 0);
            const ltp = ltpById[String(r.user_id)] || null;
            const ltpAmtMinor = ltp ? Number(ltp.amount || 0) : 0;
            const ltpDate = ltp && ltp.date ? String(ltp.date) : '';
            const ltpAmt = (ltpAmtMinor && isFinite(ltpAmtMinor)) ? fmtMoney(vndFromMinor(Math.abs(ltpAmtMinor))) : '';
            const ltpHtml = (ltpDate && ltpAmt) ? `<div class="ltp" title="Сумма и дата последней выплаты типсов">LTP ${esc(ltpDate)} · ${esc(ltpAmt)}</div>` : '';
            const tr = document.createElement('tr');
            totChecks += Number(r.checks || 0);
            totHours += Number(r.worked_hours || 0);
            totTipsMinor += Number(r.tips_minor || 0);
            totSalary += Number(r.salary_minor || 0);
            const paidDisabled = Number(r.tips_minor || 0) <= 0 ? 'disabled' : '';
            tr.innerHTML = `
                <td class="col-id">${esc(r.user_id)}</td>
                <td class="col-name"><div>${esc(r.name)}</div>${ltpHtml}</td>
                <td class="col-rate" style="text-align:right;"><input class="rate-input" inputmode="numeric" data-user-id="${esc(r.user_id)}" data-hours="${esc(r.worked_hours)}" data-rate="${esc(r.rate)}" value="${esc(fmtSpaces(String(r.rate || '')))}"></td>
                <td class="col-role">${esc(r.role_name)}</td>
                <td class="col-checks" style="text-align:right;">${esc(r.checks)}</td>
                <td class="col-hours" style="text-align:right;">${esc(r.worked_hours)}</td>
                <td class="col-tips" style="text-align:right;">
                    <div style="display:inline-flex; align-items:center; justify-content:flex-end; gap: 6px; width: 100%;">
                        <span>${esc(fmtMoney(tipsVnd))}</span>
                        <button type="button" class="paid-btn" data-user-id="${esc(r.user_id)}" ${paidDisabled}>PAID</button>
                    </div>
                </td>
                <td class="col-salary salary-cell" style="text-align:right;" data-user-id="${esc(r.user_id)}">${esc(fmtMoney(r.salary_minor))}</td>
            `;
            tbody.appendChild(tr);
        });
        bindRateInputs();
        if (totalsEl) {
            if (viewMode === 'lite') {
                totalsEl.textContent = `Итого: Tips ${fmtMoney(vndFromMinor(totTipsMinor))} · Salary ${fmtMoney(totSalary)}`;
            } else {
                const hoursTxt = (Math.round(totHours * 100) / 100).toFixed(2).replace(/\.00$/, '');
                totalsEl.textContent = `Итого: Чеков ${fmtMoney(totChecks)} · ЧасыРаботы ${hoursTxt} · Tips ${fmtMoney(vndFromMinor(totTipsMinor))} · Salary ${fmtMoney(totSalary)}`;
            }
        }
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
        let basePct = 0;
        const tick = setInterval(() => {
            if (basePct >= 20) return;
            basePct += 1;
            progBar.style.width = basePct + '%';
            progLabel.textContent = basePct + '%';
        }, 300);
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
            clearInterval(tick);
            progBar.style.width = '20%';
            progLabel.textContent = '20%';
            progDesc.textContent = 'Подготовка загрузки Tips…';
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
                const total = Number(j2.total || 0);
                if (basePct < 25) {
                    basePct = 25;
                    progBar.style.width = basePct + '%';
                    progLabel.textContent = basePct + '%';
                }
                progDesc.textContent = `Подготовка Tips… дней: 0 из ${total}`;
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
                    const pct = total ? (25 + Math.round((done / total) * 75)) : 100;
                    progBar.style.width = pct + '%';
                    progLabel.textContent = pct + '%';
                    progDesc.textContent = `Дни: ${done} из ${total}`;
                }
                runAbort = null;
            };
            const p = await prepare();
            await run(p.job_id, Number(p.total || 0));

            try {
                progBar.style.width = '98%';
                progLabel.textContent = '98%';
                progDesc.textContent = 'Загрузка LTP…';
                const urlLtp = new URL(location.href);
                urlLtp.searchParams.set('ajax', 'ltp_load');
                urlLtp.searchParams.set('date_from', dateFrom.value);
                urlLtp.searchParams.set('date_to', dateTo.value);
                const { signal, cleanup } = withTimeout(20000);
                const resLtp = await fetch(urlLtp.toString(), { headers: { 'Accept': 'application/json' }, signal });
                const txtLtp = await resLtp.text();
                cleanup();
                let jLtp = null;
                try { jLtp = JSON.parse(txtLtp); } catch (_) {}
                if (jLtp && jLtp.ok && jLtp.items) {
                    ltpById = jLtp.items || {};
                } else {
                    ltpById = {};
                }
            } catch (_) {
                ltpById = {};
            }

            try {
                await loadPayMeta();
            } catch (_) {
            }

            progBar.style.width = '100%';
            progLabel.textContent = '100%';
            progDesc.textContent = 'Готово';
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
            prog.style.display = 'none';
            cancelBtn.style.display = 'none';
        } catch (e) {
            try { clearInterval(tick); } catch (_) {}
            setError(e && e.message ? e.message : 'Ошибка');
        } finally {
            setLoading(false);
        }
    };

    btn.addEventListener('click', load);

    const openPaidConfirm = (html) => new Promise((resolve) => {
        if (!paidModal || !paidText || !paidChecked || !paidCancel || !paidOk) return resolve(false);
        paidResolve = resolve;
        paidText.innerHTML = html;
        paidChecked.checked = false;
        paidOk.disabled = true;
        paidModal.style.display = 'flex';
        paidCancel.focus();
    });
    const closePaidConfirm = (ok) => {
        if (!paidModal) return;
        paidModal.style.display = 'none';
        const r = paidResolve;
        paidResolve = null;
        if (r) r(!!ok);
    };
    if (paidChecked) paidChecked.addEventListener('change', () => { if (paidOk) paidOk.disabled = !paidChecked.checked; });
    if (paidCancel) paidCancel.addEventListener('click', () => closePaidConfirm(false));
    if (paidOk) paidOk.addEventListener('click', () => closePaidConfirm(true));
    if (paidModal) {
        paidModal.addEventListener('click', (e) => { if (e.target === paidModal) closePaidConfirm(false); });
        document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && paidModal.style.display === 'flex') closePaidConfirm(false); });
    }

    const openHelp = () => { if (helpModal) helpModal.style.display = 'flex'; };
    const closeHelp = () => { if (helpModal) helpModal.style.display = 'none'; };
    if (helpBtn) helpBtn.addEventListener('click', openHelp);
    if (helpClose) helpClose.addEventListener('click', closeHelp);
    if (helpModal) {
        helpModal.addEventListener('click', (e) => { if (e.target === helpModal) closeHelp(); });
        document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && helpModal.style.display === 'flex') closeHelp(); });
    }

    const loadPayMeta = async () => {
        if (payMeta) return payMeta;
        const url = new URL(location.href);
        url.searchParams.set('ajax', 'pay_meta');
        const { signal, cleanup } = withTimeout(15000);
        const res = await fetch(url.toString(), { headers: { 'Accept': 'application/json' }, signal });
        const txt = await res.text();
        cleanup();
        let j = null;
        try { j = JSON.parse(txt); } catch (_) {}
        if (!j || !j.ok) throw new Error((j && j.error) ? j.error : 'Ошибка');
        payMeta = j;
        return payMeta;
    };

    tbody.addEventListener('click', async (e) => {
        const b = e.target && e.target.closest ? e.target.closest('.paid-btn') : null;
        if (!b) return;
        const uid = Number(b.getAttribute('data-user-id') || 0);
        if (!uid) return;
        const row = dataRows.find((x) => Number(x.user_id) === uid);
        if (!row) return;
        const tipsMinor = Number(row.tips_minor || 0);
        if (tipsMinor <= 0) return;
        const tipsVnd = vndFromMinor(tipsMinor);
        let meta = null;
        try { meta = await loadPayMeta(); } catch (_) { meta = null; }
        const catName = meta && meta.category && meta.category.name ? String(meta.category.name) : '#4';
        const accName = meta && meta.account_from && meta.account_from.name ? String(meta.account_from.name) : '#8';
        const payerName = meta && meta.payer && meta.payer.name ? String(meta.payer.name) : '#10';
        const ok = await openPaidConfirm(
            `Будет создана транзакция расхода на выплату типсов.<br>` +
            `ID сотрудника: <b>${esc(uid)}</b><br>` +
            `Сумма: <b>${esc(fmtMoney(tipsVnd))}</b><br>` +
            `Категория: <b>${esc(catName)}</b><br>` +
            `Исполнитель: <b>${esc(payerName)}</b><br>` +
            `Счет списания: <b>${esc(accName)}</b><br>` +
            `Комментарий: <b>TIPS ID=${esc(uid)}</b> + email создателя`
        );
        if (!ok) return;
        b.disabled = true;
        try {
            const url = new URL(location.href);
            url.searchParams.set('ajax', 'pay_tips');
            const res = await fetch(url.toString(), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({ waiter_id: uid, tips_minor: tipsMinor }),
            });
            const txt = await res.text();
            let j = null;
            try { j = JSON.parse(txt); } catch (_) {}
            if (!j || !j.ok) throw new Error((j && j.error) ? j.error : 'Ошибка');
            ltpById[String(uid)] = { date: String(j.date || ''), amount: Number(j.amount || 0) };
            row.tips_minor = 0;
            renderTable();
        } catch (err) {
            setError(err && err.message ? err.message : 'Ошибка');
            b.disabled = false;
        }
    });
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
