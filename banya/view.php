<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Отчет баня</title>
    <link rel="icon" type="image/svg+xml" href="/links/favicon.svg">
    <script src="/assets/app.js" defer></script>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/analytics.php'; ?>
    <link rel="stylesheet" href="/assets/css/common.css">
    <link rel="stylesheet" href="/assets/css/banya.css?v=20260425_0001">
    <link rel="stylesheet" href="/banya/style.css">
</head>
<body>
<div class="container banya-container">
    <div class="top-nav banya-top-nav">
        <div class="nav-left banya-nav-left">
            <div class="nav-title banya-nav-title">Отчет баня</div>
        </div>
        <div class="nav-mid"></div>
        <?php require __DIR__ . '/../partials/user_menu.php'; ?>
    </div>
</div>
<div class="wrap">
    <div class="card">
        <div class="row">
            <div>
                <h1 class="banya-h1-hidden">Отчет баня</h1>
            </div>
            <div class="date-inputs banya-date-inputs">
                <label>
                    Начало
                    <input type="date" id="dateFrom" value="<?= htmlspecialchars($firstOfMonth) ?>">
                </label>
                <label>
                    Конец
                    <input type="date" id="dateTo" value="<?= htmlspecialchars($today) ?>">
                </label>
            </div>
            <div class="controls">
                <button id="loadBtn" type="button">ЗАГРУЗИТЬ</button>
                <div class="progress banya-progress" id="prog" style="display:none;">
                    <div class="bar banya-bar">
                        <span id="progBar" class="banya-prog-bar"></span>
                    </div>
                    <div class="label banya-label" id="progLabel">0%</div>
                    <div class="desc banya-desc" id="progDesc"></div>
                </div>
                <div class="loader banya-loader" id="loader" style="display:none;"><span class="spinner"></span><span class="muted">Загрузка…</span></div>
                <div class="toggles-mobile">
                    <div class="toggle-wrap" title="Страницы">
                        <span class="toggle-text">страницы</span>
                        <label class="switch">
                            <input type="checkbox" id="noPages">
                            <span class="slider"></span>
                        </label>
                    </div>
                    <div class="toggle-wrap" title="Группировать по дням">
                        <span class="toggle-text">по дням</span>
                        <label class="switch">
                            <input type="checkbox" id="groupByDay">
                            <span class="slider"></span>
                        </label>
                    </div>
                </div>
                <div class="pager" id="pagerTop"></div>
            </div>
        </div>
        <div class="error banya-error" id="err" style="display:none;"></div>

        <div class="table-wrap banya-table-wrap">
            <table>
                <thead>
                    <tr>
                        <th id="thDate" data-sort="date" class="banya-th-date">Дата<span class="sort-arrow"></span></th>
                        <th id="thHall" data-sort="hall" class="banya-th-hall">Hall<span class="sort-arrow"></span></th>
                        <th id="thTable" data-sort="table" class="banya-th-table">
                            <div class="table-filter">
                                <span>Стол</span><span class="sort-arrow"></span>
                                <button type="button" id="tableFilterBtn" class="table-filter-btn" title="Фильтр столов" aria-label="Фильтр столов">▾</button>
                                <div id="tableFilterPop" class="table-filter-pop" style="display:none;"></div>
                            </div>
                        </th>
                        <th id="thReceipt" data-sort="receipt" class="banya-th-receipt">Чек<span class="sort-arrow"></span></th>
                        <th id="thWaiter" data-sort="waiter" class="banya-th-waiter">Официант<span class="sort-arrow"></span></th>
                        <th id="thSum" data-sort="sum_minor" class="banya-th-sum">Сумма<span class="sort-arrow"></span></th>
                        <th class="banya-th-empty"></th>
                    </tr>
                </thead>
                <tbody id="tbody"></tbody>
            </table>
        </div>
        <div class="banya-pager-bottom">
            <div class="pager" id="pagerBottom"></div>
        </div>

        <div class="totals">
            <div class="pill" id="totChecks">Итого чеков: 0</div>
            <div class="pill ok" id="totSum">Итого сумма: 0</div>
            <div class="pill bad" id="totHookah">Сумма кальянов: 0</div>
            <div class="pill ok" id="totWithout">Сумма без кальянов: 0</div>
        </div>
        <div class="muted banya-footer-note">Включены только столы Бани · кальяны: категория <?= (int)\Banya\Model::HOOKAH_CATEGORY_ID ?></div>
    </div>
</div>

<script src="/assets/js/banya.js"></script>
<script src="/assets/user_menu.js" defer></script>
<script src="/banya/script.js"></script>
</body>
</html>
