<?php

if (file_exists(__DIR__ . '/.env')) {
    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $t = trim($line);
        if ($t === '' || strpos($t, '#') === 0) continue;
        if (strpos($t, '=') === false) continue;
        [$name, $value] = explode('=', $line, 2);
        $_ENV[$name] = trim($value);
    }
}

require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/src/classes/PosterAPI.php';

veranda_require('reservations');

$spotId = (int)($_GET['spot_id'] ?? ($_ENV['POSTER_SPOT_ID'] ?? 1));
if ($spotId <= 0) $spotId = 1;
$hallId = (int)($_GET['hall_id'] ?? 2);
if ($hallId <= 0) $hallId = 2;

$tables = [];
$error = '';
if (empty($_ENV['POSTER_API_TOKEN'])) {
    $error = 'POSTER_API_TOKEN не настроен';
} else {
    try {
        $api = new \App\Classes\PosterAPI($_ENV['POSTER_API_TOKEN']);
        $tables = $api->request('spots.getTableHallTables', [
            'spot_id' => $spotId,
            'hall_id' => $hallId,
            'without_deleted' => 1,
        ], 'GET');
        if (!is_array($tables)) $tables = [];
    } catch (\Throwable $e) {
        $error = $e->getMessage();
        $tables = [];
    }
}

$norm = [];
$minX = null; $minY = null; $maxX = null; $maxY = null;
foreach ($tables as $t) {
    $x = (float)($t['table_x'] ?? 0);
    $y = (float)($t['table_y'] ?? 0);
    $w = (float)($t['table_width'] ?? 0);
    $h = (float)($t['table_height'] ?? 0);
    $id = (int)($t['table_id'] ?? 0);
    $num = trim((string)($t['table_num'] ?? ''));
    $title = trim((string)($t['table_title'] ?? ''));
    if ($w <= 0) $w = 6;
    if ($h <= 0) $h = 6;
    $minX = $minX === null ? $x : min($minX, $x);
    $minY = $minY === null ? $y : min($minY, $y);
    $maxX = $maxX === null ? ($x + $w) : max($maxX, $x + $w);
    $maxY = $maxY === null ? ($y + $h) : max($maxY, $y + $h);
    $norm[] = [
        'id' => $id,
        'num' => $num,
        'title' => $title,
        'x' => $x,
        'y' => $y,
        'w' => $w,
        'h' => $h,
        'shape' => (string)($t['table_shape'] ?? ''),
    ];
}

$minX = $minX ?? 0;
$minY = $minY ?? 0;
$maxX = $maxX ?? 1;
$maxY = $maxY ?? 1;

$worldW = max(1.0, $maxX - $minX);
$worldH = max(1.0, $maxY - $minY);

$canvasW = 980;
$canvasH = 620;
$pad = 20;
$scale = min(($canvasW - $pad * 2) / $worldW, ($canvasH - $pad * 2) / $worldH);

$px = function (float $v) {
    return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
};

?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Poster Hall</title>
    <link rel="stylesheet" href="/assets/app.css?v=20260415_1200">
    <style>
        :root { color-scheme: dark; }
        .wrap { max-width: 1200px; margin: 0 auto; padding: 16px; }
        .top { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
        .title { font-weight: 900; font-size: 18px; }
        .sub { color: rgba(255,255,255,0.65); font-size: 12px; margin-top: 4px; }
        .card { border: 1px solid rgba(255,255,255,0.10); border-radius: 16px; background: rgba(0,0,0,0.18); padding: 14px; margin-top: 12px; }
        .controls { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
        .controls label { display: inline-flex; gap: 8px; align-items: center; font-size: 12px; color: rgba(255,255,255,0.70); font-weight: 900; }
        .controls input { width: 86px; padding: 8px 10px; border-radius: 12px; border: 1px solid rgba(255,255,255,0.14); background: rgba(0,0,0,0.22); color: rgba(255,255,255,0.92); font-size: 13px; }
        .controls button { padding: 8px 12px; border-radius: 12px; border: 1px solid rgba(184,135,70,0.65); background: linear-gradient(180deg, rgba(184,135,70,0.95), rgba(154,108,52,0.95)); color: #0b0705; font-weight: 900; cursor: pointer; font-size: 13px; }
        .board-wrap { overflow: auto; }
        .board { position: relative; width: <?= (int)$canvasW ?>px; height: <?= (int)$canvasH ?>px; background: radial-gradient(circle at 25% 15%, rgba(255,255,255,0.06), rgba(0,0,0,0.15)); border: 1px solid rgba(255,255,255,0.10); border-radius: 16px; }
        .tbl { position: absolute; border: 1px solid rgba(184,135,70,0.55); background: rgba(184,135,70,0.10); border-radius: 12px; display: grid; place-items: center; color: rgba(255,255,255,0.95); font-weight: 900; text-align: center; padding: 2px; box-sizing: border-box; }
        .tbl.circle { border-radius: 999px; }
        .tbl .n { font-size: 12px; line-height: 1.1; }
        .tbl .t { font-size: 10px; line-height: 1.1; color: rgba(255,255,255,0.75); font-weight: 700; max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .err { color: #ffcdd2; font-weight: 900; }
        .meta { color: rgba(255,255,255,0.70); font-size: 12px; }
        @media (max-width: 720px) {
            .board { width: 860px; height: 540px; }
        }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="top">
            <div>
                <div class="title">Poster: схема зала</div>
                <div class="sub">spot_id=<?= (int)$spotId ?> · hall_id=<?= (int)$hallId ?> · tables=<?= (int)count($norm) ?></div>
            </div>
            <form class="controls" method="get">
                <label>spot_id <input name="spot_id" value="<?= (int)$spotId ?>" inputmode="numeric"></label>
                <label>hall_id <input name="hall_id" value="<?= (int)$hallId ?>" inputmode="numeric"></label>
                <button type="submit">Показать</button>
            </form>
        </div>

        <div class="card">
            <?php if ($error !== ''): ?>
                <div class="err"><?= htmlspecialchars($error) ?></div>
            <?php else: ?>
                <div class="meta">bounds: x=<?= htmlspecialchars($px($minX)) ?>..<?= htmlspecialchars($px($maxX)) ?>, y=<?= htmlspecialchars($px($minY)) ?>..<?= htmlspecialchars($px($maxY)) ?>, scale=<?= htmlspecialchars($px($scale)) ?> px/unit</div>
                <div class="board-wrap" style="margin-top:10px;">
                    <div class="board">
                        <?php foreach ($norm as $t): ?>
                            <?php
                                $left = $pad + ($t['x'] - $minX) * $scale;
                                $top = $pad + ($t['y'] - $minY) * $scale;
                                $w = $t['w'] * $scale;
                                $h = $t['h'] * $scale;
                                $cls = 'tbl' . ((string)$t['shape'] === 'circle' ? ' circle' : '');
                                $label = $t['num'] !== '' ? $t['num'] : ('#' . (int)$t['id']);
                            ?>
                            <div class="<?= htmlspecialchars($cls) ?>" style="left:<?= htmlspecialchars($px($left)) ?>px; top:<?= htmlspecialchars($px($top)) ?>px; width:<?= htmlspecialchars($px($w)) ?>px; height:<?= htmlspecialchars($px($h)) ?>px;" title="<?= htmlspecialchars('table_id=' . (int)$t['id'] . ' · ' . $t['title']) ?>">
                                <div class="n"><?= htmlspecialchars($label) ?></div>
                                <?php if ($t['title'] !== ''): ?><div class="t"><?= htmlspecialchars($t['title']) ?></div><?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>

