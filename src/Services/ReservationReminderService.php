<?php

declare(strict_types=1);

namespace App\Services;

use App\Infrastructure\Database;
use App\Infrastructure\HttpClient;
use App\Infrastructure\Logger;
use App\Infrastructure\TelegramBotClient;
use App\Infrastructure\Config;

/**
 * Sends "complete your booking" reminder messages 10 minutes after the auth link
 * was sent but the user hasn't finished the reservation flow.
 * Handles both Telegram (DM to user) and WhatsApp channels.
 */
class ReservationReminderService
{
    private const REMINDER_DELAY_SECONDS = 600;
    private const BATCH_LIMIT            = 50;

    public function __construct(
        private readonly Database          $db,
        private readonly TelegramBotClient $bot,
        private readonly HttpClient        $http,
    ) {}

    public function run(): void
    {
        $now    = date('Y-m-d H:i:s');
        $cutoff = date('Y-m-d H:i:s', time() - self::REMINDER_DELAY_SECONDS);

        $tgCount = $this->_processTelegramReminders($now, $cutoff);
        $waCount = $this->_processWhatsAppReminders($now, $cutoff);

        if ($tgCount > 0 || $waCount > 0) {
            Logger::get()->info('reservation_reminders.done', ['tg' => $tgCount, 'wa' => $waCount]);
        }
    }

    private function _processTelegramReminders(string $now, string $cutoff): int
    {
        $table = $this->db->t('table_reservation_tg_states');
        $siteBase = rtrim(Config::baseUrl(), '/');

        try {
            $rows = $this->db->query(
                "SELECT code, payload_json, tg_user_id
                 FROM {$table}
                 WHERE used_at IS NULL
                   AND expires_at > ?
                   AND return_sent_at IS NOT NULL
                   AND reminder_sent_at IS NULL
                   AND tg_user_id IS NOT NULL AND tg_user_id > 0
                   AND return_sent_at <= ?
                 LIMIT " . self::BATCH_LIMIT,
                [$now, $cutoff]
            )->fetchAll();
        } catch (\Throwable) {
            return 0;
        }

        $count = 0;
        foreach ($rows as $row) {
            $code     = (string) ($row['code'] ?? '');
            $userId   = (int) ($row['tg_user_id'] ?? 0);
            if ($code === '' || $userId <= 0) {
                continue;
            }

            $payload  = json_decode((string) ($row['payload_json'] ?? '{}'), true) ?? [];
            $url      = $siteBase . '/' . ltrim((string) ($payload['source_page'] ?? 'tr3/'), '/')
                      . '?tg_state=' . rawurlencode($code);

            $text = $this->_buildTgReminderText($payload, $url);

            // Send DM directly to user (not to the group chat)
            $userBot = new TelegramBotClient(
                Config::require('TELEGRAM_BOT_TOKEN'),
                $this->http,
                (string) $userId
            );
            $mid = $userBot->sendMessageWithKeyboard(
                $text,
                [[['text' => 'Завершить бронирование', 'url' => $url]]]
            );

            try {
                $this->db->query(
                    "UPDATE {$table}
                     SET reminder_sent_at = ?, reminder_msg_id = NULLIF(?, 0)
                     WHERE code = ?",
                    [date('Y-m-d H:i:s'), (int) ($mid ?? 0), $code]
                );
            } catch (\Throwable) {}
            $count++;
        }

        return $count;
    }

    private function _processWhatsAppReminders(string $now, string $cutoff): int
    {
        $table    = $this->db->t('table_reservation_wa_states');
        $siteBase = rtrim(Config::baseUrl(), '/');

        try {
            $rows = $this->db->query(
                "SELECT code, phone, payload_json
                 FROM {$table}
                 WHERE used_at IS NULL
                   AND expires_at > ?
                   AND return_sent_at IS NOT NULL
                   AND reminder_sent_at IS NULL
                   AND return_sent_at <= ?
                 LIMIT " . self::BATCH_LIMIT,
                [$now, $cutoff]
            )->fetchAll();
        } catch (\Throwable) {
            return 0;
        }

        $count = 0;
        foreach ($rows as $row) {
            $code  = (string) ($row['code']  ?? '');
            $phone = trim((string) ($row['phone'] ?? ''));
            if ($code === '' || $phone === '') {
                continue;
            }

            $payload = json_decode((string) ($row['payload_json'] ?? '{}'), true) ?? [];
            $url     = $siteBase . '/' . ltrim((string) ($payload['source_page'] ?? 'tr3/'), '/')
                     . '?wa_state=' . rawurlencode($code);

            $text = $this->_buildWaReminderText($payload, $url);
            $this->_sendWhatsApp($phone, $text);

            try {
                $this->db->query(
                    "UPDATE {$table} SET reminder_sent_at = ? WHERE code = ?",
                    [date('Y-m-d H:i:s'), $code]
                );
            } catch (\Throwable) {}
            $count++;
        }

        return $count;
    }

    private function _buildTgReminderText(array $payload, string $url): string
    {
        $details = $this->_reservationDetails($payload);
        return "Напоминание.\n\nАккаунт подтвержден.\nНажми кнопку ниже, чтобы завершить бронирование:"
            . ($details !== '' ? "\n\n{$details}" : '');
    }

    private function _buildWaReminderText(array $payload, string $url): string
    {
        $lines   = ["Напоминание.", "", "Чтобы завершить бронирование, перейдите по ссылке:", $url];
        $details = $this->_reservationDetails($payload);
        if ($details !== '') {
            $lines[] = '';
            $lines[] = $details;
        }
        return implode("\n", $lines);
    }

    private function _reservationDetails(array $payload): string
    {
        $parts = [];
        $table  = trim((string) ($payload['table_label'] ?? $payload['table_num'] ?? ''));
        $start  = trim((string) ($payload['start']  ?? ''));
        $guests = (int) ($payload['guests'] ?? 0);

        if ($table  !== '') $parts[] = 'Стол: ' . htmlspecialchars($table);
        if ($start  !== '') $parts[] = 'Время: ' . htmlspecialchars($start);
        if ($guests  > 0)   $parts[] = 'Гостей: ' . $guests;

        return implode("\n", $parts);
    }

    private function _sendWhatsApp(string $phone, string $text): bool
    {
        $host   = Config::get('WA_HTTP_HOST', '127.0.0.1');
        $port   = Config::int('WA_HTTP_PORT', 3210);
        $secret = Config::get('WA_NODE_SECRET') ?: Config::get('WA_BRIDGE_SECRET');

        if ($secret === '') {
            return false;
        }

        $result = $this->http->postJsonBodyWithHeaders(
            "http://{$host}:{$port}/send",
            ['phone' => $phone, 'text' => $text],
            ['X-WA-BRIDGE: ' . $secret]
        );

        return $result !== null;
    }
}
