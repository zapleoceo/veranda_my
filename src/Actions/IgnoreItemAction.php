<?php

declare(strict_types=1);

namespace App\Actions;

class IgnoreItemAction implements ActionInterface
{
    public function handle(ActionContext $ctx): string
    {
        $ks = $ctx->db->t('kitchen_stats');
        $ai = $ctx->db->t('tg_alert_items');

        $ctx->db->query(
            "UPDATE {$ks} SET exclude_from_dashboard = 1, exclude_auto = 0 WHERE id = ?",
            [$ctx->actionId]
        );

        IgnoreLog::record($ctx->db, 'item', $ctx->actionId, $ctx->username);

        // Drop the row from tg_alert_items so the next telegram_alerts tick
        // doesn't try to re-delete a message we already removed here. Without
        // this every Ignore press generated a "message to delete not found"
        // warning ~1.5k/day in app.log (IgnoreTxAction already does the
        // equivalent batch DELETE).
        try {
            $ctx->db->query(
                "DELETE FROM {$ai} WHERE kitchen_stats_id = ?",
                [$ctx->actionId]
            );
        } catch (\Throwable) {}

        $deleted = $ctx->bot->deleteMessage($ctx->messageId);
        if ($deleted) {
            return 'Игнор блюда установлен. Сообщение удалено.';
        }

        $ctx->bot->editMessageReplyMarkup($ctx->messageId, []);
        return 'Игнор блюда установлен. Кнопки убраны.';
    }
}
