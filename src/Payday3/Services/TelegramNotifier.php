<?php

declare(strict_types=1);

namespace App\Payday3\Services;

use App\Infrastructure\Config;
use App\Payday3\Contracts\TelegramNotifierInterface;

/**
 * Thin Telegram bot client — just sendMessage. Used by the
 * check-remove notification today; the screenshot/photo flow
 * (Phase 9) can reuse this for caption + share the cURL boilerplate.
 *
 * Reads .env: TELEGRAM_BOT_TOKEN (or legacy TG_BOT_TOKEN). Defaults
 * for chat/thread are baked in to match payday2's behaviour but every
 * caller can override.
 */
final class TelegramNotifier implements TelegramNotifierInterface
{
    private const DEFAULT_CHAT_ID  = '-1003889942420';
    private const DEFAULT_THREAD   = '1950';

    public function sendText(string $text, ?string $chatId = null, ?string $threadId = null): array
    {
        $token = trim((string)($_ENV['TELEGRAM_BOT_TOKEN']
            ?? $_ENV['TG_BOT_TOKEN']
            ?? Config::get('TELEGRAM_BOT_TOKEN')));
        if ($token === '') return ['ok' => false, 'error' => 'TELEGRAM_BOT_TOKEN missing'];

        $chat   = $chatId   ?? self::DEFAULT_CHAT_ID;
        $thread = $threadId ?? self::DEFAULT_THREAD;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://api.telegram.org/bot{$token}/sendMessage");
        curl_setopt($ch, CURLOPT_POST, true);
        $fields = ['chat_id' => $chat, 'text' => $text];
        if ($thread !== null && $thread !== '') $fields['message_thread_id'] = $thread;
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
}
