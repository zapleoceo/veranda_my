<?php
declare(strict_types=1);
/**
 * Actual-balances card (Phase 8). Operator types in physical cash
 * on hand at end of day; the diff against Poster's reported balance
 * lights up red / green. JS posts to /payday3/api/balances on blur.
 *
 * Telegram-screenshot button captures a PNG of the card via
 * html2canvas (Phase 9 — not wired in this commit; button present
 * for parity with payday2 layout).
 */
?>
<section class="pd3-card pd3-balances" id="pd3Balances">
    <header class="pd3-balances__header">
        <h3>Фактические балансы</h3>
        <button type="button" class="pd3-pill pd3-pill--sync" id="pd3BalancesReloadBtn" title="Перезагрузить">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <path d="M21 2v6h-6"/>
                <path d="M3 12a9 9 0 0 1 15-6.7L21 8"/>
                <path d="M3 22v-6h6"/>
                <path d="M21 12a9 9 0 0 1-15 6.7L3 16"/>
            </svg>
        </button>
    </header>
    <table class="pd3-table pd3-bal-table">
        <thead>
            <tr><th>Счёт</th><th class="right">Факт</th></tr>
        </thead>
        <tbody>
            <tr><td>Андрей</td>
                <td class="right"><input type="text" inputmode="numeric" class="pd3-bal-input" id="pd3BalAndrey"  data-key="bal_andrey"></td>
            </tr>
            <tr><td>Vietnam</td>
                <td class="right"><input type="text" inputmode="numeric" class="pd3-bal-input" id="pd3BalVietnam" data-key="bal_vietnam"></td>
            </tr>
            <tr><td>Cash</td>
                <td class="right"><input type="text" inputmode="numeric" class="pd3-bal-input" id="pd3BalCash"    data-key="bal_cash"></td>
            </tr>
            <tr><td><strong>Итого</strong></td>
                <td class="right"><input type="text" inputmode="numeric" class="pd3-bal-input" id="pd3BalTotal"   data-key="bal_total"></td>
            </tr>
        </tbody>
    </table>
    <footer class="pd3-balances__footer">
        <button type="button" class="pd3-btn" id="pd3BalancesSaveBtn">Сохранить</button>
        <span class="pd3-balances__status muted" id="pd3BalancesStatus"></span>
    </footer>
</section>
