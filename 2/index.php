<?php
require_once __DIR__ . '/../auth_check.php';

$r = trim((string)($_GET['r'] ?? 'dashboard'));
$r = ltrim($r, '/');
$r = preg_replace('/\s+/', '', $r) ?? $r;

$routes = [
    'dashboard' => ['label' => 'Дашборд', 'perm' => 'dashboard', 'url' => '../dashboard.php'],
    'rawdata' => ['label' => 'Сырые данные', 'perm' => 'rawdata', 'url' => '../rawdata.php'],
    'kitchen_online' => ['label' => 'КухняOnline', 'perm' => 'kitchen_online', 'url' => '../kitchen_online.php'],
    'logs' => ['label' => 'Логи', 'perm' => 'logs', 'url' => '../logs.php'],
    'admin/sync' => ['label' => 'Синки', 'perm' => 'admin_sync', 'url' => '../admin.php?tab=sync'],
    'admin/access' => ['label' => 'Доступы', 'perm' => 'admin_access', 'url' => '../admin.php?tab=access'],
    'admin/telegram' => ['label' => 'Telegram', 'perm' => 'admin_telegram', 'url' => '../admin.php?tab=telegram'],
    'admin/menu' => ['label' => 'Меню', 'perm' => 'admin_menu', 'url' => '../admin.php?tab=menu'],
    'admin/categories' => ['label' => 'Категории', 'perm' => 'admin_categories', 'url' => '../admin.php?tab=categories'],
];

if (!array_key_exists($r, $routes)) {
    $r = 'dashboard';
}
$route = $routes[$r];

$canAdmin = veranda_can('admin');
$perm = (string)($route['perm'] ?? '');
if ($perm !== '') {
    $ok = false;
    if ($canAdmin && (str_starts_with($r, 'admin/') || $r === 'logs')) {
        $ok = true;
    } elseif (veranda_can($perm)) {
        $ok = true;
    }
    if (!$ok) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
}

$title = 'Veranda-admin';
$pageTitle = (string)($route['label'] ?? '');
$iframeUrl = (string)($route['url'] ?? '../dashboard.php');

$link = function (string $key, string $label) use ($r): string {
    $href = 'index.php?' . http_build_query(['r' => $key]);
    $active = $r === $key ? 'active' : '';
    return '<a href="' . htmlspecialchars($href) . '" class="nav-link ' . $active . '"><p>' . htmlspecialchars($label) . '</p></a>';
};

