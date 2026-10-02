<?php

declare(strict_types=1);

namespace App\Actions;

use App\Afisha\AfishaStore;

/**
 * Кнопка «Откатить» под уведомлением об обновлении афиши.
 * actionId — понедельник недели в виде YYYYMMDD (callback_data: afisha_undo:20260928).
 */
final class AfishaUndoAction implements ActionInterface
{
    public function handle(ActionContext $ctx): string
    {
        $raw = str_pad((string) $ctx->actionId, 8, '0', STR_PAD_LEFT);
        $weekStart = substr($raw, 0, 4) . '-' . substr($raw, 4, 2) . '-' . substr($raw, 6, 2);

        if (!(new AfishaStore())->undo($weekStart)) {
            return 'Откатывать нечего — это изменение уже отменено или перекрыто новым.';
        }

        // Убираем кнопку, чтобы второй клик не выглядел рабочим.
        $ctx->bot->editMessageReplyMarkup($ctx->messageId, null);

        return 'Готово: афиша недели возвращена к предыдущей версии.';
    }
}
