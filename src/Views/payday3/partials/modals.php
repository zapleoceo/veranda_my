<?php
declare(strict_types=1);

/**
 * Modal containers. The shell is shared; each toolbar button toggles
 * a different content block by id. JS (ui/modals.js) wires the
 * triggers and binds Esc / backdrop-click / close-button to dismiss.
 *
 * The structure follows payday2's pattern but with one host element
 * instead of N independent modals — DRY.
 */
?>
<div class="pd3-modal-host" id="pd3ModalHost" aria-hidden="true" hidden>
    <div class="pd3-modal-backdrop" data-pd3-modal-close></div>

    <div class="pd3-modal" id="pd3InfoModal" role="dialog" aria-modal="true" aria-labelledby="pd3InfoModalTitle" hidden>
        <header class="pd3-modal__header">
            <h2 id="pd3InfoModalTitle">PayDay3</h2>
            <button type="button" class="pd3-modal__close" data-pd3-modal-close aria-label="Закрыть">×</button>
        </header>
        <div class="pd3-modal__body">
            <p>PayDay3 — рефакторинг payday2 под Slim 4 (SOLID/DRY).</p>
            <p class="muted">Сверка банковских поступлений (Sepay) и чеков Poster. Связи можно создавать вручную (галочками + 🎯) или авто-матчер 🧩 по сумме и дате.</p>
            <ul class="pd3-modal__legend">
                <li><span class="pd3-legend-line pd3-legend-line--green"></span>Авто-match: одна сумма, один кандидат с каждой стороны</li>
                <li><span class="pd3-legend-line pd3-legend-line--yellow"></span>Авто-match с неоднозначностью (несколько кандидатов)</li>
                <li><span class="pd3-legend-line pd3-legend-line--gray"></span>Связь создана вручную</li>
            </ul>
        </div>
    </div>

    <div class="pd3-modal" id="pd3SettingsModal" role="dialog" aria-modal="true" aria-labelledby="pd3SettingsModalTitle" hidden>
        <header class="pd3-modal__header">
            <h2 id="pd3SettingsModalTitle">Настройки</h2>
            <button type="button" class="pd3-modal__close" data-pd3-modal-close aria-label="Закрыть">×</button>
        </header>
        <div class="pd3-modal__body">
            <p>Telegram-бот для отчётов о балансах, счета Poster для авто-сопоставления, категории финансовых транзакций.</p>
            <p class="muted">Пока редактируется в <a href="/payday2" class="pd3-link" target="_blank">payday2 → ⚙</a>.
            Перенос в payday3 запланирован отдельным этапом — там много полей (BIDV cookies, CSRF, Poster admin curl), требующих своих сервисов.</p>
        </div>
    </div>

    <div class="pd3-modal" id="pd3KashShiftModal" role="dialog" aria-modal="true" aria-labelledby="pd3KashShiftTitle" hidden>
        <header class="pd3-modal__header">
            <h2 id="pd3KashShiftTitle">Кассовые смены</h2>
            <button type="button" class="pd3-modal__close" data-pd3-modal-close aria-label="Закрыть">×</button>
        </header>
        <div class="pd3-modal__body" id="pd3KashShiftBody">
            <p class="pd3-modal__loading">Загрузка кассовых смен…</p>
        </div>
    </div>

    <div class="pd3-modal" id="pd3SuppliesModal" role="dialog" aria-modal="true" aria-labelledby="pd3SuppliesTitle" hidden>
        <header class="pd3-modal__header">
            <h2 id="pd3SuppliesTitle">Поставки</h2>
            <button type="button" class="pd3-modal__close" data-pd3-modal-close aria-label="Закрыть">×</button>
        </header>
        <div class="pd3-modal__body" id="pd3SuppliesBody">
            <p class="pd3-modal__loading">Загрузка поставок…</p>
        </div>
    </div>

    <div class="pd3-modal" id="pd3CheckFinderModal" role="dialog" aria-modal="true" aria-labelledby="pd3CheckFinderTitle" hidden>
        <header class="pd3-modal__header">
            <h2 id="pd3CheckFinderTitle">Поиск чека Poster</h2>
            <button type="button" class="pd3-modal__close" data-pd3-modal-close aria-label="Закрыть">×</button>
        </header>
        <div class="pd3-modal__body">
            <form id="pd3CheckFinderForm" class="pd3-form">
                <label class="pd3-field">
                    <span>Номер чека или transaction_id</span>
                    <input type="text" id="pd3CheckFinderInput" inputmode="numeric" autocomplete="off" required>
                </label>
                <button type="submit" class="pd3-btn">Искать</button>
            </form>
            <div id="pd3CheckFinderResult" class="pd3-modal__result muted">Введи номер чека и нажми «Искать».</div>
        </div>
    </div>
</div>
