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
        <a href="?tab=menu&export=ko_missing_csv" title="Список позиций, где не заполнено Название KO (берётся Название RU как источник для перевода).">KO пустые</a>
        <?php if (!empty($menuSyncMeta['last_sync_at'])): ?>
            <span class="muted">Последняя синхронизация: <span class="js-local-dt" data-iso="<?= htmlspecialchars($menuSyncAtIso) ?>"><?= htmlspecialchars($menuSyncMeta['last_sync_at']) ?></span></span>
        <?php endif; ?>
    </div>
</div>
<?php if (!empty($menuSyncMeta['last_sync_error'])): ?>
    <div style="margin-top:12px;" class="error"><?= htmlspecialchars($menuSyncMeta['last_sync_error']) ?></div>
<?php endif; ?>

<details style="margin-top: 12px;">
    <summary style="cursor:pointer; font-weight:800;">Импорт KO названий</summary>
    <div class="muted" style="margin-top: 8px;">Вставь CSV в формате: Item ID;Название KO</div>
    <form method="POST" style="margin-top: 10px;">
        <textarea name="ko_titles_csv" rows="6" placeholder="Item ID;Название KO"></textarea>
        <div style="margin-top: 10px;">
            <button type="submit" name="import_ko_titles_csv" value="1">Импортировать KO</button>
        </div>
    </form>
</details>

<details style="margin-top: 12px;">
    <summary style="cursor:pointer; font-weight:800;">Импорт нормализованных названий</summary>
    <div class="muted" style="margin-top: 8px;">Вставь CSV в формате: ID;Название;RU;EN;VN;KO (ID = Poster ID)</div>
    <form method="POST" style="margin-top: 10px;">
        <textarea name="normalized_titles_csv" rows="8" placeholder="ID;Название;Нормализованное название на русском;Нормализованное название на английском;Нормализованное название на вьетнамском;Нормализованное название на корейском"></textarea>
        <div style="margin-top: 10px;">
            <button type="submit" name="import_normalized_titles_csv" value="1">Импортировать названия</button>
        </div>
    </form>
</details>
