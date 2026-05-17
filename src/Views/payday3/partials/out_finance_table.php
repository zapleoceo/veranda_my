<?php
declare(strict_types=1);
/**
 * Right pane of the OUT graph — Poster finance.getTransactions.
 * Lead column carries the anchor + checkbox so the SVG connector
 * docks at the left edge.
 */
?>
<div class="pd3-pane pd3-pane--poster" data-pane="out-finance" data-help="Финансовые транзакции Poster (выплаты, переводы между счетами).">
    <header class="pd3-pane__header">
        <div class="pd3-pane__title">
            <span class="nowrap">Poster тр-ии</span>
            <button type="button" class="pd3-pill pd3-pill--sync" id="pd3OutFinanceReloadBtn" title="Перезагрузить" data-help-abs="Перезагрузить finance.getTransactions из Poster.">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 2v6h-6"/>
                    <path d="M3 12a9 9 0 0 1 15-6.7L21 8"/>
                    <path d="M3 22v-6h6"/>
                    <path d="M21 12a9 9 0 0 1-15 6.7L3 16"/>
                </svg>
            </button>
        </div>
    </header>
    <div class="pd3-pane__scroll" id="pd3OutFinanceScroll">
        <table class="pd3-table pd3-table--poster" id="pd3OutFinanceTable">
            <thead>
                <tr>
                    <th class="pd3-col pd3-col--lead"></th>
                    <th class="pd3-col pd3-col--time   nowrap">Дата</th>
                    <th class="pd3-col pd3-col--method">User</th>
                    <th class="pd3-col pd3-col--method">Категория</th>
                    <th class="pd3-col pd3-col--total  nowrap right">Сумма</th>
                    <th class="pd3-col pd3-col--card   nowrap right">Баланс</th>
                    <th class="pd3-col pd3-col--content">Комментарий</th>
                </tr>
            </thead>
            <tbody>
                <tr class="pd3-empty"><td colspan="7">Открой OUT, чтобы загрузить транзакции…</td></tr>
            </tbody>
        </table>
    </div>
    <footer class="pd3-pane__footer muted">
        <span>Итого: <strong id="pd3OutFinanceTotal">—</strong></span>
        <span>• транзакций: <span id="pd3OutFinanceCount">—</span></span>
    </footer>
</div>
