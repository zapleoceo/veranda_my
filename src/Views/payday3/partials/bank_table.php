<?php
/** @var \App\Payday3\Domain\SepayTransaction[] $sepayOpen */
/** @var \App\Payday3\Domain\SepayTransaction[] $sepayHidden */
/** @var array<int,string>                       $rowStateBySepay */
declare(strict_types=1);

/**
 * Left pane «Деньги» — every bank movement of the day in ONE table:
 *
 *   tbody#pd3SepayTbody    incoming (SePay), server-rendered, time ↑
 *   tbody.pd3-bank-split   «Расходы» divider
 *   tbody#pd3OutMailTbody  outgoing (BIDV mail), filled by
 *                          out/bootstrap.js from live IMAP, time ↑
 *
 * One table (not two stacked ones) so both blocks share the exact same
 * column widths. Both row kinds have the same 7 cells:
 *   − | Content | Время | Сумма | + | ☐ | anchor
 * The JS re-renders (in/renderTables.js, out/renderTables.js) emit the
 * same markup — tests pin the contract on both sides.
 */

use App\Payday3\Domain\Money;

$bankColumns = 7;

$totalOpen = Money::vnd(0);
foreach ($sepayOpen as $s) $totalOpen = $totalOpen->plus($s->amount);

$h = static function (\App\Payday3\Domain\SepayTransaction $s, string $rowClass): string {
    $time = $s->transactionDate !== '' ? substr($s->transactionDate, 11, 8) : '';
    $contentForSort = mb_strtolower($s->content, 'UTF-8');
    ob_start();
    ?>
    <tr id="pd3-sepay-<?= $s->id ?>"
        class="pd3-row <?= htmlspecialchars($rowClass) ?>"
        data-sepay-id="<?= $s->id ?>"
        data-ts="<?= htmlspecialchars($s->transactionDate) ?>"
        data-sum="<?= $s->amount->amount ?>"
        data-content="<?= htmlspecialchars($contentForSort) ?>">
        <td class="pd3-col pd3-col--hide">
            <button type="button" class="pd3-row-hide" data-sepay-id="<?= $s->id ?>" title="Скрыть/восстановить">−</button>
        </td>
        <td class="pd3-col pd3-col--content"><?= htmlspecialchars($s->content) ?></td>
        <td class="pd3-col pd3-col--time nowrap"><?= htmlspecialchars($time) ?></td>
        <td class="pd3-col pd3-col--sum nowrap right"><?= htmlspecialchars($s->amount->format()) ?></td>
        <?php /* «+» → приход в Poster. Same markup as createTxButtonHtml()
                 in payday3/assets/js/ui/rowCreateTx.js (JS re-render). */ ?>
        <td class="pd3-col pd3-col--create"><button type="button" class="pd3-row-create" title="Создать приход в Poster на эту сумму" data-tx-type="1" data-amount="<?= $s->amount->amount ?>" data-date="<?= htmlspecialchars($s->transactionDate) ?>">+</button></td>
        <td class="pd3-col pd3-col--cb">
            <input type="checkbox" class="pd3-cb pd3-cb--sepay" data-sepay-id="<?= $s->id ?>" data-sum="<?= $s->amount->amount ?>">
        </td>
        <td class="pd3-col pd3-col--anchor"><span class="pd3-anchor" id="pd3-sepay-anchor-<?= $s->id ?>"></span></td>
    </tr>
    <?php
    return (string)ob_get_clean();
};
?>
<div class="pd3-pane pd3-pane--sepay pd3-pane--bank" data-pane="bank" data-help="Все движения денег по банку за день. Сверху — поступления (SePay), под разделителем — расходы (письма BIDV). Цвет строки: красный — не связано, зелёный — авто-связь, жёлтый — авто-связь с неоднозначностью, серый — ручная связь.">
    <header class="pd3-pane__header">
        <div class="pd3-pane__title">
            <span class="nowrap">Деньги</span>
            <button type="button" class="pd3-pill pd3-pill--sync" id="pd3SepaySyncBtn" title="Загрузить" data-help-abs="Загрузить свежие поступления из банка и письма о расходах.">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 2v6h-6"/>
                    <path d="M3 12a9 9 0 0 1 15-6.7L21 8"/>
                    <path d="M3 22v-6h6"/>
                    <path d="M21 12a9 9 0 0 1-15 6.7L3 16"/>
                </svg>
            </button>
        </div>
        <div class="pd3-pane__actions">
            <button type="button" class="pd3-eye" id="pd3SepayHiddenToggle"
                    title="Показать/скрыть скрытые"
                    data-help-abs="Показать/скрыть строки, которые оператор пометил кнопкой − (не наш платёж) — и поступления, и расходы."
                    aria-pressed="false">👁</button>
        </div>
    </header>
    <div class="pd3-pane__scroll" id="pd3BankScroll">
        <table class="pd3-table pd3-table--sepay" id="pd3BankTable">
            <thead>
                <tr>
                    <th class="pd3-col pd3-col--hide"></th>
                    <th class="pd3-col pd3-col--content pd3-sortable" data-sort-key="content">Content</th>
                    <th class="pd3-col pd3-col--time pd3-sortable nowrap" data-sort-key="ts">Время</th>
                    <th class="pd3-col pd3-col--sum pd3-sortable nowrap right" data-sort-key="sum">Сумма</th>
                    <th class="pd3-col pd3-col--create"></th>
                    <th class="pd3-col pd3-col--cb"></th>
                    <th class="pd3-col pd3-col--anchor"></th>
                </tr>
            </thead>
            <tbody id="pd3SepayTbody">
                <?php foreach ($sepayOpen as $s): ?>
                    <?= $h($s, $rowStateBySepay[$s->id] ?? 'row-red') ?>
                <?php endforeach; ?>
                <?php foreach ($sepayHidden as $s): ?>
                    <?= $h($s, 'row-hidden is-hidden') ?>
                <?php endforeach; ?>
                <?php if ($sepayOpen === [] && $sepayHidden === []): ?>
                    <tr class="pd3-empty"><td colspan="<?= $bankColumns ?>">Нет поступлений за период.</td></tr>
                <?php endif; ?>
            </tbody>
            <tbody class="pd3-bank-split">
                <tr><td colspan="<?= $bankColumns ?>">Расходы</td></tr>
            </tbody>
            <tbody id="pd3OutMailTbody">
                <tr class="pd3-empty"><td colspan="<?= $bankColumns ?>">Загрузка писем о расходах…</td></tr>
            </tbody>
        </table>
    </div>
    <footer class="pd3-pane__footer muted">
        <span>Приходы: <strong id="pd3SepayTotal"><?= htmlspecialchars($totalOpen->format()) ?></strong></span>
        <span>• связанные: <span id="pd3SepayLinked">—</span></span>
        <span>• несвязанные: <span id="pd3SepayUnlinked">—</span></span>
        <span>• Расходы: <strong id="pd3OutMailTotal">—</strong></span>
        <span>• писем: <span id="pd3OutMailCount">—</span></span>
    </footer>
</div>
