<?php
declare(strict_types=1);
/**
 * Left pane of the OUT graph — BIDV outgoing-mail rows. tbody starts
 * empty; outBootstrap.js fetches /payday3/api/out/data on first OUT
 * activation and rerenders this tbody (with anchors that line up with
 * the SVG endpoints in #pd3OutLineLayer).
 */
?>
<div class="pd3-pane pd3-pane--sepay" data-pane="out-mail" data-help="BIDV исходящие платежи. Парсятся из IMAP в реальном времени.">
    <header class="pd3-pane__header">
        <div class="pd3-pane__title">
            <span class="nowrap">Деньги 📧</span>
            <button type="button" class="pd3-pill pd3-pill--sync" id="pd3OutMailReloadBtn" title="Перезагрузить" data-help-abs="Заново забрать письма BIDV из IMAP.">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 2v6h-6"/>
                    <path d="M3 12a9 9 0 0 1 15-6.7L21 8"/>
                    <path d="M3 22v-6h6"/>
                    <path d="M21 12a9 9 0 0 1-15 6.7L3 16"/>
                </svg>
            </button>
        </div>
        <div class="pd3-pane__actions">
            <button type="button" class="pd3-eye" id="pd3OutMailHiddenToggle"
                    title="Показать/скрыть скрытые"
                    data-help-abs="Показать/скрыть письма BIDV, которые оператор пометил кнопкой − (не наш платёж)."
                    aria-pressed="false">👁</button>
        </div>
    </header>
    <div class="pd3-pane__scroll" id="pd3OutMailScroll">
        <table class="pd3-table pd3-table--sepay" id="pd3OutMailTable">
            <thead>
                <tr>
                    <th class="pd3-col pd3-col--hide"></th>
                    <th class="pd3-col pd3-col--content">Content</th>
                    <th class="pd3-col pd3-col--time nowrap">Время</th>
                    <th class="pd3-col pd3-col--sum  nowrap right">Сумма</th>
                    <th class="pd3-col pd3-col--create"></th>
                    <th class="pd3-col pd3-col--cb"></th>
                    <th class="pd3-col pd3-col--anchor"></th>
                </tr>
            </thead>
            <tbody>
                <tr class="pd3-empty"><td colspan="7">Открой OUT, чтобы загрузить почту…</td></tr>
            </tbody>
        </table>
    </div>
    <footer class="pd3-pane__footer muted">
        <span>Итого: <strong id="pd3OutMailTotal">—</strong></span>
        <span>• писем: <span id="pd3OutMailCount">—</span></span>
    </footer>
</div>
