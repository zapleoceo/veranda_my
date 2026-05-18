<?php
declare(strict_types=1);
/**
 * Финансовые транзакции card. Two-row layout:
 *   - Vietnam Company — expected transfer = sum of card+third+tip for
 *     checks paid with poster_payment_method_id=11 in the range.
 *   - Tips           — expected transfer = sum of tip_sum on linked
 *     checks (excluding Vietnam checks) in the range.
 *
 * Server renders an empty shell; ui/financeTransfers.js fills the
 * mini-tables from /payday3/api/finance/transfers on page boot and
 * after every sync. Card auto-fits to content (sits next to
 * Итоговый баланс in .pd3-bottom-row).
 */
?>
<section class="pd3-card pd3-finance" id="pd3Finance">
    <header class="pd3-card__header">
        <h3>Финансовые транзакции</h3>
        <button type="button" class="pd3-pill pd3-pill--sync" id="pd3FinanceReloadBtn"
                title="Обновить"
                data-help-abs="Пересчитать ожидаемые переводы Vietnam/Tips и список найденных в Poster транзакций.">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <path d="M21 2v6h-6"/>
                <path d="M3 12a9 9 0 0 1 15-6.7L21 8"/>
                <path d="M3 22v-6h6"/>
                <path d="M21 12a9 9 0 0 1-15 6.7L3 16"/>
            </svg>
        </button>
    </header>
    <?php foreach ([
        ['kind' => 'vietnam', 'title' => 'Vietnam'],
        ['kind' => 'tips',    'title' => 'Tips'],
    ] as $row): ?>
        <div class="pd3-finance__row" data-kind="<?= htmlspecialchars($row['kind']) ?>">
            <div class="pd3-finance__head">
                <span class="pd3-finance__title"><?= htmlspecialchars($row['title']) ?></span>
                <span class="pd3-finance__total" id="pd3FinanceTotal_<?= htmlspecialchars($row['kind']) ?>">—</span>
                <button type="button"
                        class="pd3-btn pd3-btn--sm pd3-finance__create"
                        id="pd3FinanceCreateBtn_<?= htmlspecialchars($row['kind']) ?>"
                        data-kind="<?= htmlspecialchars($row['kind']) ?>"
                        title="Создать транзакцию"
                        data-help-abs="Создать перевод в Poster на ожидаемую сумму. Доступна когда сумма ненулевая и совпадающей транзакции в Poster ещё нет."
                        disabled>Создать</button>
            </div>
            <div class="pd3-finance__status muted" id="pd3FinanceStatus_<?= htmlspecialchars($row['kind']) ?>">Загрузка…</div>
        </div>
    <?php endforeach; ?>
</section>
