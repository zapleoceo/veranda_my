<?php
/** @var \App\Payday3\Domain\PosterTransaction[] $poster */
/** @var array<int,string>                        $rowStateByPoster */
declare(strict_types=1);

use App\Payday3\Domain\Money;

$total = Money::vnd(0);
foreach ($poster as $p) $total = $total->plus($p->totalPayed());
?>
<div class="pd3-pane pd3-pane--poster" data-pane="poster" data-help="Чеки Poster, оплаченные картой или 3-rd party. Сюда же попадают чаевые.">
    <header class="pd3-pane__header">
        <div class="pd3-pane__title">
            <span class="nowrap">Poster чеки</span>
            <button type="button" class="pd3-pill pd3-pill--sync" id="pd3PosterSyncBtn" title="Загрузить" data-help-abs="Загрузить актуальные чеки из Poster.">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 2v6h-6"/>
                    <path d="M3 12a9 9 0 0 1 15-6.7L21 8"/>
                    <path d="M3 22v-6h6"/>
                    <path d="M21 12a9 9 0 0 1-15 6.7L3 16"/>
                </svg>
            </button>
        </div>
        <div class="pd3-pane__actions">
            <button type="button" class="pd3-eye" id="pd3VietnamToggle" title="Скрыть/показать Vietnam Company" aria-pressed="true">👁</button>
        </div>
    </header>
    <div class="pd3-pane__scroll" id="pd3PosterScroll">
        <table class="pd3-table pd3-table--poster" id="pd3PosterTable">
            <thead>
                <tr>
                    <!-- Anchor + checkbox in a single LEFT-edge cell, mirroring payday2.
                         The poster table sits on the right of the graph, so its leftmost
                         column is the one closest to the sepay table and the line layer. -->
                    <th class="pd3-col pd3-col--lead"></th>
                    <th class="pd3-col pd3-col--num    pd3-sortable nowrap" data-sort-key="num">№</th>
                    <th class="pd3-col pd3-col--time   pd3-sortable nowrap" data-sort-key="ts">Время</th>
                    <th class="pd3-col pd3-col--card   pd3-sortable nowrap right" data-sort-key="card">Card</th>
                    <th class="pd3-col pd3-col--tips   pd3-sortable nowrap right" data-sort-key="tips">Tips</th>
                    <th class="pd3-col pd3-col--total  pd3-sortable nowrap right" data-sort-key="total">Card+Tips</th>
                    <th class="pd3-col pd3-col--method pd3-sortable" data-sort-key="method">Метод</th>
                    <th class="pd3-col pd3-col--waiter pd3-sortable" data-sort-key="waiter">Официант</th>
                    <th class="pd3-col pd3-col--table  pd3-sortable nowrap" data-sort-key="table">Стол</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($poster as $p):
                    $total_ = $p->totalPayed();
                    $time   = $p->dateClose !== '' ? substr($p->dateClose, 11, 8) : '';
                    $rowClass = $rowStateByPoster[$p->transactionId] ?? 'row-red';
                ?>
                    <tr id="pd3-poster-<?= $p->transactionId ?>"
                        class="pd3-row <?= htmlspecialchars($rowClass) ?>"
                        data-poster-id="<?= $p->transactionId ?>"
                        data-num="<?= $p->receiptNumber !== '' ? htmlspecialchars($p->receiptNumber) : (string)$p->transactionId ?>"
                        data-ts="<?= htmlspecialchars($p->dateClose) ?>"
                        data-card="<?= $p->payedCard->amount ?>"
                        data-tips="<?= $p->tipSum->amount ?>"
                        data-total="<?= $total_->amount ?>"
                        data-method="<?= htmlspecialchars((string)($p->paymentMethodDisplay ?? '')) ?>"
                        data-waiter="<?= htmlspecialchars($p->waiterName) ?>"
                        data-table="<?= $p->tableId ?>">
                        <td class="pd3-col pd3-col--lead">
                            <div class="pd3-lead">
                                <span class="pd3-anchor" id="pd3-poster-anchor-<?= $p->transactionId ?>"></span>
                                <input type="checkbox" class="pd3-cb pd3-cb--poster" data-poster-id="<?= $p->transactionId ?>" data-sum="<?= $total_->amount ?>">
                            </div>
                        </td>
                        <td class="pd3-col pd3-col--num    nowrap"><?= htmlspecialchars($p->receiptNumber !== '' ? $p->receiptNumber : (string)$p->transactionId) ?></td>
                        <td class="pd3-col pd3-col--time   nowrap"><?= htmlspecialchars($time) ?></td>
                        <td class="pd3-col pd3-col--card   nowrap right"><?= htmlspecialchars($p->payedCard->format()) ?></td>
                        <td class="pd3-col pd3-col--tips   nowrap right"><?= htmlspecialchars($p->tipSum->format()) ?></td>
                        <td class="pd3-col pd3-col--total  nowrap right"><strong><?= htmlspecialchars($total_->format()) ?></strong></td>
                        <td class="pd3-col pd3-col--method">
                            <?php
                                $pmFull = (string)($p->paymentMethodDisplay ?? '—');
                                // Payment-method abbreviation in Lite mode — matches
                                // payday2: Vietnam Company → VC, Bybit → BB, anything
                                // else keeps its full label.
                                $pmLite = $pmFull;
                                if (stripos($pmFull, 'vietnam') !== false)      $pmLite = 'VC';
                                else if (stripos($pmFull, 'bybit')  !== false) $pmLite = 'BB';
                            ?>
                            <span class="pm-full"><?= htmlspecialchars($pmFull) ?></span>
                            <span class="pm-lite" aria-hidden="true"><?= htmlspecialchars($pmLite) ?></span>
                        </td>
                        <td class="pd3-col pd3-col--waiter"><?= htmlspecialchars($p->waiterName) ?></td>
                        <td class="pd3-col pd3-col--table  nowrap"><?= $p->tableId ?: '—' ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($poster === []): ?>
                    <tr class="pd3-empty"><td colspan="9">Нет чеков Poster за период.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <footer class="pd3-pane__footer muted">
        <span>Итого: <strong id="pd3PosterTotal"><?= htmlspecialchars($total->format()) ?></strong></span>
        <span>• чеков: <span id="pd3PosterCount"><?= count($poster) ?></span></span>
        <span>• связанные: <span id="pd3PosterLinked">—</span></span>
        <span>• несвязанные: <span id="pd3PosterUnlinked">—</span></span>
    </footer>
</div>
