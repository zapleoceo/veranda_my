<?php
/**
 * @var int    $appId
 * @var string $cssVersion
 * @var string $jsVersion
 */
declare(strict_types=1);

$h = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0f1117">
    <meta name="robots" content="noindex, nofollow">
    <link rel="icon" type="image/svg+xml" href="/links/favicon.svg">
    <title>Veranda Табель</title>
    <link rel="stylesheet" href="/assets/css/common.css?v=<?= $h($cssVersion) ?>">
    <link rel="stylesheet" href="/poster-app/assets/css/widget.css?v=<?= $h($cssVersion) ?>">
</head>
<body>

<main class="pa-shell">
    <header class="pa-head">
        <div class="pa-brand">Veranda</div>
        <div class="pa-title">Табель сотрудников</div>
    </header>

    <!-- Status card — populated by widget.js once the Poster SDK lands. -->
    <section class="pa-card" id="paStatusCard">
        <div class="pa-row">
            <div class="pa-label">Сотрудник</div>
            <div class="pa-value" id="paUserName">—</div>
        </div>
        <div class="pa-row">
            <div class="pa-label">Смена</div>
            <div class="pa-value" id="paShiftState">—</div>
        </div>
        <div class="pa-row" id="paShiftStartedRow" hidden>
            <div class="pa-label">Открыта</div>
            <div class="pa-value" id="paShiftStarted">—</div>
        </div>
    </section>

    <section class="pa-actions">
        <button type="button" class="pa-btn pa-btn--primary" id="paOpenBtn" disabled>
            <span class="pa-btn__dot pa-btn__dot--green"></span>
            Начать смену
        </button>
        <button type="button" class="pa-btn pa-btn--danger"  id="paCloseBtn" disabled>
            <span class="pa-btn__dot pa-btn__dot--red"></span>
            Закончить смену
        </button>
    </section>

    <section class="pa-log" id="paLog" aria-live="polite"></section>

    <footer class="pa-foot">
        App ID: <?= $h((string)$appId) ?> · build <?= $h($jsVersion) ?>
    </footer>
</main>

<script>
window.__pa = {
    appId: <?= json_encode($appId) ?>,
    apiBase: '/poster-app/api',
};
</script>
<script type="module" src="/poster-app/assets/js/widget.js?v=<?= $h($jsVersion) ?>"></script>
</body>
</html>
