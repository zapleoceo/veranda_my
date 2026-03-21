<?php
$userLabel = (string)($_SESSION['user_name'] ?? $_SESSION['user_email'] ?? '');
$initial = mb_strtoupper(mb_substr($userLabel !== '' ? $userLabel : 'U', 0, 1));
$avatar = (string)($_SESSION['user_avatar'] ?? '');
$dashboardQuery = isset($dashboardQuery) ? (string)$dashboardQuery : '';
$rawDataQuery = isset($rawDataQuery) ? (string)$rawDataQuery : $dashboardQuery;
?>
<div class="user-menu">
    <div class="user-chip">
        <span class="user-icon"><?php if ($avatar !== ''): ?><img src="<?= htmlspecialchars($avatar) ?>" alt=""><?php else: ?><?= htmlspecialchars($initial) ?><?php endif; ?></span>
        <span><?= htmlspecialchars($userLabel) ?></span>
    </div>
    <div class="user-dropdown">
        <?php if (function_exists('veranda_can') && veranda_can('dashboard')): ?><a href="dashboard.php<?= $dashboardQuery !== '' ? ('?' . htmlspecialchars($dashboardQuery)) : '' ?>">Дашборд</a><?php endif; ?>
        <?php if (function_exists('veranda_can') && veranda_can('rawdata')): ?><a href="rawdata.php<?= $rawDataQuery !== '' ? ('?' . htmlspecialchars($rawDataQuery)) : '' ?>">Таблица</a><?php endif; ?>
        <?php if (function_exists('veranda_can') && veranda_can('kitchen_online')): ?><a href="kitchen_online.php">КухняОнлайн</a><?php endif; ?>
        <?php if (function_exists('veranda_can') && veranda_can('payday')): ?><a href="/payday">Payday</a><?php endif; ?>
        <?php if (function_exists('veranda_can') && veranda_can('admin')): ?><a href="admin.php">Управление</a><?php endif; ?>
        <a href="logout.php">Выход</a>
    </div>
</div>
