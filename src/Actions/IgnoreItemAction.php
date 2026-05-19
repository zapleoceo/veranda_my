<?php

declare(strict_types=1);

namespace App\Actions;

class IgnoreItemAction implements ActionInterface
{
    public function handle(ActionContext $ctx): string
    {
        $ks = $ctx->db->t('kitchen_stats');
        $ctx->db->query(
            "UPDATE {$ks} SET exclude_from_dashboard = 1, exclude_auto = 0 WHERE id = ?",
            [$ctx->actionId]
        );

        IgnoreLog::record($ctx->db, 'item', $ctx->actionId, $ctx->username);

        $deleted = $ctx->bot->deleteMessage($ctx->messageId);
        if ($deleted) {
            return 'Игнор блюда установлен. Сообщение удалено.';
        }

        $ctx->bot->editMessageReplyMarkup($ctx->messageId, []);
        return 'Игнор блюда установлен. Кнопки убраны.';
    }
}
