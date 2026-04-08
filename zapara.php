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

if (!function_exists('zapara_poster_request')) {
    function zapara_poster_request(string $token, string $method, array $params, int $timeoutSec = 35): array {
        $params['token'] = $token;
        $url = 'https://joinposter.com/api/' . $method . '?' . http_build_query($params);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSec);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
        curl_setopt($ch, CURLOPT_ENCODING, '');
        $body = curl_exec($ch);
        $err = curl_error($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($err) {
            throw new Exception('CURL Error: ' . $err);
        }
        if (!is_string($body) || $body === '') {
            throw new Exception('Poster API Error: empty response (http=' . $http . ', method=' . $method . ')');
        }
        $j = json_decode($body, true);
        if (!is_array($j)) {
            throw new Exception('Poster API Error: invalid json (http=' . $http . ', method=' . $method . ')');
        }
        if (isset($j['error']) || isset($j['error_code'])) {
            $msg = (string)($j['error'] ?? $j['message'] ?? 'Poster API error');
            $code = (string)($j['error_code'] ?? '');
            throw new Exception(trim(($code !== '' ? ($code . ': ') : '') . $msg));
        }
        $resp = $j['response'] ?? $j;
        return is_array($resp) ? $resp : [];
    }
}

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

    $hours = [];
    for ($h = 9; $h <= 23; $h++) $hours[] = $h;
    $countsByHourChecks = [];
    $countsByHourDishes = [];
    foreach ($hours as $h) {
        $countsByHourChecks[(string)$h] = 0;
        $countsByHourDishes[(string)$h] = 0;
    }

    $tz = new DateTimeZone('Asia/Ho_Chi_Minh');
    $totalChecks = 0;
    $totalDishes = 0;

    try {
        $nextTr = null;
        $prevNextTr = null;
        $guard = 0;
        $seenTx = [];
        do {
            $guard++;
            if ($guard > 20000) break;
            $params = [
                'dateFrom' => str_replace('-', '', $date),
                'dateTo' => str_replace('-', '', $date),
                'status' => 2,
                'include_products' => 'true',
                'include_history' => 'false',
                'include_delivery' => 'false',
                'timezone' => 'client',
            ];
            if ($nextTr !== null) $params['next_tr'] = $nextTr;
            $batch = zapara_poster_request($posterToken, 'dash.getTransactions', $params, 40);
            if (!is_array($batch)) $batch = [];
            if (count($batch) === 0) break;

            $last = end($batch);
            $nextTrCandidate = is_array($last) ? ($last['transaction_id'] ?? null) : null;

            foreach ($batch as $tx) {
                if (!is_array($tx)) continue;
                $txId = (int)($tx['transaction_id'] ?? 0);
                if ($txId <= 0) continue;
                if (isset($seenTx[$txId])) continue;
                $seenTx[$txId] = true;
                $v = $tx['date_start_new'] ?? $tx['date_start'] ?? null;
                if ($v === null) continue;
                $ts = (int)$v;
                if ($ts > 10000000000) $ts = (int)round($ts / 1000);
                if ($ts <= 0) continue;
                $dt = (new DateTimeImmutable('@' . $ts))->setTimezone($tz);
                $hour = (int)$dt->format('G');
                if ($hour < 9 || $hour > 23) continue;
                $hourKey = (string)$hour;
                $countsByHourChecks[$hourKey] += 1;
                $totalChecks++;

                $dishCount = 0;
                $prods = $tx['products'] ?? null;
                if (is_array($prods)) {
                    foreach ($prods as $p) {
                        if (!is_array($p)) continue;
                        $c = $p['count'] ?? $p['quantity'] ?? 1;
                        if (is_string($c)) $c = str_replace(',', '.', trim($c));
                        $cn = is_numeric($c) ? (float)$c : 1.0;
                        if ($cn <= 0) continue;
                        $dishCount += (int)round($cn);
                    }
                }
                if ($dishCount > 0) {
                    $countsByHourDishes[$hourKey] += $dishCount;
                    $totalDishes += $dishCount;
                }
            }

            $prevNextTr = $nextTr;
            $nextTr = $nextTrCandidate;
            if ($nextTr === null || $nextTr === $prevNextTr) break;
        } while (true);

        $dow = (int)(new DateTimeImmutable($date . ' 12:00:00', $tz))->format('N');

        echo json_encode([
            'ok' => true,
            'date' => $date,
            'dow' => $dow,
            'status' => 2,
            'hours' => $hours,
            'counts_by_hour_checks' => $countsByHourChecks,
            'counts_by_hour_dishes' => $countsByHourDishes,
            'total_checks' => $totalChecks,
            'total_dishes' => $totalDishes,
        ], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
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
    <link rel="icon" type="image/svg+xml" href="/links/favicon.svg">
    <link rel="stylesheet" href="assets/app.css">
    <script src="/assets/app.js" defer></script>
    <script src="assets/user_menu.js" defer></script>
      <?php include $_SERVER['DOCUMENT_ROOT'] . '/analytics.php'; ?>
  <link rel="stylesheet" href="/assets/css/common.css">
  <link rel="stylesheet" href="/assets/css/zapara.css">
</head>
<body>
<div class="wrap">
    <div class="top">
        <div class="controls">
            <div class="card filters-card">
                <div class="filters-row">
                    <span class="muted">С</span>
                    <input type="date" id="dateFrom" value="<?= htmlspecialchars($defaultFrom) ?>">
                    <span class="muted">По</span>
                    <input type="date" id="dateTo" value="<?= htmlspecialchars($defaultTo) ?>">
                    <button class="btn" id="loadBtn">Загрузить</button>
                    <label class="chart-switch">
                        <span>Колонки</span>
                        <input type="checkbox" id="chartTypeToggle">
                        <span class="track"><span class="knob"></span></span>
                        <span>Линия</span>
                    </label>
                    <label class="chart-switch">
                        <span>Чеки</span>
                        <input type="checkbox" id="metricToggle">
                        <span class="track"><span class="knob"></span></span>
                        <span>Блюда</span>
                    </label>
                </div>
                <div class="prog" id="prog">
                    <div class="progbar"><div id="progFill"></div></div>
                    <div class="progPct" id="progPct">0%</div>
                    <div class="progText" id="progText">—</div>
                </div>
            </div>
        </div>
        <?php require __DIR__ . '/partials/user_menu.php'; ?>
    </div>
    <div style="margin-top: 12px;">
        <h1>Zapara</h1>
        <div class="muted">Источник: Poster (dash.getTransactions), группировка по дню недели и часу открытия чека</div>
    </div>

    <div class="grid" id="charts"><div class="card muted" style="display:flex; align-items:center; justify-content:center; min-height: 120px;">Выбери период и нажми «Загрузить»</div></div>
</div>

<script src="/assets/js/zapara.js"></script>
</body>
</html>
