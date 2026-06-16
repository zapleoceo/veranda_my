<?php

declare(strict_types=1);

namespace App\Actions;

use App\Classes\PosterSpotHallsService;
use App\Classes\ReservationTelegram;
use App\Infrastructure\Config;

class VposterCancelAction implements ActionInterface
{
    public function handle(ActionContext $ctx): string
    {
        $res = $ctx->db->t('reservations');
        $row = $ctx->db->query("SELECT * FROM {$res} WHERE id = ? LIMIT 1", [$ctx->actionId])->fetch();

        if (!$row) {
            $ctx->bot->answerCallbackQuery($ctx->callbackQueryId, 'Бронь не найдена в БД');
            return '';
        }
        if (ReservationTelegram::isSoftDeleted($row)) {
            $ctx->bot->answerCallbackQuery($ctx->callbackQueryId, 'Бронь отказана. Сначала восстановите.');
            return '';
        }

        $row      = $this->_withHallName($row, $ctx);
        $baseText = trim(ReservationTelegram::buildManagerText($row));

        $ctx->bot->answerCallbackQuery($ctx->callbackQueryId, 'Ок');
        $ctx->bot->editMessageText(
            $ctx->messageId,
            $baseText,
            [ReservationTelegram::keyboardActive($ctx->actionId)]
        );

        return '';
    }

    private function _withHallName(array $row, ActionContext $ctx): array
    {
        $spotId = (int) ($row['spot_id'] ?? 0) ?: Config::int('POSTER_SPOT_ID', 1);
        $hallId = (int) ($row['hall_id'] ?? 0);
        if ($hallId > 0) {
            $name = PosterSpotHallsService::getHallName(
                $ctx->db, Config::get('POSTER_API_TOKEN'), $spotId, $hallId
            );
            $row['hall_name'] = $name !== '' ? $name : "hall_id={$hallId}";
        }
        return $row;
    }
}
