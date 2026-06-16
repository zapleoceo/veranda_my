<?php

declare(strict_types=1);

namespace App\Infrastructure;

/**
 * Telegram Bot API client.
 * Replaces App\Classes\TelegramBot — all curl calls go through HttpClient.
 */
class TelegramBotClient
{
    private readonly string $chatId;

    public function __construct(
        private readonly string $token,
        private readonly HttpClient $http,
        string $chatId = ''
    ) {
        $this->chatId = $this->_normalizeChatId($chatId);
    }

    public function withChatId(string $chatId): self
    {
        $clone = clone $this;
        // chatId is readonly — use reflection trick via constructor
        return new self($this->token, $this->http, $chatId);
    }

    public function sendMessage(string $text, ?int $threadId = null): bool
    {
        $result = $this->_call('sendMessage', $this->_msgParams($text, $threadId));
        return (bool) ($result['ok'] ?? false);
    }

    public function sendMessageGetId(string $text, ?int $threadId = null): int|null
    {
        $result = $this->_call('sendMessage', $this->_msgParams($text, $threadId));
        return isset($result['ok'], $result['result']['message_id']) && $result['ok']
            ? (int) $result['result']['message_id']
            : null;
    }

    public function sendMessageWithKeyboard(string $text, array $keyboard, ?int $threadId = null): int|null
    {
        $params = $this->_msgParams($text, $threadId);
        $params['reply_markup'] = json_encode(
            ['inline_keyboard' => $keyboard],
            JSON_UNESCAPED_UNICODE
        );
        $result = $this->_call('sendMessage', $params);
        return isset($result['ok'], $result['result']['message_id']) && $result['ok']
            ? (int) $result['result']['message_id']
            : null;
    }

    public function editMessageText(int $messageId, string $text, array|null $keyboard = null): bool
    {
        $params = [
            'chat_id'    => $this->chatId,
            'message_id' => $messageId,
            'text'       => $text,
            'parse_mode' => 'HTML',
        ];
        if ($keyboard !== null) {
            $params['reply_markup'] = json_encode(
                ['inline_keyboard' => $keyboard],
                JSON_UNESCAPED_UNICODE
            );
        }
        $result = $this->_call('editMessageText', $params);
        return self::_isEditOk($result);
    }

    /**
     * Edit attempt with a tri-state result so callers can tell an ambiguous
     * transport failure (retry later — the message is probably still fine) from
     * an explicit Telegram rejection (the message is gone, a replacement is
     * warranted). The status-alert loop relies on this: a flaky link must NOT
     * make it keep posting fresh copies of the status board.
     *
     * @return bool|null true  = edited (or "message is not modified");
     *                   false = terminal rejection (e.g. message to edit not
     *                           found / can't be edited) → resend is warranted;
     *                   null  = no/timeout transport or 429/5xx — UNKNOWN, so the
     *                           caller should skip this tick rather than resend.
     */
    public function tryEditStatus(int $messageId, string $text): ?bool
    {
        $result = $this->_call('editMessageText', [
            'chat_id'    => $this->chatId,
            'message_id' => $messageId,
            'text'       => $text,
            'parse_mode' => 'HTML',
        ]);
        if ($result === null) {
            return null; // no HTTP response — network / timeout
        }
        if (self::_isEditOk($result)) {
            return true;
        }
        $code = (int) ($result['error_code'] ?? 0);
        if ($code === 429 || $code >= 500) {
            return null; // rate-limited / server-side — ambiguous, don't resend
        }
        return false; // terminal rejection → message gone, send a replacement
    }

    public function editMessageReplyMarkup(int $messageId, array|null $keyboard = null): bool
    {
        $params = [
            'chat_id'      => $this->chatId,
            'message_id'   => $messageId,
            'reply_markup' => json_encode(
                ['inline_keyboard' => $keyboard ?? []],
                JSON_UNESCAPED_UNICODE
            ),
        ];
        $result = $this->_call('editMessageReplyMarkup', $params);
        return self::_isEditOk($result);
    }

