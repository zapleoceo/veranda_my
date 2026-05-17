<?php
declare(strict_types=1);
/**
 * Итоговый баланс card. Compact 4×4 grid:
 *   Показатель | Poster | Факт. | Δ
 *
 * Poster column → /payday3/api/poster/balances (finance.getAccounts
 *                 mapped to Andrey+Tips / Vietnam / Cash account 2)
 * Факт. column  → operator-editable, persists via /payday3/api/balances
 * Δ             → Факт − Poster (client-side, red/green)
 *
 * Lives in `.pd3-bottom-row` next to Финансовые транзакции; cells
 * are nowrap + width:auto so the table shrinks to its content.
 */
?>
<section class="pd3-card pd3-balances" id="pd3Balances">
    <header class="pd3-card__header">
        <h3>Итоговый баланс</h3>
        <button type="button" class="pd3-pill pd3-pill--sync" id="pd3BalancesReloadBtn" title="Обновить">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <path d="M21 2v6h-6"/>
                <path d="M3 12a9 9 0 0 1 15-6.7L21 8"/>
                <path d="M3 22v-6h6"/>
                <path d="M21 12a9 9 0 0 1-15 6.7L3 16"/>
            </svg>
        </button>
    </header>
    <table class="pd3-table pd3-bal-table">
        <thead>
            <tr>
                <th></th>
                <th class="right">Poster</th>
                <th class="right">Факт.</th>
                <th class="right">Δ</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ([
                ['key' => 'andrey',  'label' => 'Андрей'],
                ['key' => 'vietnam', 'label' => 'Вьет.'],
                ['key' => 'cash',    'label' => 'Касса'],
                ['key' => 'total',   'label' => 'Total', 'readonly' => true],
            ] as $row): ?>
                <tr data-key="<?= htmlspecialchars($row['key']) ?>">
                    <td class="pd3-bal-table__label"><?= htmlspecialchars($row['label']) ?></td>
                    <td class="right nowrap"><span class="pd3-bal-poster" id="pd3BalPoster_<?= htmlspecialchars($row['key']) ?>">—</span></td>
                    <td class="right">
                        <input type="text"
                               inputmode="numeric"
                               class="pd3-bal-input"
                               id="pd3BalActual_<?= htmlspecialchars($row['key']) ?>"
                               data-key="bal_<?= htmlspecialchars($row['key']) ?>"
                               <?= !empty($row['readonly']) ? 'readonly' : '' ?>>
                    </td>
                    <td class="right nowrap"><span class="pd3-bal-diff" id="pd3BalDiff_<?= htmlspecialchars($row['key']) ?>">—</span></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <footer class="pd3-card__footer">
        <button type="button" class="pd3-btn pd3-btn--sm" id="pd3BalancesSaveBtn">Сохранить</button>
        <span class="pd3-balances__status muted" id="pd3BalancesStatus"></span>
    </footer>
</section>
