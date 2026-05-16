<?php
declare(strict_types=1);
?>
<aside class="pd3-mid" data-help="Управление связями. Чекбоксы в таблицах задают селект; кнопки применяют действие.">

    <div class="pd3-mode-toggle" title="Lite/Full">
        <span class="pd3-mode-toggle__label"><span class="pd3-mode-toggle__full">Lite</span><span class="pd3-mode-toggle__short">L</span></span>
        <label class="pd3-switch">
            <input type="checkbox" id="pd3ModeToggle" aria-label="Lite/Full mode">
            <span class="pd3-switch__slider"></span>
        </label>
        <span class="pd3-mode-toggle__label"><span class="pd3-mode-toggle__full">Full</span><span class="pd3-mode-toggle__short">F</span></span>
    </div>

    <div class="pd3-mid__glass">
        <button type="button" class="pd3-mid__btn pd3-mid__btn--primary" id="pd3LinkMakeBtn"
                title="Связать выбранные"
                data-help-abs="Создать ручную связь между выбранными строками."
                disabled aria-disabled="true">🎯</button>

        <button type="button" class="pd3-mid__btn pd3-mid__btn--toggle" id="pd3HideLinkedBtn"
                title="Скрыть связанные"
                data-help-abs="Скрыть/показать уже связанные строки.">👁</button>

        <button type="button" class="pd3-mid__btn" id="pd3LinkAutoBtn"
                title="Автосвязи"
                data-help-abs="Автоматически связать совпадения по сумме/времени.">🧩</button>

        <button type="button" class="pd3-mid__btn" id="pd3LinkClearBtn"
                title="Разорвать"
                data-help-abs="Удалить связи у выбранных строк." disabled aria-disabled="true">⛓️‍💥</button>

        <div class="pd3-mid__sums">
            <div class="pd3-mid__sum-row"><span class="muted">←</span><span id="pd3SelSepaySum">0</span></div>
            <div class="pd3-mid__sum-row"><span class="muted">→</span><span id="pd3SelPosterSum">0</span></div>
            <div class="pd3-mid__match" id="pd3SelMatch" data-state="empty" aria-hidden="true">·</div>
            <div class="pd3-mid__diff" id="pd3SelDiff">0</div>
        </div>

        <ul class="pd3-mid__legend">
            <li><span class="pd3-legend-line pd3-legend-line--green"  aria-hidden="true"></span>Авто 1</li>
            <li><span class="pd3-legend-line pd3-legend-line--yellow" aria-hidden="true"></span>Авто 2</li>
            <li><span class="pd3-legend-line pd3-legend-line--gray"   aria-hidden="true"></span>Ручная</li>
        </ul>
    </div>

    <!-- Soft-reset (clearDayBtn equivalent). Marks sepay/poster rows in
         the range as was_deleted=1; the next sync brings them back.
         Sits at the bottom of the mid column, intentionally separated
         from the glass panel so it isn't a one-click mistake. -->
    <button type="button"
            class="pd3-soft-reset"
            id="pd3ClearDayBtn"
            title="Soft reset: Poster/SePay за дату помечаются was_deleted; без физического удаления; после синка записи восстановятся."
            data-help-abs="Soft-reset выбранного периода. Перенесёт все строки в was_deleted=1; следующая синхронизация их вернёт. Используй когда данные в таблицах визуально 'битые', а в почте/Poster они правильные.">
        <svg viewBox="0 0 24 24" aria-hidden="true">
            <path fill="currentColor" d="M12 5a7 7 0 1 1-6.2 3.8l1.7 1A5 5 0 1 0 12 7h-1.6l2.6 2.6L11.6 11 6 5.4 11.6 0l1.4 1.4L10.4 4H12Z"/>
        </svg>
    </button>
</aside>
