<?php
declare(strict_types=1);
/**
 * Итоговый баланс (Phase 8). Mirrors payday2's bal-grid 4×3:
 *   Показатель | Poster | Факт. | Разница
 *
 * Poster column → /payday3/api/poster/balances (finance.getAccounts
 *                 mapped via the 3 configured account_ids)
 * Факт. column  → editable, persists to payday_actual_balances via
 *                 /payday3/api/balances
 * Разница       → Факт − Poster (computed client-side; red/green)
 */
?>
<section class="pd3-card pd3-balances" id="pd3Balances">
    <header class="pd3-balances__header">
        <h3>Итоговый баланс</h3>
        <button type="button" class="pd3-pill pd3-pill--sync" id="pd3BalancesReloadBtn" title="Обновить">
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
            <tr>
                <th>Показатель</th>
                <th class="right">Poster</th>
                <th class="right">Факт.</th>
                <th class="right">Разница</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ([
                ['key' => 'andrey',  'label' => 'Счет Андрей'],
                ['key' => 'vietnam', 'label' => 'Вьет. счет'],
                ['key' => 'cash',    'label' => 'Касса'],
                ['key' => 'total',   'label' => 'Total', 'readonly' => true],
            ] as $row): ?>
                <tr data-key="<?= htmlspecialchars($row['key']) ?>">
                    <td class="pd3-bal-table__label"><?= htmlspecialchars($row['label']) ?></td>
                    <td class="right"><span class="pd3-bal-poster" id="pd3BalPoster_<?= htmlspecialchars($row['key']) ?>">—</span></td>
                    <td class="right">
                        <input type="text"
                               inputmode="numeric"
                               class="pd3-bal-input"
                               id="pd3BalActual_<?= htmlspecialchars($row['key']) ?>"
                               data-key="bal_<?= htmlspecialchars($row['key']) ?>"
                               <?= !empty($row['readonly']) ? 'readonly' : '' ?>>
                    </td>
                    <td class="right"><span class="pd3-bal-diff" id="pd3BalDiff_<?= htmlspecialchars($row['key']) ?>">—</span></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <footer class="pd3-balances__footer">
        <button type="button" class="pd3-btn" id="pd3BalancesSaveBtn">Сохранить факт</button>
        <span class="pd3-balances__status muted" id="pd3BalancesStatus"></span>
    </footer>
</section>
