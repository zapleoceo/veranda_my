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
        $token = self::token();
        if ($token === '') return ['ok' => false, 'error' => 'TELEGRAM_BOT_TOKEN missing'];

        [$chatId, $threadId] = $this->resolveTarget($chatId, $threadId);
        if ($chatId === '') return ['ok' => false, 'error' => 'telegram_chat_id missing'];

        // sendMessage is URL-encoded (no file payload); use the
        // multipart helper anyway — curl handles either content type
        // transparently, and we get the same error-formatting logic
        // for free (DRY).
        return $this->sendMultipart('sendMessage', $token, [
            'chat_id'             => $chatId,
            'text'                => $text,
            'message_thread_id'   => $threadId,
        ]);
    }

    public function sendPhoto(string $bytes, string $mime = 'image/png', string $caption = '', ?string $chatId = null, ?string $threadId = null): array
    {
        if ($bytes === '') return ['ok' => false, 'error' => 'empty image'];

        $token = self::token();
        if ($token === '') return ['ok' => false, 'error' => 'TELEGRAM_BOT_TOKEN missing'];

        [$chatId, $threadId] = $this->resolveTarget($chatId, $threadId);
        if ($chatId === '') return ['ok' => false, 'error' => 'telegram_chat_id missing'];

        $ext = $mime === 'image/jpeg' ? 'jpg' : 'png';
        $tmpPath = self::materialise($bytes, $ext);
        if ($tmpPath === null) return ['ok' => false, 'error' => 'tmp write failed'];
        try {
            // First try sendPhoto. Telegram rejects photos with too
            // extreme aspect ratios / above 10MB / dimensions over
            // 10000 px — a tall screenshot of the balance card hits
            // those limits occasionally. We catch those classes of
            // failure and retry as sendDocument, which has no such
            // restrictions.
            $photo = $this->sendMultipart('sendPhoto', $token, [
                'chat_id'             => $chatId,
                'photo'               => new \CURLFile($tmpPath, $mime, 'balance.' . $ext),
                'caption'             => $caption,
                'message_thread_id'   => $threadId,
            ]);
            if ($photo['ok']) return $photo;
            if (self::isPhotoFormatError($photo['error'] ?? '')) {
                $doc = $this->sendMultipart('sendDocument', $token, [
                    'chat_id'             => $chatId,
                    'document'            => new \CURLFile($tmpPath, $mime, 'balance.' . $ext),
                    'caption'             => $caption,
                    'message_thread_id'   => $threadId,
                ]);
                if ($doc['ok']) return ['ok' => true, 'fallback' => 'document'];
                return ['ok' => false, 'error' => 'photo: ' . ($photo['error'] ?? '?') . ' / document: ' . ($doc['error'] ?? '?')];
            }
            return $photo;
        } finally {
            @unlink($tmpPath);
        }
    }

    // ─── internals ──────────────────────────────────────────────

    private static function token(): string
    {
        return trim((string)(
            $_ENV['TELEGRAM_BOT_TOKEN']
            ?? $_ENV['TG_BOT_TOKEN']
            ?? Config::get('TELEGRAM_BOT_TOKEN')
        ));
    }

    /** @return array{0:string,1:?string}  resolved chat / thread, settings as fallback */
    private function resolveTarget(?string $chatId, ?string $threadId): array
    {
        if ($chatId === null || $threadId === null) {
            $cfg = $this->settings->load();
            $chatId   = $chatId   ?? $cfg->telegramChatId;
            $threadId = $threadId ?? $cfg->telegramThreadId;
        }
        return [$chatId, $threadId];
    }

    /** Materialise raw bytes into a tmp file with the right extension; returns the path or null. */
    private static function materialise(string $bytes, string $ext): ?string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'pd3_tg_');
        if ($tmp === false) return null;
        $tmpPath = $tmp . '.' . $ext;
        @rename($tmp, $tmpPath);
        if (@file_put_contents($tmpPath, $bytes) === false) {
            @unlink($tmpPath);
            @unlink($tmp);
            return null;
        }
        return $tmpPath;
    }

    /**
     * POST multipart/form-data to a Telegram Bot API method.
     * Drops empty fields so we don't accidentally send
     * `message_thread_id=` (which Telegram interprets as 0).
     *
     * @return array{ok:bool, error?:string, response?:array}
     */
    private function sendMultipart(string $method, string $token, array $fields): array
    {
        foreach ($fields as $k => $v) {
            if ($v === null || $v === '') unset($fields[$k]);
        }
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL,            "https://api.telegram.org/bot{$token}/{$method}");
        curl_setopt($ch, CURLOPT_POST,           true);
        curl_setopt($ch, CURLOPT_POSTFIELDS,     $fields);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT,        30);
        $resp = curl_exec($ch);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($resp === false) return ['ok' => false, 'error' => $err ?: $method . ' request failed'];
        $j = json_decode((string)$resp, true);
        if (is_array($j) && ($j['ok'] ?? false)) return ['ok' => true, 'response' => $j];

        $code = is_array($j) ? (int)($j['error_code']  ?? 0)  : 0;
        $desc = is_array($j) ? trim((string)($j['description'] ?? '')) : '';
        if ($desc === '') {
            $rawTrimmed = trim((string)$resp);
            $desc = $rawTrimmed !== ''
                ? 'Telegram: ' . mb_substr($rawTrimmed, 0, 200)
                : $method . ' error';
        }
        $err = $code > 0 ? ($code . ': ' . $desc) : $desc;
        return ['ok' => false, 'error' => $err];
    }

    /**
     * Heuristic: is this Telegram error about the image format /
     * size / dimensions (i.e. retrying as sendDocument might
     * succeed), or about chat permissions (where retry is pointless)?
     */
    private static function isPhotoFormatError(string $err): bool
    {
        $e = mb_strtolower($err, 'UTF-8');
        return str_contains($e, 'photo')
            || str_contains($e, 'dimension')
            || str_contains($e, 'too big')
            || str_contains($e, 'too large')
            || str_contains($e, 'aspect')
            || str_contains($e, 'wrong file');
    }
}
