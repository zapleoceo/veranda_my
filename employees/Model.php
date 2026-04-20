<?php
namespace App\Models;

class EmployeesModel {
    private $db;
    private $posterToken;
    private $ratesTable;

    public function __construct($db, $posterToken) {
        $this->db = $db;
        $this->posterToken = $posterToken;
        $this->ratesTable = $db->t('employee_rates');
        $this->initDb();
    }

    private function initDb() {
        try {
            $this->db->query(
                "CREATE TABLE IF NOT EXISTS {$this->ratesTable} (
                    user_id INT NOT NULL,
                    rate BIGINT NOT NULL DEFAULT 0,
                    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    updated_by VARCHAR(255) NULL,
                    PRIMARY KEY (user_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
        } catch (\Throwable $e) {
        }
    }

    private function parseDate(string $s): ?string {
        $t = trim($s);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $t) ? $t : null;
    }

    private function mkDays(string $from, string $to): array {
        $out = [];
        $t1 = strtotime($from . ' 00:00:00');
        $t2 = strtotime($to . ' 00:00:00');
        if ($t1 === false || $t2 === false) return $out;
        if ($t2 < $t1) return $out;
        for ($t = $t1; $t <= $t2; $t += 86400) {
            $out[] = date('Y-m-d', $t);
        }
        return $out;
    }

    public function saveRate() {

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
        $this->db->query(
            "INSERT INTO {$this->ratesTable} (user_id, rate, updated_by) VALUES (?, ?, ?)
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

    public function load() {

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    $dateFrom = $this->parseDate((string)($_GET['date_from'] ?? ''));
    $dateTo = $this->parseDate((string)($_GET['date_to'] ?? ''));
    if ($dateFrom === null || $dateTo === null || $dateFrom > $dateTo) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Некорректный период'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $api = new \App\Classes\PosterAPI($this->posterToken);
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
        $byUid = [];
        foreach ($rows as $r) {
            if (!is_array($r)) continue;
            $uid = (int)($r['user_id'] ?? 0);
            if ($uid <= 0) continue;
            $name = (string)($r['name'] ?? '');
            $clients = (int)($r['clients'] ?? 0);
            $worked = $r['worked_time'] ?? null;
            if ($worked === null) $worked = $r['workedTime'] ?? null;
            if ($worked === null) $worked = $r['middle_time'] ?? null;
            $workedMin = is_numeric($worked) ? (int)round((float)$worked) : 0;
            $workedHours = $workedMin > 0 ? round($workedMin / 60, 2) : 0;
            $byUid[$uid] = [
                'user_id' => $uid,
                'name' => $name,
                'checks' => $clients,
                'worked_hours' => $workedHours,
            ];
        }

        $uids = [];
        foreach ($emps as $e) {
            if (!is_array($e)) continue;
            $uid = (int)($e['user_id'] ?? 0);
            if ($uid <= 0) continue;
            $uids[$uid] = true;
            $empName = trim((string)($e['name'] ?? ''));
            $role = trim((string)($e['role_name'] ?? ''));
            $w = $byUid[$uid] ?? null;
            $out[] = [
                'user_id' => $uid,
                'name' => ($empName !== '' ? $empName : (string)($w['name'] ?? '')),
                'role_name' => $role,
                'checks' => (int)($w['checks'] ?? 0),
                'worked_hours' => (float)($w['worked_hours'] ?? 0),
                'tips_card' => 0,
            ];
        }
        $rateByUser = [];
        $uidList = array_keys($uids);
        if ($uidList) {
            $place = implode(',', array_fill(0, count($uidList), '?'));
            $rateRows = $this->db->query(
                "SELECT user_id, rate FROM {$this->ratesTable} WHERE user_id IN ({$place})",
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

    public function hoursByDay() {

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    $dateFrom = $this->parseDate((string)($_GET['date_from'] ?? ''));
    $dateTo = $this->parseDate((string)($_GET['date_to'] ?? ''));
    $userId = (int)($_GET['user_id'] ?? 0);
    if ($dateFrom === null || $dateTo === null || $dateFrom > $dateTo || $userId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Bad request'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $cacheKey = 'hours_by_day_' . $userId . '_' . $dateFrom . '_' . $dateTo;
    $cached = $_SESSION[$cacheKey] ?? null;
    if (is_array($cached) && isset($cached['created_at']) && is_numeric($cached['created_at']) && (time() - (int)$cached['created_at']) < 3600) {
        echo json_encode(['ok' => true, 'user_id' => $userId, 'date_from' => $dateFrom, 'date_to' => $dateTo, 'days' => $cached['days'] ?? [], 'total_hours' => $cached['total_hours'] ?? 0], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $days = $this->mkDays($dateFrom, $dateTo);
    if (!$days) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Нет дней'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $api = new \App\Classes\PosterAPI($this->posterToken);
        $out = [];
        $total = 0.0;
        foreach ($days as $d) {
            $ymd = str_replace('-', '', $d);
            $rows = $api->request('dash.getWaitersSales', ['dateFrom' => $ymd, 'dateTo' => $ymd], 'GET');
            if (!is_array($rows)) $rows = [];
            $workedMin = 0;
            foreach ($rows as $r) {
                if (!is_array($r)) continue;
                $uid = (int)($r['user_id'] ?? 0);
                if ($uid !== $userId) continue;
                $worked = $r['worked_time'] ?? null;
                if ($worked === null) $worked = $r['workedTime'] ?? null;
                if ($worked === null) $worked = $r['middle_time'] ?? null;
                $workedMin = is_numeric($worked) ? (int)round((float)$worked) : 0;
                break;
            }
            $h = $workedMin > 0 ? round($workedMin / 60, 2) : 0;
            $out[] = ['date' => $d, 'hours' => $h];
            $total += (float)$h;
        }
        $total = round($total, 2);
        $_SESSION[$cacheKey] = ['created_at' => time(), 'days' => $out, 'total_hours' => $total];
        echo json_encode(['ok' => true, 'user_id' => $userId, 'date_from' => $dateFrom, 'date_to' => $dateTo, 'days' => $out, 'total_hours' => $total], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
    }

    public function tipsPrepare() {

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    $dateFrom = $this->parseDate((string)($_GET['date_from'] ?? ''));
    $dateTo = $this->parseDate((string)($_GET['date_to'] ?? ''));
    if ($dateFrom === null || $dateTo === null || $dateFrom > $dateTo) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Некорректный период'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $days = $this->mkDays($dateFrom, $dateTo);
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

    public function tipsRun() {

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
    $token = $this->posterToken;
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

    public function tipsCancel() {

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

    public function paySalary() {

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
    $salaryVnd = (int)($j['salary_vnd'] ?? 0);
    $empName = trim((string)($j['employee_name'] ?? ''));
    if ($empName !== '') {
        $empName = preg_replace('/\s+/u', ' ', $empName);
        $empName = preg_replace('/[^\p{L}\p{N}\s\.\-\'"()]+/u', '', (string)$empName);
        $empName = trim((string)$empName);
        if (mb_strlen($empName, 'UTF-8') > 60) $empName = mb_substr($empName, 0, 60, 'UTF-8');
    }
    if ($waiterId <= 0 || $salaryVnd <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Bad request'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $api = new \App\Classes\PosterAPI($this->posterToken);
        $now = date('Y-m-d H:i:s');
        $by = trim((string)($_SESSION['user_email'] ?? $_SESSION['user_name'] ?? ''));
        $comment = 'SLR' . ($empName !== '' ? (' ' . $empName) : '') . ' ID=' . $waiterId . ($by !== '' ? (' by ' . $by) : '');
        $res = $api->request('finance.createTransactions', [
            'id' => (int)(time() * 1000 + random_int(0, 999)),
            'type' => 0,
            'category' => 6,
            'user_id' => 10,
            'amount_from' => $salaryVnd,
            'account_from' => 1,
            'date' => $now,
            'comment' => $comment,
        ], 'POST');
        echo json_encode(['ok' => true, 'created_id' => (int)$res, 'date' => $now, 'salary_vnd' => $salaryVnd, 'waiter_id' => $waiterId], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
    }

    public function payExtra() {

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
    $amountVnd = (int)($j['amount_vnd'] ?? 0);
    $kind = (string)($j['kind'] ?? '');
    $accountFrom = (int)($j['account_from'] ?? 0);
    $empName = trim((string)($j['employee_name'] ?? ''));
    if ($empName !== '') {
        $empName = preg_replace('/\s+/u', ' ', $empName);
        $empName = preg_replace('/[^\p{L}\p{N}\s\.\-\'"()]+/u', '', (string)$empName);
        $empName = trim((string)$empName);
        if (mb_strlen($empName, 'UTF-8') > 60) $empName = mb_substr($empName, 0, 60, 'UTF-8');
    }
    if ($waiterId <= 0 || $amountVnd <= 0 || $accountFrom <= 0 || ($kind !== 'tips' && $kind !== 'salary')) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Bad request'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $api = new \App\Classes\PosterAPI($this->posterToken);
        $now = date('Y-m-d H:i:s');
        $by = trim((string)($_SESSION['user_email'] ?? $_SESSION['user_name'] ?? ''));
        $prefix = $kind === 'salary' ? 'SLR' : 'TIPS';
        $comment = $prefix . ($empName !== '' ? (' ' . $empName) : '') . ' ID=' . $waiterId . ($by !== '' ? (' by ' . $by) : '');
        $categoryId = $kind === 'salary' ? 6 : 4;
        $res = $api->request('finance.createTransactions', [
            'id' => (int)(time() * 1000 + random_int(0, 999)),
            'type' => 0,
            'category' => $categoryId,
            'user_id' => 10,
            'amount_from' => $amountVnd,
            'account_from' => $accountFrom,
            'date' => $now,
            'comment' => $comment,
        ], 'POST');
        echo json_encode(['ok' => true, 'created_id' => (int)$res, 'date' => $now, 'amount_vnd' => $amountVnd, 'waiter_id' => $waiterId, 'kind' => $kind], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
    }

    public function ltpLoad() {

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    $dateFrom0 = $this->parseDate((string)($_GET['date_from'] ?? ''));
    $dateTo0 = $this->parseDate((string)($_GET['date_to'] ?? ''));
    if ($dateFrom0 === null || $dateTo0 === null || $dateFrom0 > $dateTo0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Некорректный период'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $shift3 = function (string $d): ?string {
        $ts = strtotime($d . ' +3 days');
        if ($ts === false) return null;
        return date('Y-m-d', $ts);
    };
    $dateFrom = $shift3($dateFrom0);
    $dateTo = $shift3($dateTo0);
    if ($dateFrom === null || $dateTo === null || $dateFrom > $dateTo) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Некорректный период'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    try {
        $api = new \App\Classes\PosterAPI($this->posterToken);
        $txs = $api->request('finance.getTransactions', [
            'dateFrom' => str_replace('-', '', $dateFrom),
            'dateTo' => str_replace('-', '', $dateTo),
            'type' => 0,
        ], 'GET');
        if (!is_array($txs)) $txs = [];

        $strictPayerFilter = (string)($_ENV['LTP_STRICT_PAYER_USER_FILTER'] ?? '') === '1';
        $tipsAgg = [];
        $slrAgg = [];

        $pushAgg = function (array &$dst, int $id, int $ts, string $dateStr, int $amount, int $accountId): void {
            if (!isset($dst[$id])) $dst[$id] = ['total' => 0, 'items' => []];
            $dst[$id]['total'] = (int)$dst[$id]['total'] + $amount;
            $dst[$id]['items'][] = ['ts' => $ts, 'date' => $dateStr, 'amount' => $amount, 'account_id' => $accountId];
        };

        foreach ($txs as $t) {
            if (!is_array($t)) continue;
            $type = (string)($t['type'] ?? '');
            if ($type !== '0' && $type !== 'expense' && $type !== 'out') continue;

            $comment = trim((string)($t['comment'] ?? ''));
            if ($comment === '') continue;
            $isTips = stripos($comment, 'TIPS') === 0;
            $isSlr = stripos($comment, 'SLR') === 0;
            if (!$isTips && !$isSlr) continue;
            if (!preg_match('/\bID\s*=\s*(\d+)\b/i', $comment, $m)) continue;
            $empId = (int)$m[1];
            if ($empId <= 0) continue;

            if ($strictPayerFilter) {
                $uid = (int)($t['user_id'] ?? 0);
                if ($uid !== 10 && $uid !== 4) continue;
            } else {
                $cat = isset($t['category']) ? (int)$t['category'] : (isset($t['category_id']) ? (int)$t['category_id'] : 0);
                if ($cat > 0) {
                    if ($isTips && $cat !== 4) continue;
                    if ($isSlr && $cat !== 6) continue;
                }
                $acc = isset($t['account_from']) ? (int)$t['account_from'] : (isset($t['account_id']) ? (int)$t['account_id'] : 0);
                if ($acc > 0) {
                    if ($isTips && $acc !== 8) continue;
                    if ($isSlr && $acc !== 1 && $acc !== 2 && $acc !== 9) continue;
                }
            }

            $dt = $t['date'] ?? '';
            $ts = false;
            if (is_numeric($dt)) {
                $v = (int)$dt;
                if ($v > 10000000000) $v = (int)round($v / 1000);
                if ($v > 0) $ts = $v;
            } else {
                $s = trim((string)$dt);
                if ($s !== '') $ts = strtotime($s);
            }
            if ($ts === false || (int)$ts <= 0) continue;

            $amount = (int)($t['amount'] ?? 0);
            $acc = isset($t['account_from']) ? (int)$t['account_from'] : (isset($t['account_id']) ? (int)$t['account_id'] : 0);
            $dateStr = date('Y-m-d H:i:s', (int)$ts);
            if ($isTips) $pushAgg($tipsAgg, $empId, (int)$ts, $dateStr, $amount, $acc);
            else $pushAgg($slrAgg, $empId, (int)$ts, $dateStr, $amount, $acc);
        }

        $makeOut = function (array $agg): array {
            $out = [];
            foreach ($agg as $id => $v) {
                $items = (array)($v['items'] ?? []);
                usort($items, function ($a, $b) { return ((int)($b['ts'] ?? 0)) <=> ((int)($a['ts'] ?? 0)); });
                $payload = [];
                foreach ($items as $it) {
                    $d = (string)($it['date'] ?? '');
                    if ($d === '') continue;
                    $payload[] = ['date' => $d, 'amount' => (int)($it['amount'] ?? 0), 'account_id' => (int)($it['account_id'] ?? 0)];
                }
                $out[(string)$id] = ['total_amount' => (int)($v['total'] ?? 0), 'items' => $payload];
            }
            return $out;
        };

        echo json_encode(['ok' => true, 'tips' => $makeOut($tipsAgg), 'slr' => $makeOut($slrAgg), 'date_from' => $dateFrom, 'date_to' => $dateTo], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
    }

    public function payMeta() {

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    try {
        $api = new \App\Classes\PosterAPI($this->posterToken);
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

    public function payMetaSalary() {

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    try {
        $api = new \App\Classes\PosterAPI($this->posterToken);
        $categoryName = '';
        $accountName = '';
        $payerName = '';

        try {
            $cats = $api->request('finance.getCategories', [], 'GET');
            if (is_array($cats)) {
                foreach ($cats as $c) {
                    if (!is_array($c)) continue;
                    if ((int)($c['category_id'] ?? 0) === 6) {
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
                    if ((int)($a['account_id'] ?? $a['id'] ?? 0) === 1) {
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
            'category' => ['id' => 6, 'name' => $categoryName],
            'account_from' => ['id' => 1, 'name' => $accountName],
            'payer' => ['id' => 10, 'name' => $payerName],
        ], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
    }

    public function payMetaExtra() {

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    try {
        $api = new \App\Classes\PosterAPI($this->posterToken);
        $accounts = [];
        try {
            $accs = $api->request('finance.getAccounts', [], 'GET');
            if (is_array($accs)) {
                foreach ($accs as $a) {
                    if (!is_array($a)) continue;
                    $id = (int)($a['account_id'] ?? $a['id'] ?? 0);
                    if ($id <= 0) continue;
                    $name = trim((string)($a['name'] ?? $a['title'] ?? ''));
                    $accounts[] = ['id' => $id, 'name' => $name];
                }
            }
        } catch (\Throwable $e) {
        }

        $payerName = '';
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

        $catTipsName = '';
        $catSalaryName = '';
        try {
            $cats = $api->request('finance.getCategories', [], 'GET');
            if (is_array($cats)) {
                foreach ($cats as $c) {
                    if (!is_array($c)) continue;
                    $cid = (int)($c['category_id'] ?? 0);
                    if ($cid === 4) $catTipsName = trim((string)($c['name'] ?? ''));
                    if ($cid === 6) $catSalaryName = trim((string)($c['name'] ?? ''));
                }
            }
        } catch (\Throwable $e) {
        }

        echo json_encode([
            'ok' => true,
            'categories' => [
                'tips' => ['id' => 4, 'name' => $catTipsName],
                'salary' => ['id' => 6, 'name' => $catSalaryName],
            ],
            'payer' => ['id' => 10, 'name' => $payerName],
            'accounts' => $accounts,
        ], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
    }

    public function tipsBalance() {

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    try {
        $api = new \App\Classes\PosterAPI($this->posterToken);
        $accs = $api->request('finance.getAccounts', [], 'GET');
        if (!is_array($accs)) $accs = [];
        $accountId = 0;
        $name = '';
        $balanceMinor = null;

        foreach ($accs as $a) {
            if (!is_array($a)) continue;
            $id = (int)($a['account_id'] ?? $a['id'] ?? 0);
            if ($id <= 0) continue;
            $n = trim((string)($a['name'] ?? $a['title'] ?? ''));
            if ($n === '') continue;
            if (stripos($n, 'BIDV') !== false) { $accountId = $id; $name = $n; break; }
        }
        if ($accountId <= 0) {
            foreach ($accs as $a) {
                if (!is_array($a)) continue;
                $id = (int)($a['account_id'] ?? $a['id'] ?? 0);
                if ($id !== 8) continue;
                $accountId = 8;
                $name = trim((string)($a['name'] ?? $a['title'] ?? ''));
                break;
            }
        }

        if ($accountId > 0) {
            foreach ($accs as $a) {
                if (!is_array($a)) continue;
                $id = (int)($a['account_id'] ?? $a['id'] ?? 0);
                if ($id !== $accountId) continue;
                $name = $name !== '' ? $name : trim((string)($a['name'] ?? $a['title'] ?? ''));
                $bRaw = $a['balance'] ?? null;

                $hasDecimal = false;
                if (is_string($bRaw)) {
                    $t = trim((string)$bRaw);
                    $hasDecimal = (strpos($t, '.') !== false) || (strpos($t, ',') !== false);
                } elseif (is_float($bRaw)) {
                    $hasDecimal = true;
                }

                $val = null;
                if (is_int($bRaw)) $val = (float)$bRaw;
                elseif (is_float($bRaw)) $val = (float)$bRaw;
                elseif (is_string($bRaw)) {
                    $t = trim((string)$bRaw);
                    $t = str_replace(',', '.', $t);
                    if ($t !== '' && is_numeric($t)) $val = (float)$t;
                } elseif (is_numeric($bRaw)) {
                    $val = (float)$bRaw;
                }

                if ($val !== null) {
                    if ($hasDecimal) $balanceMinor = (int)round($val * 100);
                    else $balanceMinor = (int)(abs($val) >= 10000000 ? round($val) : round($val * 100));
                }
                break;
            }
        }
        echo json_encode([
            'ok' => true,
            'account_id' => $accountId,
            'name' => $name,
            'balance_minor' => $balanceMinor !== null ? (int)$balanceMinor : null,
        ], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
    }

    public function employeeLookup() {

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    $userId = (int)($_GET['user_id'] ?? 0);
    if ($userId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Bad request'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    try {
        $api = new \App\Classes\PosterAPI($this->posterToken);
        $emps = $api->request('access.getEmployees', [], 'GET');
        if (!is_array($emps)) $emps = [];
        $name = '';
        foreach ($emps as $e) {
            if (!is_array($e)) continue;
            if ((int)($e['user_id'] ?? 0) === $userId) {
                $name = trim((string)($e['name'] ?? ''));
                break;
            }
        }
        echo json_encode(['ok' => true, 'user_id' => $userId, 'name' => $name], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
    }

    public function payTips() {

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
    $empName = trim((string)($j['employee_name'] ?? ''));
    if ($empName !== '') {
        $empName = preg_replace('/\s+/u', ' ', $empName);
        $empName = preg_replace('/[^\p{L}\p{N}\s\.\-\'"()]+/u', '', (string)$empName);
        $empName = trim((string)$empName);
        if (mb_strlen($empName, 'UTF-8') > 60) $empName = mb_substr($empName, 0, 60, 'UTF-8');
    }
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
        $api = new \App\Classes\PosterAPI($this->posterToken);
        $now = date('Y-m-d H:i:s');
        $by = trim((string)($_SESSION['user_email'] ?? $_SESSION['user_name'] ?? ''));
        $comment = 'TIPS' . ($empName !== '' ? (' ' . $empName) : '') . ' ID=' . $waiterId . ($by !== '' ? (' by ' . $by) : '');
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

}
