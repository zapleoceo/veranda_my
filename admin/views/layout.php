<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/svg+xml" href="/links/favicon.svg">
    <title>Админ-панель</title>
    <link rel="stylesheet" href="/assets/app.css?v=11">
    <link rel="stylesheet" href="/assets/css/common.css?v=1">
    <link rel="stylesheet" href="/admin/assets/css/admin.css?v=20260504_0004">
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/analytics.php'; ?>
</head>
<body>

<div class="container">
    <div class="top-nav" style="justify-content: space-between; flex-wrap: nowrap; align-items: center; gap: 10px;">
        <div class="nav-left" style="flex: 1; min-width: 0;">
            <h1 style="margin: 0; font-size: 24px; font-weight: 800; color: var(--text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">Админ-панель</h1>
        </div>
        <div class="nav-right" style="flex-shrink: 0;">
            <?php require_once __DIR__ . '/../../partials/user_menu.php'; ?>
        </div>
    </div>

    <!-- Stylish Navigation Menu -->
    <nav class="admin-nav">
        <a href="?tab=sync" class="admin-nav-item <?= $tab === 'sync' ? 'active' : '' ?>">Синки</a>
        <a href="?tab=access" class="admin-nav-item <?= $tab === 'access' ? 'active' : '' ?>">Доступы</a>
        <a href="?tab=telegram" class="admin-nav-item <?= $tab === 'telegram' ? 'active' : '' ?>">Telegram</a>
        <a href="?tab=reservations" class="admin-nav-item <?= $tab === 'reservations' ? 'active' : '' ?>">Брони</a>
        <a href="?tab=categories" class="admin-nav-item <?= $tab === 'categories' ? 'active' : '' ?>">Категории</a>
        <a href="?tab=menu" class="admin-nav-item <?= $tab === 'menu' ? 'active' : '' ?>">Меню</a>
        <a href="?tab=logs" class="admin-nav-item <?= $tab === 'logs' ? 'active' : '' ?>">Логи</a>
    </nav>

    <?php if ($message): ?>
        <div class="alert ok" style="margin-bottom: 20px;"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert err" style="margin-bottom: 20px;"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php
    $view_file = __DIR__ . '/' . $tab . '.php';
    if (file_exists($view_file)) {
        require_once $view_file;
    } else {
        echo "<div class='alert err'>Вид не найден.</div>";
    }
    ?>
</div>

<script src="/assets/app.js?v=2" defer></script>
<script src="/admin/assets/js/common.js?v=1" defer></script>
<script>window.ADMIN = window.ADMIN || {}; window.ADMIN.userEmail = <?= json_encode((string)($_SESSION['user_email'] ?? ''), JSON_UNESCAPED_UNICODE) ?>;</script>
<?php if (file_exists(__DIR__ . '/../assets/js/' . $tab . '.js')): ?>
    <script src="/admin/assets/js/<?= $tab ?>.js?v=20260504_0004" defer></script>
<?php endif; ?>
<script src="/assets/user_menu.js" defer></script>
</body>
</html>
