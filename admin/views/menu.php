<div class="card">
    <?php require __DIR__ . '/menu/_actions.php'; ?>

    <?php if ($menuView === 'edit'): ?>
        <?php require __DIR__ . '/menu/edit.php'; ?>
    <?php else: ?>
        <?php require __DIR__ . '/menu/list.php'; ?>
    <?php endif; ?>
</div>

