<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Zapara</title>
    <link rel="icon" type="image/svg+xml" href="/links/favicon.svg">
    <link rel="stylesheet" href="/assets/app.css">
    <script src="/assets/app.js" defer></script>
    <script src="/assets/user_menu.js" defer></script>
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
        <?php require $_SERVER['DOCUMENT_ROOT'] . '/partials/user_menu.php'; ?>
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

