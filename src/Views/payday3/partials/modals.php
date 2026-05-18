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

    <div class="pd3-modal pd3-modal--wide" id="pd3SettingsModal" role="dialog" aria-modal="true" aria-labelledby="pd3SettingsModalTitle" hidden>
        <header class="pd3-modal__header">
            <h2 id="pd3SettingsModalTitle">Настройки</h2>
            <button type="button" class="pd3-modal__close" data-pd3-modal-close aria-label="Закрыть">×</button>
        </header>
        <form class="pd3-modal__body pd3-form pd3-settings" id="pd3SettingsForm">
            <p class="muted pd3-settings__note">Хранится в БД (<code>payday3_settings.config_json</code>). Раньше — payday2/local_config.json.</p>

            <details class="pd3-settings__group" open>
                <summary>Telegram</summary>
                <p class="pd3-settings__hint">
                    Для супергрупп <code>chat_id</code> = <code>-100&lt;internal id&gt;</code>.
                    Можно вставить ссылку на тему (<code>https://t.me/c/12345/678/…</code>) —
                    распарсится в chat + thread автоматически.
                </p>
                <div class="pd3-form__row">
                    <label class="pd3-field">
                        <span>chat_id (или t.me URL)</span>
                        <input type="text" name="telegram_chat_id" required autocomplete="off"
                               placeholder="-1003889942420">
                    </label>
                    <label class="pd3-field">
                        <span>message_thread_id</span>
                        <input type="text" name="telegram_message_thread_id" autocomplete="off"
                               placeholder="пусто = без темы">
                    </label>
                </div>
            </details>

            <details class="pd3-settings__group" open>
                <summary>Postеr — счета и сервисный user</summary>
                <div class="pd3-form__row">
                    <label class="pd3-field">
                        <span>service_user_id</span>
                        <input type="number" name="service_user_id" required min="1">
                    </label>
                    <label class="pd3-field">
                        <span>Андрей (id)</span>
                        <input type="number" name="accounts[andrey]" required min="1">
                    </label>
                    <label class="pd3-field">
                        <span>Tips (id)</span>
                        <input type="number" name="accounts[tips]" required min="1">
                    </label>
                    <label class="pd3-field">
                        <span>Vietnam (id)</span>
                        <input type="number" name="accounts[vietnam]" required min="1">
                    </label>
                    <label class="pd3-field">
                        <span>Чай (balance_sinc, id)</span>
                        <input type="number" name="balance_sinc_account_id" required min="1">
                    </label>
                </div>
            </details>

            <details class="pd3-settings__group">
                <summary>Poster Admin (edit-check сессия)</summary>
                <p class="muted pd3-settings__hint">Вставьте Cookie-строку из DevTools и нажмите «Разобрать» — поля заполнятся автоматически.</p>
                <div class="pd3-form__row">
                    <label class="pd3-field" style="flex:1">
                        <span>Cookie</span>
                        <input type="text" id="pd3SettCookie" autocomplete="off"
                               placeholder="account_url=restpublica2; pos_session=...; ssid=...; csrf_cookie_poster=...">
                    </label>
                    <button type="button" class="pd3-btn pd3-btn--sm" id="pd3SettCookieParseBtn">Разобрать</button>
                </div>
                <div class="pd3-form__row">
                    <label class="pd3-field">
                        <span>account_url</span>
                        <input type="text" name="poster_admin[account]" autocomplete="off" placeholder="restpublica2">
                    </label>
                    <label class="pd3-field">
                        <span>ssid</span>
                        <input type="text" name="poster_admin[ssid]" autocomplete="off">
                    </label>
                    <label class="pd3-field">
                        <span>csrf_cookie_poster</span>
                        <input type="text" name="poster_admin[csrf]" autocomplete="off">
                    </label>
                    <label class="pd3-field">
                        <span>pos_session</span>
                        <input type="text" name="poster_admin[pos_session]" autocomplete="off">
                    </label>
                    <label class="pd3-field" style="flex:1">
                        <span>user_agent (опционально)</span>
                        <input type="text" name="poster_admin[user_agent]" autocomplete="off">
                    </label>
                </div>
            </details>

            <details class="pd3-settings__group">
                <summary>Категории Poster (whitelist + кастомные имена)</summary>
                <p class="muted pd3-settings__hint">Чекбокс — пускать в выпадающие списки. Поле справа — кастомное имя (пусто = из Poster).</p>
                <div class="pd3-settings__categories" id="pd3SettCategoriesList">
                    <div class="muted">Откройте раздел чтобы загрузить категории…</div>
                </div>
            </details>

            <footer class="pd3-settings__footer">
                <button type="submit" class="pd3-btn">Сохранить</button>
                <span class="pd3-balances__status muted" id="pd3SettingsStatus"></span>
            </footer>
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
