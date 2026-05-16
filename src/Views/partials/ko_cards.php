<?php
/**
 * Kitchen Online card list.
 * Expects: $cards (array), $waitLimit (int), $tgConfig (array), $canExclude (bool)
 */

$nowTs = time();
foreach ($cards as $c):
    if (empty($c['items'])) continue;
    $receipt = trim((string)$c['receipt_number']);
    $label   = $receipt !== '' ? $receipt : ('TX #' . (int)$c['transaction_id']);
    $table   = trim((string)$c['table_number']);
    $waiter  = trim((string)$c['waiter_name']) ?: '—';
?>
<div class="ko-card" data-tx-id="<?= (int)$c['transaction_id'] ?>">
    <div class="ko-card-header">
        <div class="ko-card-top">
            <div class="ko-title"># <?= htmlspecialchars($label) ?></div>
            <div class="ko-table">🍽️ <?= htmlspecialchars($table ?: '—') ?></div>
        </div>
        <div class="ko-meta">
            <span>Офик: <?= htmlspecialchars($waiter) ?></span>
            <?php if ($c['comment'] !== ''): ?>
                <span class="ko-comment"><?= htmlspecialchars($c['comment']) ?></span>
            <?php endif; ?>
        </div>
    </div>
    <div class="ko-items">
        <?php foreach ($c['items'] as $it):
            $sentTs    = (int)($it['sent_ts'] ?? 0);
            $sentLabel = $sentTs > 0 ? date('H:i:s', $sentTs) : '—';
            $overdue   = $waitLimit > 0 && $sentTs > 0 && ($nowTs - $sentTs) >= ($waitLimit * 60);
            $tgSent    = trim((string)($it['tg_sent_at'] ?? ''));
            $tgMsgId   = (int)($it['tg_message_id'] ?? 0);
            $tgClass   = $tgSent !== '' ? ' sent' : '';
            $tgHref    = '';
            if ($tgSent !== '' && $tgMsgId > 0) {
                $th  = (int)($tgConfig['thread'] ?? 0);
                $un  = (string)($tgConfig['username'] ?? '');
                $int = (string)($tgConfig['internal'] ?? '');
                if ($un !== '') {
                    $tgHref = $th > 0 ? "https://t.me/{$un}/{$tgMsgId}?thread={$th}" : "https://t.me/{$un}/{$tgMsgId}";
                } elseif ($int !== '') {
                    $tgHref = $th > 0 ? "https://t.me/c/{$int}/{$tgMsgId}?thread={$th}" : "https://t.me/c/{$int}/{$tgMsgId}";
                }
            }
        ?>
        <div class="ko-item<?= $overdue ? ' ko-item-overdue' : '' ?>"
             data-sent-ts="<?= $sentTs ?>"
             <?= $it['item_id'] > 0 ? 'data-item-id="' . (int)$it['item_id'] . '"' : '' ?>>
            <div class="ko-item-name"><?= htmlspecialchars((string)($it['dish_name'] ?: '')) ?></div>
            <div class="ko-item-row">
                <span class="ko-item-sent">Старт: <?= htmlspecialchars($sentLabel) ?></span>
                <?php if ($sentTs > 0): ?>
                    <span class="ko-item-wait live-wait" data-sent-ts="<?= $sentTs ?>"><span class="live-time">00:00</span></span>
                <?php else: ?>
                    <span class="ko-item-wait">—</span>
                <?php endif; ?>
                <span class="ko-tg<?= $tgClass ?>">
                    <?php if ($tgHref !== ''): ?>
                        <a href="<?= htmlspecialchars($tgHref) ?>" target="_blank" rel="noopener noreferrer">TG</a>
                    <?php else: ?>TG<?php endif; ?>
                </span>
                <?php if ($it['item_id'] > 0 && $canExclude): ?>
                    <button type="button" class="ko-ack" data-item-id="<?= (int)$it['item_id'] ?>" title="Игнор" aria-label="Игнор">✕</button>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endforeach; ?>
