<?php
$userLabel = (string)($_SESSION['user_name'] ?? $_SESSION['user_email'] ?? '');
$initial = mb_strtoupper(mb_substr($userLabel !== '' ? $userLabel : 'U', 0, 1));
$avatar = (string)($_SESSION['user_avatar'] ?? '');
$dashboardQuery = isset($dashboardQuery) ? (string)$dashboardQuery : '';
$rawDataQuery = isset($rawDataQuery) ? (string)$rawDataQuery : $dashboardQuery;
?>
<style>
    .user-menu { position: relative; }
    .user-chip { display: inline-flex; align-items: center; gap: 8px; padding: 6px 10px; border: 1px solid #e5e7eb; border-radius: 999px; background: #fff; color: #37474f; font-weight: 600; cursor: pointer; position: relative; }
    .user-icon { width: 22px; height: 22px; border-radius: 50%; background: #e3f2fd; display: inline-flex; align-items: center; justify-content: center; color: #1a73e8; font-weight: 800; font-size: 12px; overflow: hidden; }
    .user-icon img { width: 100%; height: 100%; object-fit: cover; display: block; }
    .user-chip::after { content: attr(data-name); position: absolute; right: calc(100% + 8px); top: 50%; transform: translateY(-50%); background: rgba(17,24,39,0.92); color: rgba(255,255,255,0.95); padding: 6px 10px; border-radius: 999px; font-size: 12px; font-weight: 800; white-space: nowrap; opacity: 0; pointer-events: none; transition: opacity 0.15s ease; }
    .user-menu.open .user-chip::after,
    .user-menu:hover .user-chip::after { opacity: 1; }
    .user-dropdown { position: absolute; right: 0; top: calc(100% + 8px); background: #fff; border: 1px solid #e0e0e0; border-radius: 10px; box-shadow: 0 8px 18px rgba(0,0,0,0.12); padding: 8px; min-width: 160px; z-index: 1000; display: block; opacity: 0; visibility: hidden; transform: translateY(-6px); pointer-events: none; transition: opacity 0.15s ease, transform 0.15s ease, visibility 0.15s ease; }
    .user-menu.open .user-dropdown { opacity: 1; visibility: visible; transform: translateY(0); pointer-events: auto; }
    .user-dropdown a { display: block; padding: 8px 10px; border-radius: 8px; color: #37474f; text-decoration: none; font-weight: 600; }
    .user-dropdown a:hover { background: #f4f7f6; }
    .user-dropdown .ud-title { padding: 6px 10px 4px; color: #9aa0a6; font-size: 11px; font-weight: 900; text-transform: uppercase; letter-spacing: 0.06em; }
    .user-dropdown .ud-link.ud-l1 { padding-left: 18px; }
    .user-dropdown .ud-link.ud-l2 { padding-left: 32px; }
    .user-dropdown .ud-sep { height: 1px; background: #eee; margin: 6px 8px; border-radius: 999px; }
    .user-dropdown .ud-details { margin: 2px 0; }
    .user-dropdown .ud-summary { list-style: none; padding: 8px 10px; border-radius: 8px; color: #37474f; font-weight: 800; cursor: pointer; user-select: none; }
    .user-dropdown .ud-summary::-webkit-details-marker { display: none; }
    .user-dropdown details[open] .ud-summary { background: #f4f7f6; }
    .user-dropdown .ud-summary::after { content: "›"; float: right; color: #9aa0a6; font-weight: 900; }
    .user-dropdown details[open] .ud-summary::after { content: "⌄"; }
    @media (max-width: 768px) {
        .top-nav { flex-wrap: wrap !important; }
        .top-nav .nav-left { order: 1; flex: 0 0 auto; }
        .top-nav .user-menu { order: 2; margin-left: auto; }
        .top-nav .nav-mid { order: 3; flex: 1 1 100%; margin-top: 10px; }
    }
</style>
<div class="user-menu">
    <div class="user-chip" data-name="<?= htmlspecialchars($userLabel, ENT_QUOTES) ?>">
        <span class="user-icon"><?php if ($avatar !== ''): ?><img src="<?= htmlspecialchars($avatar) ?>" alt=""><?php else: ?><?= htmlspecialchars($initial) ?><?php endif; ?></span>
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
