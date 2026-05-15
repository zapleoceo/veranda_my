<?php

declare(strict_types=1);

namespace App\Actions;

use App\Classes\PosterReservationHelper;
use App\Classes\PosterSpotHallsService;
use App\Classes\ReservationTelegram;
use App\Infrastructure\Config;

class VposterFixAction implements ActionInterface
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
            $ctx->bot->answerCallbackQuery($ctx->callbackQueryId, 'Бронь уже отправляется в Poster');
            return '';
        }
        if ($pushedState === 1) {
            $ctx->bot->answerCallbackQuery($ctx->callbackQueryId, 'Бронь уже отправлена в Poster');
            return '';
        }

        $row    = $this->_withHallName($row, $ctx);
        $spotTz = new \DateTimeZone(Config::get('POSTER_SPOT_TIMEZONE', 'Asia/Ho_Chi_Minh'));

        $startRaw = (string) ($row['start_time'] ?? '');
        $startDt  = $this->_parseDateTime($startRaw, $spotTz) ?? new \DateTimeImmutable('now', $spotTz);
        $duration = max(1, (int) ($row['duration'] ?? 0) ?: 120);
        $oldEnd   = $startDt->modify("+{$duration} minutes");

        $now      = new \DateTimeImmutable('now', $spotTz);
        $newStart = $now->setTime((int) $now->format('H'), (int) $now->format('i'), 0);

        if ($oldEnd->getTimestamp() <= $newStart->getTimestamp()) {
            $ctx->bot->answerCallbackQuery($ctx->callbackQueryId, 'Бронь уже полностью прошла');
            $baseText = ReservationTelegram::buildManagerText($row);
            $ctx->bot->editMessageText(
                $ctx->messageId,
                trim($baseText) . "\n\n⏰ <b>Время брони уже прошло полностью.</b>",
                [ReservationTelegram::keyboardStale($ctx->actionId)]
            );
            return '';
        }

        $newDuration = max(1, (int) ceil(($oldEnd->getTimestamp() - $newStart->getTimestamp()) / 60));

        $ctx->db->query(
            "UPDATE {$res} SET start_time = ?, duration = ? WHERE id = ? LIMIT 1",
            [$newStart->format('Y-m-d H:i:s'), $newDuration, $ctx->actionId]
        );

        $ctx->bot->answerCallbackQuery($ctx->callbackQueryId, 'Обновляю и отправляю…');

        $result = PosterReservationHelper::pushToPoster(
            $ctx->db, Config::get('POSTER_API_TOKEN'), $ctx->actionId,
            (string) Config::int('POSTER_SPOT_ID', 1), $ctx->actorName
        );

        $row2 = $ctx->db->query("SELECT * FROM {$res} WHERE id = ? LIMIT 1", [$ctx->actionId])->fetch();
        $row2 = is_array($row2) ? $row2 : [];
        if (!empty($row['hall_name'])) {
            $row2['hall_name'] = $row['hall_name'];
        }

        $baseText = trim(ReservationTelegram::buildManagerText($row2));
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
            : "\n\n⏱ <b>Время старта обновлено</b>: " . htmlspecialchars($newStart->format('H:i'))
              . "\n🚀 <b>Отправлено в Poster</b> (" . htmlspecialchars($ctx->actorName) . ")";

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
