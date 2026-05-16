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
        return (bool) ($result['ok'] ?? false);
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
        return (bool) ($result['ok'] ?? false);
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
        $id = trim($id);
        if ($id === '' || $id[0] === '@' || $id[0] === '-') {
            return $id;
        }
        $digits = preg_replace('/\D+/', '', $id);
        return ($digits !== '') ? '-100' . $digits : $id;
    }
}
