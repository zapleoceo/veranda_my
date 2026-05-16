<?php
$_umLabel  = (string)($_SESSION['user_name'] ?? $_SESSION['user_email'] ?? '');
$_umInit   = mb_strtoupper(mb_substr($_umLabel !== '' ? $_umLabel : 'U', 0, 1));
$_umAvatar = (string)($_SESSION['user_avatar'] ?? '');
?>
<div class="sb-user">
    <span class="sb-user-icon">
        <?php if ($_umAvatar !== ''): ?>
            <img src="<?= htmlspecialchars($_umAvatar) ?>" alt="">
        <?php else: ?>
            <?= htmlspecialchars($_umInit) ?>
        <?php endif; ?>
    </span>
    <div class="sb-user-info">
        <span class="sb-user-name"><?= htmlspecialchars($_umLabel) ?></span>
        <a class="sb-user-logout" href="/logout">Выход</a>
    </div>
</div>
