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
    <link rel="icon" type="image/svg+xml" href="/links/favicon.svg">
    <script src="/assets/app.js" defer></script>
      <?php include $_SERVER['DOCUMENT_ROOT'] . '/analytics.php'; ?>
  <link rel="stylesheet" href="/assets/css/common.css">
  <link rel="stylesheet" href="/assets/css/roma.css">
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

<script src="/assets/js/roma.js"></script>
</body>
</html>
