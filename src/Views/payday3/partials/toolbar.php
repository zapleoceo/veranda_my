<?php
/** @var \App\Payday3\Domain\DateRange $range */
declare(strict_types=1);
?>
<header class="pd3-toolbar">
    <div class="pd3-toolbar__left">
        <h1 class="pd3-toolbar__title" id="pd3InfoBtn" title="О модуле">PayDay3</h1>

        <button type="button" class="pd3-icon-btn" id="pd3HelpBtn" title="Справка" data-help-abs="Включить подсказки по интерфейсу.">
            <span aria-hidden="true">❓</span>
        </button>
        <button type="button" class="pd3-icon-btn" id="pd3SettingsBtn" title="Настройки" data-help-abs="Настройки интеграции (Telegram, счета Poster).">
            <span aria-hidden="true">⚙</span>
        </button>
        <button type="button" class="pd3-icon-btn" id="pd3KashShiftBtn" title="Кассовые смены" data-help-abs="Кассовые смены из Poster.">
            <!-- pos terminal -->
            <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="currentColor" d="M4 4h16v4H4zm0 6h16v10H4zM8 14h2v2H8zm4 0h2v2h-2zm4 0h2v2h-2z"/></svg>
        </button>
        <button type="button" class="pd3-icon-btn" id="pd3SuppliesBtn" title="Поставки" data-help-abs="Поставки/закупки из Poster.">
            <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="currentColor" d="M3 7h13v3h3l2 4v4h-2a2 2 0 1 1-4 0H9a2 2 0 1 1-4 0H3V7Zm13 5v2h4l-1.4-2H16Z"/></svg>
        </button>
        <button type="button" class="pd3-icon-btn" id="pd3CheckFinderBtn" title="Поиск чека" data-help-abs="Поиск/удаление чека Poster по номеру.">
            <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="currentColor" d="M6 2h12v20l-2-1-2 1-2-1-2 1-2-1-2 1V2Zm2 4v2h8V6H8Zm0 4v2h8v-2H8Zm0 4v2h6v-2H8Z"/></svg>
        </button>

        <!-- Font-scale: cycles current/larger/largest. zoom on .pd3-page
             rescales the entire UI (typography + spacing + radii + line
             overlay) because every layout-affecting value goes through
             --pd3-* tokens. Choice persists in localStorage. -->
        <div class="pd3-fontscale" role="group" aria-label="Размер шрифта" data-help-abs="Размер шрифта: текущий / крупнее / очень крупный.">
            <button type="button" class="pd3-fontscale__btn is-active" data-scale="1"   title="Размер шрифта: обычный">а</button>
            <button type="button" class="pd3-fontscale__btn"           data-scale="1.2" title="Размер шрифта: крупнее">А</button>
            <button type="button" class="pd3-fontscale__btn"           data-scale="1.5" title="Размер шрифта: очень крупный">А</button>
        </div>
    </div>

    <nav class="pd3-tabs" aria-label="Режим сверки">
        <button type="button" class="pd3-tab is-active" data-tab="in"  data-help-abs="Сверка входящих платежей.">IN</button>
        <button type="button" class="pd3-tab"           data-tab="out" data-help-abs="Сверка исходящих платежей.">OUT</button>
    </nav>

    <form class="pd3-toolbar__dates" method="get" action="/payday3" id="pd3DateForm">
        <!-- Auto-submit on date change — no explicit "Открыть" button. -->
        <input type="date" name="dateFrom" class="pd3-date" value="<?= htmlspecialchars($range->from) ?>" required>
        <button type="button" class="pd3-icon-btn pd3-icon-btn--small" id="pd3DateRangeToggle" title="Диапазон дат" data-help-abs="Включить диапазон вместо одного дня.">
            <svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true"><path fill="currentColor" d="M5 12h14v2H5z"/></svg>
        </button>
        <input type="date" name="dateTo" class="pd3-date pd3-date--to <?= $range->isSingleDay() ? 'is-hidden' : '' ?>" value="<?= htmlspecialchars($range->to) ?>">
        <span class="pd3-spinner is-hidden" id="pd3DateSpinner" aria-hidden="true">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M21 12a9 9 0 1 1-6.219-8.56"></path></svg>
        </span>
    </form>
</header>
