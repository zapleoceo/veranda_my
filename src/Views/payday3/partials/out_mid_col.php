<?php
declare(strict_types=1);
/**
 * OUT mid-column. Same controls as the IN one (the OUT-mode JS
 * adapter routes them to /api/out/* instead of /api/links/*).
 */
?>
<aside class="pd3-mid" data-help="OUT: связи между банковской почтой и финансовыми транзакциями Poster.">
    <div class="pd3-mode-toggle" title="Lite/Full">
        <span class="pd3-mode-toggle__label"><span class="pd3-mode-toggle__full">Lite</span><span class="pd3-mode-toggle__short">L</span></span>
        <label class="pd3-switch">
            <input type="checkbox" id="pd3OutModeToggle" aria-label="Lite/Full mode">
            <span class="pd3-switch__slider"></span>
        </label>
        <span class="pd3-mode-toggle__label"><span class="pd3-mode-toggle__full">Full</span><span class="pd3-mode-toggle__short">F</span></span>
    </div>

    <div class="pd3-mid__glass">
        <button type="button" class="pd3-mid__btn pd3-mid__btn--primary" id="pd3OutLinkMakeBtn"
                title="Связать выбранные" disabled aria-disabled="true">🎯</button>
        <button type="button" class="pd3-mid__btn pd3-mid__btn--toggle" id="pd3OutHideLinkedBtn"
                title="Скрыть связанные">👁</button>
        <button type="button" class="pd3-mid__btn" id="pd3OutLinkAutoBtn"
                title="Автосвязи">🧩</button>
        <button type="button" class="pd3-mid__btn" id="pd3OutLinkClearBtn"
                title="Снять все связи">⛓️‍💥</button>

        <div class="pd3-mid__sums">
            <div class="pd3-mid__sum-row"><span class="muted">←</span><span id="pd3OutSelMailSum">0</span></div>
            <div class="pd3-mid__sum-row"><span class="muted">→</span><span id="pd3OutSelFinanceSum">0</span></div>
            <div class="pd3-mid__match" id="pd3OutSelMatch" data-state="empty" aria-hidden="true">·</div>
            <div class="pd3-mid__diff"  id="pd3OutSelDiff">0</div>
        </div>
    </div>
</aside>
