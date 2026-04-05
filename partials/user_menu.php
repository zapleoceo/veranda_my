<?php
$userLabel = (string)($_SESSION['user_name'] ?? $_SESSION['user_email'] ?? '');
$initial = mb_strtoupper(mb_substr($userLabel !== '' ? $userLabel : 'U', 0, 1));
$avatar = (string)($_SESSION['user_avatar'] ?? '');
$dashboardQuery = isset($dashboardQuery) ? (string)$dashboardQuery : '';
$rawDataQuery = isset($rawDataQuery) ? (string)$rawDataQuery : $dashboardQuery;
?>
<div class="user-menu">
    <div class="user-chip" data-name="<?= htmlspecialchars($userLabel, ENT_QUOTES) ?>">
        <span class="user-icon"><?php if ($avatar !== ''): ?><img src="<?= htmlspecialchars($avatar) ?>" alt=""><?php else: ?><?= htmlspecialchars($initial) ?><?php endif; ?></span>
        <span class="user-name"><?= htmlspecialchars($userLabel) ?></span>
    </div>
    <div class="user-dropdown">
        <?php
            $canDashboard = function_exists('veranda_can') && veranda_can('dashboard');
            $canRaw = function_exists('veranda_can') && veranda_can('rawdata');
            $canKitchen = function_exists('veranda_can') && veranda_can('kitchen_online');
            $canCooked = function_exists('veranda_can') && veranda_can('errors');
            $canZapara = function_exists('veranda_can') && veranda_can('zapara');
            $canBanya = function_exists('veranda_can') && veranda_can('banya');
            $canRoma = function_exists('veranda_can') && veranda_can('roma');
            $canEmployees = function_exists('veranda_can') && veranda_can('employees');
            $canPayday = function_exists('veranda_can') && veranda_can('payday');
            $canAdmin = function_exists('veranda_can') && veranda_can('admin');
            $hasReports = $canDashboard || $canRaw || $canKitchen || $canCooked || $canZapara || $canBanya || $canRoma || $canEmployees;
        ?>

        <?php if ($hasReports): ?>
            <div class="ud-title">Отчеты</div>

            <?php if ($canKitchen || $canDashboard || $canRaw || $canCooked || $canZapara): ?>
                <details class="ud-details">
                    <summary class="ud-summary">Кухня</summary>
                    <?php if ($canKitchen): ?><a class="ud-link ud-l2" href="/kitchen_online.php">Онлайн</a><?php endif; ?>
                    <?php if ($canDashboard): ?><a class="ud-link ud-l2" href="/dashboard.php<?= $dashboardQuery !== '' ? ('?' . htmlspecialchars($dashboardQuery)) : '' ?>">Дашборд</a><?php endif; ?>
                    <?php if ($canCooked): ?><a class="ud-link ud-l2" href="/errors.php">Cooked</a><?php endif; ?>
                    <?php if ($canZapara): ?><a class="ud-link ud-l2" href="/zapara.php">Zapara</a><?php endif; ?>
                    <?php if ($canRaw): ?><a class="ud-link ud-l2" href="/rawdata.php<?= $rawDataQuery !== '' ? ('?' . htmlspecialchars($rawDataQuery)) : '' ?>">Таблица</a><?php endif; ?>
                </details>
            <?php endif; ?>

            <?php if ($canBanya): ?><a class="ud-link ud-l1" href="/banya.php">Баня</a><?php endif; ?>
            <?php if ($canRoma): ?><a class="ud-link ud-l1" href="/roma.php">Кальяны</a><?php endif; ?>
            <?php if ($canEmployees): ?><a class="ud-link ud-l1" href="/employees.php">ЗП сотрудников</a><?php endif; ?>

            <div class="ud-sep"></div>
        <?php endif; ?>

        <?php if ($canPayday): ?><a class="ud-link" href="/payday">PayDay</a><?php endif; ?>
        <?php if ($canAdmin): ?><a class="ud-link" href="/admin.php">Управление</a><?php endif; ?>
        <div class="ud-sep"></div>
        <a href="/logout.php">Выход</a>
    </div>
</div>
