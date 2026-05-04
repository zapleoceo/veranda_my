<div class="menu-actions"><div class="left">
        <h3>Меню</h3>
        <form method="POST">
            <button type="submit" name="sync_menu" title="Синк из Poster: только обновляет слепок poster_menu_items и справочники по poster_id. Не трогает переводы и ручные привязки/публикацию.">Обновить меню из Poster</button>
        </form>
        <form method="POST">
            <button type="submit" name="autofill_menu" title="Разовая привязка по ID: связывает menu_items.category_id и menu_categories.workshop_id из данных Poster там, где сейчас пусто. Не трогает переводы и ручные значения.">Привязать ID (разово)</button>
        </form>
        <a href="?tab=menu&export=csv" title="Выгрузка CSV со всеми активными позициями и текущими переводами/категориями.">CSV меню</a>
        <a href="?tab=menu&export=categories_csv" title="Выгрузка CSV справочников цехов и категорий с переводами.">CSV категорий</a>
        <?php if (!empty($menuSyncMeta['last_sync_at'])): ?>
            <span class="muted">Последняя синхронизация: <span class="js-local-dt" data-iso="<?= htmlspecialchars($menuSyncAtIso) ?>"><?= htmlspecialchars($menuSyncMeta['last_sync_at']) ?></span></span>
        <?php endif; ?>
    </div>
</div>
<?php if (!empty($menuSyncMeta['last_sync_error'])): ?>
    <div style="margin-top:12px;" class="error"><?= htmlspecialchars($menuSyncMeta['last_sync_error']) ?></div>
<?php endif; ?>
