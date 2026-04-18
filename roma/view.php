<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Roma</title>
    <link rel="icon" type="image/svg+xml" href="/links/favicon.svg">
    <script src="/assets/app.js" defer></script>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/analytics.php'; ?>
    <link rel="stylesheet" href="/assets/css/common.css">
    <link rel="stylesheet" href="/roma/style.css">
</head>
<body>
<div class="container" style="max-width: 1200px; margin: 0 auto; padding: 0 16px;">
    <div class="top-nav" style="display:flex; justify-content: space-between; align-items:center; gap: 16px; flex-wrap: wrap; padding: 12px 0;">
        <div class="nav-left" style="display:flex; gap: 14px; align-items:center; flex-wrap: wrap;">
            <div class="nav-title" style="font-weight: 800; color: var(--brand-text);">Roma</div>
        </div>
        <div class="nav-mid"></div>
        <?php require __DIR__ . '/../partials/user_menu.php'; ?>
    </div>
</div>
<div class="wrap">
    <div class="card">
        <div class="row">
            <div class="roma-header-info">
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
            <div class="roma-actions">
                <button id="loadBtn" type="button">ЗАГРУЗИТЬ</button>
                <div class="loader" id="loader"><span class="spinner"></span><span class="muted">Загрузка…</span></div>
            </div>
        </div>

        <div class="error roma-error" id="err"></div>

        <table>
            <thead>
                <tr>
                    <th>Название кальяна</th>
                    <th class="roma-col-count">Кол‑во</th>
                    <th class="roma-col-saldo">Сальдо</th>
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

<script src="/assets/user_menu.js" defer></script>
<script src="/roma/script.js"></script>
</body>
</html>
