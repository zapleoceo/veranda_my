<?php
/** @var \App\Payday3\Domain\SepayTransaction[] $sepayOpen */
/** @var \App\Payday3\Domain\SepayTransaction[] $sepayHidden */
declare(strict_types=1);
?>
<div class="pd3-pane pd3-pane--sepay">
    <div class="pd3-pane__header">
        <h3>Sepay</h3>
        <span class="muted"><?= count($sepayOpen) ?> открытых / <?= count($sepayHidden) ?> скрытых</span>
    </div>
    <div class="pd3-pane__scroll" id="pd3SepayScroll">
        <table class="pd3-table pd3-table--sepay" id="pd3SepayTable">
            <thead>
                <tr>
                    <th class="nowrap">Дата</th>
                    <th class="right">Сумма</th>
                    <th>Метод</th>
                    <th>Контент</th>
                    <th>Ref</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($sepayOpen as $s): ?>
                    <tr id="pd3-sepay-<?= $s->id ?>" data-sepay-id="<?= $s->id ?>">
                        <td class="nowrap"><?= htmlspecialchars($s->transactionDate) ?></td>
                        <td class="right nowrap"><?= htmlspecialchars($s->amount->format()) ?></td>
                        <td><?= htmlspecialchars($s->paymentMethod) ?></td>
                        <td class="pd3-table__content"><?= htmlspecialchars($s->content) ?></td>
                        <td class="nowrap"><?= htmlspecialchars($s->referenceCode) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($sepayHidden !== []): ?>
                    <tr class="pd3-table__separator"><td colspan="5">Скрытые</td></tr>
                    <?php foreach ($sepayHidden as $s): ?>
                        <tr id="pd3-sepay-<?= $s->id ?>" class="pd3-row--hidden" data-sepay-id="<?= $s->id ?>">
                            <td class="nowrap"><?= htmlspecialchars($s->transactionDate) ?></td>
                            <td class="right nowrap"><?= htmlspecialchars($s->amount->format()) ?></td>
                            <td><?= htmlspecialchars($s->paymentMethod) ?></td>
                            <td class="pd3-table__content">
                                <?= htmlspecialchars($s->content) ?>
                                <?php if ($s->hiddenComment !== null && $s->hiddenComment !== ''): ?>
                                    <span class="pd3-table__hidden-comment">— <?= htmlspecialchars($s->hiddenComment) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="nowrap"><?= htmlspecialchars($s->referenceCode) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
