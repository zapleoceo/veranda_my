<?php
/** @var array $receipt */
$_isClosed  = ($receipt['status'] ?? 1) != 1;
$_openedTs  = !empty($receipt['opened_at'])  ? strtotime($receipt['opened_at'])  : 0;
$_closedTs  = !empty($receipt['closed_at'])  ? strtotime($receipt['closed_at'])  : 0;
$_maxWait   = (int)($receipt['max_wait'] ?? 0);
$_waitClass = $_maxWait <= 0 ? 'wait-fallback'
    : ($_maxWait < 600 ? 'wait-low' : ($_maxWait < 1200 ? 'wait-medium' : 'wait-high'));
$_waitLabel = $_maxWait > 0
    ? sprintf('%d:%02d', intdiv($_maxWait, 60), $_maxWait % 60)
    : '—';
?>
<details class="receipt-item<?= $_isClosed ? ' receipt-closed' : '' ?>"
         data-receipt="<?= htmlspecialchars($receipt['receipt_number']) ?>"
         data-opened="<?= $_openedTs ?>"
         data-closed="<?= $_closedTs ?>"
         data-wait="<?= $_maxWait ?>">
    <summary>
        <div class="receipt-info">
            <div class="receipt-number">#<?= htmlspecialchars($receipt['receipt_number']) ?></div>
            <div class="receipt-date"><?= htmlspecialchars($receipt['transaction_date']) ?></div>
            <div class="receipt-times">
                <?php if ($_openedTs): ?>
                    <span>Открыт: <?= date('H:i:s', $_openedTs) ?></span>
                <?php endif; ?>
                <?php if ($_closedTs): ?>
                    <span>Закрыт: <?= date('H:i:s', $_closedTs) ?></span>
                <?php endif; ?>
                <?php if ($receipt['table_number'] !== ''): ?>
                    <span>Стол: <?= htmlspecialchars($receipt['table_number']) ?></span>
                <?php endif; ?>
                <?php if ($receipt['waiter_name'] !== ''): ?>
                    <span><?= htmlspecialchars($receipt['waiter_name']) ?></span>
                <?php endif; ?>
            </div>
        </div>
        <div class="receipt-max-wait <?= $_waitClass ?>"><?= htmlspecialchars($_waitLabel) ?></div>
    </summary>
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Блюдо</th>
                    <th>Цех</th>
                    <th>Отправлено</th>
                    <th>Ожидание</th>
                    <th>Игнор</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (($receipt['items'] ?? []) as $_item):
                    $_sentTs   = !empty($_item['sent_at'])  ? strtotime($_item['sent_at'])  : 0;
                    $_isReady  = !empty($_item['ready_at']);
                    $_excluded = (int)($_item['excluded'] ?? 0);
                    $_waitSec  = (int)($_item['wait_seconds'] ?? 0);
                    $_waitFmt  = $_waitSec > 0 ? sprintf('%d:%02d', intdiv($_waitSec, 60), $_waitSec % 60) : '—';
                    if ($_excluded) {
                        $_statusClass = 'status-deleted';
                        $_statusLabel = $_waitFmt;
                    } elseif ($_isReady) {
                        $_statusClass = 'status-ready';
                        $_statusLabel = '✓ ' . $_waitFmt;
                    } else {
                        $_statusClass = 'status-cooking';
                        $_statusLabel = $_waitFmt;
                    }
                ?>
                    <tr>
                        <td><?= htmlspecialchars($_item['dish_name']) ?></td>
                        <td><?= htmlspecialchars($_item['station']) ?></td>
                        <td><?= $_sentTs ? date('H:i:s', $_sentTs) : '—' ?></td>
                        <td>
                            <?php if (!$_isReady && $_sentTs && !$_excluded): ?>
                                <span class="live-wait <?= $_statusClass ?>" data-sent-ts="<?= $_sentTs ?>">
                                    <span class="wait-spinner"></span>
                                    <span class="live-time"><?= htmlspecialchars($_waitFmt) ?></span>
                                </span>
                            <?php else: ?>
                                <span class="<?= $_statusClass ?>"><?= htmlspecialchars($_statusLabel) ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <form class="exclude-item-form">
                                <input type="hidden" name="toggle_exclude_item" value="<?= (int)$_item['id'] ?>">
                                <label class="exclude-toggle">
                                    <input type="checkbox" name="exclude_from_dashboard" value="1"<?= $_excluded ? ' checked' : '' ?>>
                                    Игнор
                                </label>
                                <span class="save-indicator"></span>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</details>