$adminActive = str_starts_with($r, 'admin/') ? 'menu-open' : '';
$adminLinkActive = str_starts_with($r, 'admin/') ? 'active' : '';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($title . ($pageTitle !== '' ? (' — ' . $pageTitle) : '')) ?></title>
    <link rel="icon" type="image/svg+xml" href="/links/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@4.0.0-rc7/dist/css/adminlte.min.css" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.7.2/css/all.min.css" crossorigin="anonymous">
    <style>
        html, body { height: 100%; }
        .content-wrapper { background: var(--lte-content-bg, #111827); }
        .iframe-wrap { border-radius: 14px; overflow: hidden; border: 1px solid rgba(255,255,255,0.08); background: rgba(0,0,0,0.18); }
        .page-iframe { width: 100%; border: 0; display: block; min-height: calc(100vh - 230px); background: transparent; }
        .nav-sidebar .nav-link p { font-weight: 700; }
        .brand-link .brand-text { font-weight: 900; letter-spacing: 0.2px; }
    </style>
</head>
<body class="dark-mode layout-fixed sidebar-mini text-sm">
<div class="app-wrapper">
    <nav class="app-header navbar navbar-expand navbar-dark">
        <div class="container-fluid">
            <ul class="navbar-nav">
                <li class="nav-item">
                    <a class="nav-link" data-lte-toggle="sidebar" href="#" role="button"><i class="fas fa-bars"></i></a>
                </li>
            </ul>
            <ul class="navbar-nav ms-auto">
                <li class="nav-item dropdown user-menu">
                    <a href="#" class="nav-link dropdown-toggle" data-bs-toggle="dropdown">
                        <span class="d-none d-md-inline"><?= htmlspecialchars((string)($_SESSION['user_name'] ?? $_SESSION['user_email'] ?? '')) ?></span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="../logout.php">Выход</a></li>
                    </ul>
                </li>
            </ul>
        </div>
    </nav>

    <aside class="app-sidebar sidebar-dark-primary elevation-4">
        <a href="index.php?r=dashboard" class="brand-link">
            <span class="brand-text">Veranda-admin</span>
        </a>
        <div class="sidebar">
            <nav class="mt-2">
                <ul class="nav nav-pills nav-sidebar flex-column" data-lte-toggle="treeview" role="menu" data-accordion="false">
                    <?php if (veranda_can('dashboard')): ?>
                        <li class="nav-item"><?= $link('dashboard', 'Дашборд') ?></li>
                    <?php endif; ?>
                    <?php if (veranda_can('rawdata')): ?>
                        <li class="nav-item"><?= $link('rawdata', 'Сырые данные') ?></li>
                    <?php endif; ?>
                    <?php if (veranda_can('kitchen_online')): ?>
                        <li class="nav-item"><?= $link('kitchen_online', 'КухняOnline') ?></li>
                    <?php endif; ?>
                    <?php if ($canAdmin || veranda_can('logs')): ?>
                        <li class="nav-item"><?= $link('logs', 'Логи') ?></li>
                    <?php endif; ?>

                    <?php if ($canAdmin || veranda_can('admin_sync') || veranda_can('admin_access') || veranda_can('admin_telegram') || veranda_can('admin_menu') || veranda_can('admin_categories')): ?>
                    <li class="nav-item <?= $adminActive ?>">
                        <a href="#" class="nav-link <?= $adminLinkActive ?>">
                            <i class="nav-icon fas fa-toolbox"></i>
                            <p>Управление<i class="right fas fa-angle-left"></i></p>
                        </a>
                        <ul class="nav nav-treeview">
                            <?php if ($canAdmin || veranda_can('admin_sync')): ?>
                                <li class="nav-item"><?= $link('admin/sync', 'Синки') ?></li>
                            <?php endif; ?>
                            <?php if ($canAdmin || veranda_can('admin_access')): ?>
                                <li class="nav-item"><?= $link('admin/access', 'Доступы') ?></li>
                            <?php endif; ?>
                            <?php if ($canAdmin || veranda_can('admin_telegram')): ?>
                                <li class="nav-item"><?= $link('admin/telegram', 'Telegram') ?></li>
                            <?php endif; ?>
                            <?php if ($canAdmin || veranda_can('admin_menu')): ?>
                                <li class="nav-item"><?= $link('admin/menu', 'Меню') ?></li>
                            <?php endif; ?>
                            <?php if ($canAdmin || veranda_can('admin_categories')): ?>
                                <li class="nav-item"><?= $link('admin/categories', 'Категории') ?></li>
                            <?php endif; ?>
                        </ul>
                    </li>
                    <?php endif; ?>
                </ul>
            </nav>
        </div>
    </aside>

    <main class="app-main">
        <div class="content-wrapper">
            <div class="content-header">
                <div class="container-fluid">
                    <div class="row mb-2 align-items-center">
                        <div class="col-sm-6">
                            <h1 class="m-0"><?= htmlspecialchars($pageTitle) ?></h1>
                        </div>
                        <div class="col-sm-6">
                            <ol class="breadcrumb float-sm-end mb-0">
                                <li class="breadcrumb-item"><a href="index.php?r=dashboard">Главная</a></li>
                                <li class="breadcrumb-item active"><?= htmlspecialchars($pageTitle) ?></li>
                            </ol>
                        </div>
                    </div>
                </div>
            </div>
            <section class="content">
                <div class="container-fluid">
                    <div class="iframe-wrap">
                        <iframe class="page-iframe" id="pageFrame" src="<?= htmlspecialchars($iframeUrl) ?>" loading="lazy" referrerpolicy="no-referrer"></iframe>
                    </div>
                </div>
            </section>
        </div>
    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/admin-lte@4.0.0-rc7/dist/js/adminlte.min.js" crossorigin="anonymous"></script>
<script>
(() => {
    const frame = document.getElementById('pageFrame');
    if (!frame) return;
    const fit = () => {
        try {
            const d = frame.contentWindow && frame.contentWindow.document ? frame.contentWindow.document : null;
            if (!d) return;
            const b = d.body;
            const h = Math.max(b ? b.scrollHeight : 0, d.documentElement ? d.documentElement.scrollHeight : 0);
            if (h > 200) frame.style.height = String(h) + 'px';
        } catch (_) {
        }
    };
    frame.addEventListener('load', () => {
        fit();
        setTimeout(fit, 250);
        setTimeout(fit, 1200);
    });
    window.addEventListener('resize', () => setTimeout(fit, 80));
})();
</script>
</body>
</html>
