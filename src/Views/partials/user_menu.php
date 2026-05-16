<?php
// Permission helper — checks $_SESSION['user_permissions']; returns true if no perms array (all-allow)
$_umCan = function(string $p): bool {
    $perms = $_SESSION['user_permissions'] ?? null;
    return !is_array($perms) || !empty($perms[$p]);
};

$_umLabel  = (string)($_SESSION['user_name'] ?? $_SESSION['user_email'] ?? '');
$_umInit   = mb_strtoupper(mb_substr($_umLabel !== '' ? $_umLabel : 'U', 0, 1));
$_umAvatar = (string)($_SESSION['user_avatar'] ?? '');

$_umKitchen      = $_umCan('kitchen_online');
$_umRaw          = $_umCan('rawdata');
$_umReservations = $_umCan('reservations');
$_umPayday       = $_umCan('payday');
$_umAdmin        = $_umCan('admin');
$_umHasReports   = $_umKitchen || $_umRaw || $_umReservations;
?>
<div class="user-menu">
    <div class="user-chip" data-name="<?= htmlspecialchars($_umLabel, ENT_QUOTES) ?>">
        <span class="user-icon">
            <?php if ($_umAvatar !== ''): ?>
                <img src="<?= htmlspecialchars($_umAvatar) ?>" alt="">
            <?php else: ?>
                <?= htmlspecialchars($_umInit) ?>
            <?php endif; ?>
        </span>
    </div>
    <div class="user-dropdown">
        <?php if ($_umHasReports): ?>
            <div class="ud-title">Отчёты</div>
            <?php if ($_umKitchen || $_umRaw): ?>
                <details class="ud-details">
                    <summary class="ud-summary">Кухня</summary>
                    <?php if ($_umKitchen): ?>
                        <a class="ud-link ud-l2" href="/kitchen_online">Онлайн</a>
                    <?php endif; ?>
                    <?php if ($_umRaw): ?>
                        <a class="ud-link ud-l2" href="/rawdata">Таблица</a>
                    <?php endif; ?>
                </details>
            <?php endif; ?>
            <?php if ($_umReservations): ?>
                <a class="ud-link ud-l1" href="/reservations">Брони</a>
            <?php endif; ?>
            <div class="ud-sep"></div>
        <?php endif; ?>
        <?php if ($_umPayday): ?>
            <a class="ud-link" href="/payday2">PayDay2</a>
        <?php endif; ?>
        <?php if ($_umAdmin): ?>
            <a class="ud-link" href="/admin">Управление</a>
        <?php endif; ?>
        <div class="ud-sep"></div>
        <a class="ud-link" href="/logout">Выход</a>
    </div>
</div>
