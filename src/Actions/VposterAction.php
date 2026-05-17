<?php

declare(strict_types=1);

namespace App\Actions;

use App\Classes\PosterReservationHelper;
use App\Classes\PosterSpotHallsService;
use App\Classes\ReservationTelegram;
use App\Infrastructure\Config;

class VposterAction implements ActionInterface
{
    public function handle(ActionContext $ctx): string
    {
        $res = $ctx->db->t('reservations');
        $row = $ctx->db->query("SELECT * FROM {$res} WHERE id = ? LIMIT 1", [$ctx->actionId])->fetch();

        if (!$row) {
            $ctx->bot->answerCallbackQuery($ctx->callbackQueryId, 'Бронь не найдена в БД');
            return '';
        }
        if (!empty($row['deleted_at'])) {
            $ctx->bot->answerCallbackQuery($ctx->callbackQueryId, 'Бронь отказана. Сначала восстановите.');
            return '';
        }
        if (Config::get('POSTER_API_TOKEN') === '') {
            $ctx->bot->answerCallbackQuery($ctx->callbackQueryId, 'Poster API не настроен');
            return '';
        }

        $pushedState = (int) ($row['is_poster_pushed'] ?? 0);
        if ($pushedState === 2) {
            // Concurrent push in progress — keep the keyboard so the user can
            // retry after it finishes (or fails).
            $ctx->bot->answerCallbackQuery($ctx->callbackQueryId, 'Бронь уже отправляется в Poster');
            return '';
        }
        if ($pushedState === 1) {
            // Already pushed — collapse the message to its final state so the
            // active "Бронь в Постере" button stops dangling. Happens when
            // the previous press succeeded but the original editMessageText
            // didn't run (e.g. old code path, network blip, manual DB tweak).
            $ctx->bot->answerCallbackQuery($ctx->callbackQueryId, 'Бронь уже отправлена в Poster');
            $rowWithHall = $this->_withHallName($row, $ctx);
            $baseText    = trim(ReservationTelegram::buildManagerText($rowWithHall));
            $baseText    = preg_replace('/\n?\s*@\w+\s+@\w+\s+свяжитесь\s+с\s+гостем\s*\n?/u', "\n", $baseText);
            $ctx->bot->editMessageText(
                $ctx->messageId,
                $baseText . "\n\n🚀 <b>Уже в Poster</b> (отправлено ранее)",
                []
            );
            return '';
        }

        $row    = $this->_withHallName($row, $ctx);
        $spotTz = new \DateTimeZone(Config::get('POSTER_SPOT_TIMEZONE', 'Asia/Ho_Chi_Minh'));

        // Check if reservation hasn't started yet
        $startRaw = (string) ($row['start_time'] ?? '');
        $startDt  = $this->_parseDateTime($startRaw, $spotTz);
        if ($startDt && $startDt->getTimestamp() <= (new \DateTimeImmutable('now', $spotTz))->getTimestamp()) {
            $ctx->bot->answerCallbackQuery($ctx->callbackQueryId, 'Бронь устарела');
            $baseText = ReservationTelegram::buildManagerText($row);
            $ctx->bot->editMessageText(
                $ctx->messageId,
                trim($baseText) . "\n\n⏰ <b>Время начала брони уже прошло.</b>\nМожно обновить бронь так, чтобы она начиналась с текущего времени.",
                [ReservationTelegram::keyboardStale($ctx->actionId)]
            );
            return '';
        }

        $ctx->bot->answerCallbackQuery($ctx->callbackQueryId, 'Отправляю в Poster…');

        $result  = PosterReservationHelper::pushToPoster(
            $ctx->db, Config::get('POSTER_API_TOKEN'), $ctx->actionId,
            (string) Config::int('POSTER_SPOT_ID', 1), $ctx->actorName
        );

        $baseText = trim(ReservationTelegram::buildManagerText($row));
        $baseText = preg_replace('/\n?\s*@\w+\s+@\w+\s+свяжитесь\s+с\s+гостем\s*\n?/u', "\n", $baseText);

        if (!$result['ok']) {
            $ctx->bot->editMessageText(
                $ctx->messageId,
                $baseText . "\n\n❌ Poster: " . htmlspecialchars((string) $result['error']),
                [ReservationTelegram::keyboardActive($ctx->actionId)]
            );
            return '';
        }

        $suffix = !empty($result['duplicate'])
            ? "\n\n🚀 <b>Уже была в Poster</b> (дубль предотвращен)"
            : "\n\n🚀 <b>Отправлено в Poster</b> (" . htmlspecialchars($ctx->actorName) . ")";

        $ctx->bot->editMessageText($ctx->messageId, $baseText . $suffix, []);
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

    private function _parseDateTime(string $raw, \DateTimeZone $tz): \DateTimeImmutable|null
    {
        if ($raw === '') {
            return null;
        }
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $raw, $tz);
        if ($dt !== false) {
            return $dt;
        }
        try {
            return new \DateTimeImmutable($raw, $tz);
        } catch (\Throwable) {
            return null;
        }
    }
}
