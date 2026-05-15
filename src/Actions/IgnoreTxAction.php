<?php

declare(strict_types=1);

namespace App\Actions;

class IgnoreTxAction implements ActionInterface
{
    public function handle(ActionContext $ctx): string
    {
        $ks      = $ctx->db->t('kitchen_stats');
        $txItems = $ctx->db->t('tg_alert_items');
        $txId    = $ctx->actionId;

        $dateRow = $ctx->db->query(
            "SELECT transaction_date FROM {$ks} WHERE transaction_id = ? ORDER BY transaction_date DESC LIMIT 1",
            [$txId]
        )->fetch();
        $txDate = (string) ($dateRow['transaction_date'] ?? '');

        if ($txDate === '') {
            return 'Игнор чека установлен.';
        }

        $ctx->db->query(
            "UPDATE {$ks} SET exclude_from_dashboard = 1, exclude_auto = 0
             WHERE transaction_date = ? AND transaction_id = ?",
            [$txDate, $txId]
        );

        // Collect all alert message IDs for this transaction
        $msgRows = $ctx->db->query(
            "SELECT DISTINCT message_id FROM {$txItems}
             WHERE transaction_date = ? AND transaction_id = ? AND message_id IS NOT NULL",
            [$txDate, $txId]
        )->fetchAll();

        $msgIds = array_unique(array_filter(
            array_merge(
                array_column($msgRows, 'message_id'),
                [$ctx->messageId > 0 ? $ctx->messageId : null]
            )
        ));

        $deleted = $cleared = 0;
        foreach ($msgIds as $mid) {
            if ((int) $mid <= 0) {
                continue;
            }
            if ($ctx->bot->deleteMessage((int) $mid)) {
                $deleted++;
            } else {
                $ctx->bot->editMessageReplyMarkup((int) $mid, []);
                $cleared++;
            }
        }

        try {
            $ctx->db->query(
                "DELETE FROM {$txItems} WHERE transaction_date = ? AND transaction_id = ?",
                [$txDate, $txId]
            );
        } catch (\Throwable) {}

        if ($deleted > 0) {
            return "Игнор чека установлен. Удалено сообщений: {$deleted}";
        }
        if ($cleared > 0) {
            return "Игнор чека установлен. Кнопки убраны: {$cleared}";
        }
        return 'Игнор чека установлен.';
    }
}
