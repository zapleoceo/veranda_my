<?php

declare(strict_types=1);

namespace App\Actions;

use App\Classes\PosterSpotHallsService;
use App\Classes\ReservationTelegram;
use App\Infrastructure\Config;

class VdeclineAction implements ActionInterface
{
    public function handle(ActionContext $ctx): string
    {
        $res   = $ctx->db->t('reservations');
        $row   = $ctx->db->query("SELECT * FROM {$res} WHERE id = ? LIMIT 1", [$ctx->actionId])->fetch();

        if (!$row) {
            $ctx->bot->answerCallbackQuery($ctx->callbackQueryId, 'Бронь не найдена в БД');
            return '';
        }
        if (!empty($row['deleted_at'])) {
            $ctx->bot->answerCallbackQuery($ctx->callbackQueryId, 'Бронь уже отказана');
            return '';
        }

        $who = $ctx->username !== '' ? ('@' . $ctx->username) : $ctx->actorName;
        $ctx->db->query(
            "UPDATE {$res} SET deleted_at = NOW(), deleted_by = ? WHERE id = ? LIMIT 1",
            [$who, $ctx->actionId]
        );

        $row    = $this->_withHallName($row, $ctx);
        $text   = trim(ReservationTelegram::buildManagerText($row))
            . "\n\n❌ <b>Бронь отказана</b> менеджером " . htmlspecialchars($who)
            . ' · ' . htmlspecialchars(date('Y-m-d H:i'));

        $ctx->bot->answerCallbackQuery($ctx->callbackQueryId, 'Отказано');
        $ctx->bot->editMessageText(
            $ctx->messageId, $text,
            [ReservationTelegram::keyboardDeclined($ctx->actionId)]
        );

        return '';
    }

    private function _withHallName(array $row, ActionContext $ctx): array
    {
        $spotId  = (int) ($row['spot_id'] ?? 0) ?: Config::int('POSTER_SPOT_ID', 1);
        $hallId  = (int) ($row['hall_id'] ?? 0);
        if ($hallId > 0) {
            $name = PosterSpotHallsService::getHallName(
                $ctx->db, Config::get('POSTER_API_TOKEN'), $spotId, $hallId
            );
            $row['hall_name'] = $name !== '' ? $name : "hall_id={$hallId}";
        }
        return $row;
    }
}
