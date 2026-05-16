<?php
/** @var \App\Payday3\Domain\PosterTransaction[] $poster */
declare(strict_types=1);
?>
<div class="pd3-pane pd3-pane--poster">
    <div class="pd3-pane__header">
        <h3>Poster</h3>
        <span class="muted"><?= count($poster) ?> чеков</span>
    </div>
    <div class="pd3-pane__scroll" id="pd3PosterScroll">
        <table class="pd3-table pd3-table--poster" id="pd3PosterTable">
            <thead>
                <tr>
                    <th class="nowrap">Закрыт</th>
                    <th class="right">Чек</th>
                    <th class="right">Карта</th>
                    <th class="right">3-rd party</th>
                    <th>Метод</th>
                    <th>Официант</th>
                    <th class="nowrap">Стол</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($poster as $p): ?>
                    <tr id="pd3-poster-<?= $p->transactionId ?>" data-poster-id="<?= $p->transactionId ?>">
                        <td class="nowrap"><?= htmlspecialchars($p->dateClose) ?></td>
                        <td class="right nowrap"><?= htmlspecialchars($p->receiptNumber) ?></td>
                        <td class="right nowrap"><?= htmlspecialchars($p->payedCard->format()) ?></td>
                        <td class="right nowrap"><?= htmlspecialchars($p->payedThirdParty->format()) ?></td>
                        <td><?= htmlspecialchars($p->paymentMethodDisplay ?? '—') ?></td>
                        <td><?= htmlspecialchars($p->waiterName) ?></td>
                        <td class="nowrap"><?= $p->tableId ?: '—' ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
