<?php

declare(strict_types=1);

namespace App\Payday3\Services;

use App\Infrastructure\Config;
use App\Payday3\Contracts\LocalSettingsRepositoryInterface;
use App\Payday3\Contracts\TelegramNotifierInterface;

/**
 * Thin Telegram bot client — just sendMessage.
 *
 * Defaults for chat/thread come from the LocalSettings repository
 * (operator-tuned in payday3/local_config.json). Callers can override
 * per-call to send to a different room.
 *
 * Reads .env: TELEGRAM_BOT_TOKEN (or legacy TG_BOT_TOKEN).
 */
final class TelegramNotifier implements TelegramNotifierInterface
{
    public function __construct(private readonly LocalSettingsRepositoryInterface $settings) {}

    public function sendText(string $text, ?string $chatId = null, ?string $threadId = null): array
    {
        $token = trim((string)($_ENV['TELEGRAM_BOT_TOKEN']
            ?? $_ENV['TG_BOT_TOKEN']
            ?? Config::get('TELEGRAM_BOT_TOKEN')));
        if ($token === '') return ['ok' => false, 'error' => 'TELEGRAM_BOT_TOKEN missing'];

        if ($chatId === null || $threadId === null) {
            $cfg = $this->settings->load();
            $chatId   = $chatId   ?? $cfg->telegramChatId;
            $threadId = $threadId ?? $cfg->telegramThreadId;
        }
        if ($chatId === '') return ['ok' => false, 'error' => 'telegram_chat_id missing'];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://api.telegram.org/bot{$token}/sendMessage");
        curl_setopt($ch, CURLOPT_POST, true);
        $fields = ['chat_id' => $chatId, 'text' => $text];
        if ($threadId !== null && $threadId !== '') $fields['message_thread_id'] = $threadId;
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $resp = curl_exec($ch);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($resp === false) return ['ok' => false, 'error' => $err ?: 'Telegram request failed'];
        $j = json_decode((string)$resp, true);
        if (is_array($j) && ($j['ok'] ?? false)) return ['ok' => true];
        $msg = is_array($j) ? (string)($j['description'] ?? 'Telegram error') : 'Telegram invalid response';
        return ['ok' => false, 'error' => $msg];
    }

    public function sendPhoto(string $bytes, string $mime = 'image/png', string $caption = '', ?string $chatId = null, ?string $threadId = null): array
    {
        $token = trim((string)($_ENV['TELEGRAM_BOT_TOKEN']
            ?? $_ENV['TG_BOT_TOKEN']
            ?? Config::get('TELEGRAM_BOT_TOKEN')));
        if ($token === '') return ['ok' => false, 'error' => 'TELEGRAM_BOT_TOKEN missing'];
        if ($bytes === '') return ['ok' => false, 'error' => 'empty image'];

        if ($chatId === null || $threadId === null) {
            $cfg = $this->settings->load();
            $chatId   = $chatId   ?? $cfg->telegramChatId;
            $threadId = $threadId ?? $cfg->telegramThreadId;
        }
        if ($chatId === '') return ['ok' => false, 'error' => 'telegram_chat_id missing'];

        // sendPhoto needs multipart/form-data — we materialise the
        // image to a tmp file so CURLFile can stream it. Cleaned up
        // unconditionally in the finally branch.
        $ext = $mime === 'image/jpeg' ? 'jpg' : 'png';
        $tmp = tempnam(sys_get_temp_dir(), 'pd3_tg_');
        if ($tmp === false) return ['ok' => false, 'error' => 'tempnam failed'];
        try {
            $tmpPath = $tmp . '.' . $ext;
            @rename($tmp, $tmpPath);
            if (file_put_contents($tmpPath, $bytes) === false) {
                return ['ok' => false, 'error' => 'tmp write failed'];
            }
            $fields = [
                'chat_id' => $chatId,
                'photo'   => new \CURLFile($tmpPath, $mime, 'balance.' . $ext),
            ];
            if ($caption !== '')                              $fields['caption']             = $caption;
            if ($threadId !== null && $threadId !== '')       $fields['message_thread_id']   = $threadId;

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL,            "https://api.telegram.org/bot{$token}/sendPhoto");
            curl_setopt($ch, CURLOPT_POST,           true);
            curl_setopt($ch, CURLOPT_POSTFIELDS,     $fields);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT,        30);
            $resp = curl_exec($ch);
            $err  = curl_error($ch);
            curl_close($ch);

            if ($resp === false) return ['ok' => false, 'error' => $err ?: 'Telegram request failed'];
            $j = json_decode((string)$resp, true);
            if (is_array($j) && ($j['ok'] ?? false)) return ['ok' => true];
            return ['ok' => false, 'error' => is_array($j)
                ? (string)($j['description'] ?? 'Telegram error')
                : 'Telegram invalid response'];
        } finally {
            @unlink($tmpPath ?? $tmp);
        }
    }
}
