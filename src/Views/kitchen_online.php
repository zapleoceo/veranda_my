<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/svg+xml" href="/links/favicon.svg">
    <title>КухняOnline</title>
    <link rel="stylesheet" href="/assets/app.css">
    <link rel="stylesheet" href="/assets/css/common.css?v=20260516_tokens2">
    <link rel="stylesheet" href="/assets/css/user_menu.css">
    <link rel="stylesheet" href="/assets/css/kitchen_online.css">
</head>
<body>
<div class="container">
    <div class="top-nav">
        <div class="nav-left">
            <div class="nav-title">
                <span class="ko-titlebar">КухняОнлайн
                    <button type="button" class="ko-sound" id="soundToggle" aria-label="Звук">🔊</button>
                </span>
            </div>
        </div>
        <div class="nav-mid">
            <span>Последнее обновление из Poster: <span id="lastSync"><?= htmlspecialchars($lastSyncLabel) ?></span></span>
            <span class="ko-refresh" title="Следующее обновление">
                <span class="ko-refresh-ring" aria-label="Следующее обновление">
                    <svg viewBox="0 0 36 36" aria-hidden="true">
                        <circle class="ko-refresh-track" cx="18" cy="18" r="15"></circle>
                        <circle class="ko-refresh-progress" id="refreshProgress" cx="18" cy="18" r="15"></circle>
                    </svg>
                    <span class="ko-refresh-text" id="refreshIn">10</span>
                </span>
            </span>
            <label>
                Цех:
                <select id="station">
                    <option value="all">Все</option>
                    <option value="kitchen">Кухня</option>
                    <option value="bar">Бар</option>
                </select>
            </label>
            <label style="display:flex;align-items:center;gap:6px">
                <input type="checkbox" id="useLogicalClose"<?= $useLogicalClose ? ' checked' : '' ?>>
                <span style="font-weight:900">УчитыватьВрЛогЗакр</span>
            </label>
        </div>
        <div class="nav-right">
            <?php require __DIR__ . '/partials/user_menu.php'; ?>
        </div>
    </div>

    <div id="cards" class="cards"></div>
    <div id="empty" class="empty" style="display:none">ВСЕ ЗАКАЗЫ ВЫДАНЫ</div>
    <div class="ko-footer">
        Табло обновляется автоматически каждые 10 секунд. Крестик «✕» рядом с блюдом означает «Игнор».
    </div>
</div>

<script src="/assets/app.js" defer></script>
<script src="/assets/user_menu.js" defer></script>
<script src="/assets/js/kitchen_online.js?v=20260516"></script>
</body>
</html>
