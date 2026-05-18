<?php
declare(strict_types=1);
/**
 * Итоговый баланс card. Compact 4×4 grid:
 *   Показатель | Poster | Факт. | Δ
 *
 * Poster column → /payday3/api/poster/balances (finance.getAccounts
 *                 mapped to Andrey+Tips / Vietnam / Cash account 2)
 * Факт. column  → operator-editable, auto-saves on blur to
 *                 /payday3/api/balances (no save button — debounced
 *                 commit happens implicitly while you tab between fields)
 * Δ             → Факт − Poster (client-side, red/green)
 *
 * Header buttons mirror payday2:
 *   ✈ Telegram  — sends an html2canvas screenshot of the card to the
 *                 configured chat/thread (POST /api/balances/telegram)
 *   ↻ Reload    — refresh Poster balances + accounts list
 *
 * Below the headline 4×4 grid is a second compact table that shows
 * every Poster account with its current balance — handy reference
 * when assigning supply payments / matching balance discrepancies.
 */
?>
<section class="pd3-card pd3-balances" id="pd3Balances">
    <header class="pd3-card__header">
        <h3>Итоговый баланс</h3>
        <div class="pd3-card__actions">
            <button type="button" class="pd3-pill pd3-pill--upld" id="pd3BalancesUpldBtn"
                    title="Скорректировать баланс Андрея в Poster (Факт. − Poster)"
                    data-help-abs="UPLD — создаёт корректирующую транзакцию в Poster на разницу между Факт. и Poster по строке Андрей. Доступна только когда есть ненулевая разница."
                    aria-label="UPLD" disabled>UPLD</button>
            <button type="button" class="pd3-pill" id="pd3BalancesTelegramBtn"
                    title="Отправить скриншот в Telegram"
                    data-help-abs="Снимок карточки Итоговый баланс уходит в Telegram-группу (chat/thread из ⚙ Настройки)."
                    aria-label="Отправить в Telegram">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                    <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm4.64 6.8c-.15 1.58-.8 5.42-1.13 7.19-.14.75-.42 1-.68 1.03-.58.05-1.02-.38-1.58-.75-.88-.58-1.38-.94-2.23-1.5-.99-.65-.35-1.01.22-1.59.15-.15 2.71-2.48 2.76-2.69a.2.2 0 00-.05-.18c-.06-.05-.14-.03-.21-.02-.09.02-1.42.91-4.01 2.66-.38.26-.72.39-1.03.38-.34-.01-1-.19-1.48-.35-.59-.19-1.05-.29-1.01-.61.02-.17.29-.35.81-.54 3.17-1.38 5.28-2.29 6.33-2.73 3.01-1.26 3.63-1.48 4.04-1.48.09 0 .29.02.4.11.09.07.12.16.13.25.01.12.02.26.01.37z"/>
                </svg>
            </button>
            <button type="button" class="pd3-pill pd3-pill--sync" id="pd3BalancesReloadBtn"
                    title="Обновить балансы из Poster"
                    data-help-abs="Перезапросить finance.getAccounts и пересчитать Poster-колонку + список всех счетов."
                    aria-label="Обновить">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 2v6h-6"/>
                    <path d="M3 12a9 9 0 0 1 15-6.7L21 8"/>
                    <path d="M3 22v-6h6"/>
                    <path d="M21 12a9 9 0 0 1-15 6.7L3 16"/>
                </svg>
            </button>
        </div>
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
                               placeholder="0"
                               <?= !empty($row['readonly']) ? 'readonly' : '' ?>>
                    </td>
                    <td class="right nowrap"><span class="pd3-bal-diff" id="pd3BalDiff_<?= htmlspecialchars($row['key']) ?>">—</span></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <!-- All Poster accounts list — server hydrates on reload click. -->
    <div class="pd3-bal-accounts" id="pd3BalAccountsWrap" hidden>
        <table class="pd3-table pd3-bal-accounts__table">
            <thead>
                <tr>
                    <th class="right">ID</th>
                    <th>Счёт</th>
                    <th class="right">Баланс</th>
                </tr>
            </thead>
            <tbody id="pd3BalAccountsTbody"></tbody>
        </table>
    </div>

    <footer class="pd3-card__footer">
        <span class="pd3-balances__status muted" id="pd3BalancesStatus">Авто-сохранение по blur</span>
    </footer>
</section>