    /**
     * "message is not modified" semantically means the message already matches
     * what we wanted to push — treat it as success so callers don't waste a
     * delete+resend cycle (which is what was happening with the every-minute
     * status alert and visibly flickering it in the chat).
     */
    private static function _isEditOk(array|null $result): bool
    {
        if (!is_array($result)) {
            return false;
        }
        if (!empty($result['ok'])) {
            return true;
        }
        $desc = (string) ($result['description'] ?? '');
        return stripos($desc, 'message is not modified') !== false;
    }

    public function deleteMessage(int $messageId): bool
    {
        $result = $this->_call('deleteMessage', [
            'chat_id'    => $this->chatId,
            'message_id' => $messageId,
        ]);
        return (bool) ($result['ok'] ?? false);
    }

    public function sendPhoto(string $photoUrl, string $caption = '', ?int $threadId = null): int|null
    {
        $params = [
            'chat_id' => $this->chatId,
            'photo'   => $photoUrl,
        ];
        if ($caption !== '') {
            $params['caption'] = $caption;
        }
        if ($threadId !== null && $threadId > 0) {
            $params['message_thread_id'] = $threadId;
        }
        $result = $this->_call('sendPhoto', $params);
        return isset($result['ok'], $result['result']['message_id']) && $result['ok']
            ? (int) $result['result']['message_id']
            : null;
    }

    public function answerCallbackQuery(string $queryId, string $text = '', bool $showAlert = false): bool
    {
        $result = $this->_call('answerCallbackQuery', [
            'callback_query_id' => $queryId,
            'text'              => $text,
            'show_alert'        => $showAlert,
        ]);
        return (bool) ($result['ok'] ?? false);
    }

    public function getWebhookInfo(): array|null
    {
        $result = $this->http->getJson(
            "https://api.telegram.org/bot{$this->token}/getWebhookInfo"
        );
        return (isset($result['ok']) && $result['ok']) ? ($result['result'] ?? null) : null;
    }

    public function setWebhook(string $url, string $secret = ''): bool
    {
        $params = [
            'url'             => $url,
            'allowed_updates' => json_encode(['message', 'callback_query']),
        ];
        if ($secret !== '') {
            $params['secret_token'] = $secret;
        }
        $result = $this->_call('setWebhook', $params);
        return (bool) ($result['ok'] ?? false);
    }

    // ─── private ─────────────────────────────────────────────────────────────

    private function _call(string $method, array $params): array|null
    {
        $result = $this->http->postJson(
            "https://api.telegram.org/bot{$this->token}/{$method}",
            $params
        );

        // Surface Telegram-side failures with description + error_code so
        // the operator can tell rate-limits from bad chat_id from too-long
        // text. Without this the only upstream signal is `send returned null`.
        if (!is_array($result) || empty($result['ok'])) {
            Logger::get()->warning('telegram.api_error', [
                'method'      => $method,
                'chat_id'     => $params['chat_id']                ?? null,
                'message_id'  => $params['message_id']             ?? null,
                'thread_id'   => $params['message_thread_id']      ?? null,
                'error_code'  => $result['error_code']             ?? null,
                'description' => $result['description']            ?? '(no response from Telegram)',
                'retry_after' => $result['parameters']['retry_after'] ?? null,
            ]);
        }

        return $result;
    }

    private function _msgParams(string $text, ?int $threadId): array
    {
        $params = [
            'chat_id'    => $this->chatId,
            'text'       => $text,
            'parse_mode' => 'HTML',
        ];
        if ($threadId !== null && $threadId > 0) {
            $params['message_thread_id'] = $threadId;
        }
        return $params;
    }

    private function _normalizeChatId(string $id): string
    {
        // Telegram returns chat_id already in the canonical format in every
        // update payload — positive int for private user/bot chats, negative
        // for groups, -100xxxxxxxxxx for supergroups/channels. Anyone wiring
        // a chat id (e.g. .env TELEGRAM_CHAT_ID, callers passing user ids
        // from webhook) is expected to use the same string. We just trim.
        //
        // Previously we prefixed any positive-numeric id with "-100" which
        // worked for supergroup ids stored without their prefix, but broke
        // every reply to private chats (user id 169510539 became
        // -100169510539 -> "Bad Request: chat not found"). Don't second-guess
        // the caller.
        return trim($id);
    }
}
