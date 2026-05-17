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
        <form class="pd3-modal__body pd3-form" id="pd3SettingsForm">
            <label class="pd3-field">
                <span>Telegram chat_id</span>
                <input type="text" name="telegram_chat_id" required>
            </label>
            <label class="pd3-field">
                <span>Telegram message_thread_id</span>
                <input type="text" name="telegram_message_thread_id">
            </label>
            <label class="pd3-field">
                <span>Service user_id (для удаления чеков)</span>
                <input type="number" name="service_user_id" required min="1">
            </label>
            <div class="pd3-form__row">
                <label class="pd3-field">
                    <span>Account «Андрей»</span>
                    <input type="number" name="accounts[andrey]" required min="1">
                </label>
                <label class="pd3-field">
                    <span>Account «Tips»</span>
                    <input type="number" name="accounts[tips]" required min="1">
                </label>
                <label class="pd3-field">
                    <span>Account «Vietnam»</span>
                    <input type="number" name="accounts[vietnam]" required min="1">
                </label>
            </div>
            <label class="pd3-field">
                <span>Balance sync account_id</span>
                <input type="number" name="balance_sinc_account_id" required min="1">
            </label>
            <div class="pd3-balances__footer">
                <button type="submit" class="pd3-btn">Сохранить</button>
                <span class="pd3-balances__status muted" id="pd3SettingsStatus"></span>
            </div>
        </form>
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

    <div class="pd3-modal pd3-modal--wide" id="pd3CheckFinderModal" role="dialog" aria-modal="true" aria-labelledby="pd3CheckFinderTitle" hidden>
        <header class="pd3-modal__header">
            <h2 id="pd3CheckFinderTitle">Поиск чека Poster</h2>
            <button type="button" class="pd3-modal__close" data-pd3-modal-close aria-label="Закрыть">×</button>
        </header>
        <div class="pd3-modal__body">
            <form id="pd3CheckFinderForm" class="pd3-form pd3-form--inline">
                <input type="text" id="pd3CheckFinderInput" autocomplete="off"
                       placeholder="Фильтр по номеру / id / официанту…">
                <button type="submit" class="pd3-btn">Искать по id</button>
            </form>
            <div id="pd3CheckFinderResult" class="pd3-modal__result">
                <p class="pd3-modal__loading">Загрузка списка чеков за период…</p>
            </div>
        </div>
    </div>
</div>
