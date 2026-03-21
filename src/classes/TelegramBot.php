<?php

namespace App\Classes;

class TelegramBot {
    private string $token;
    private string $chatId;

    public function __construct(string $token, string $chatId) {
        $this->token = $token;
        $this->chatId = $chatId;
    }

    /**
     * Отправка текстового сообщения в Telegram
     */
    public function sendMessage(string $text, ?int $messageThreadId = null): bool {
        if (empty($this->chatId)) return false;

        $url = "https://api.telegram.org/bot{$this->token}/sendMessage";
        $params = [
            'chat_id' => $this->chatId,
            'text' => $text,
            'parse_mode' => 'HTML'
        ];
        if ($messageThreadId !== null && $messageThreadId > 0) {
            $params['message_thread_id'] = $messageThreadId;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $response = curl_exec($ch);
        curl_close($ch);

        if ($response === false) {
            return false;
        }
        $data = json_decode($response, true);
        if (is_array($data) && array_key_exists('ok', $data)) {
            return (bool)$data['ok'];
        }
        return true;
    }

    /**
     * Удаление сообщения из Telegram
     */
    public function deleteMessage(int $messageId): bool {
        if (empty($this->chatId)) return false;

        $url = "https://api.telegram.org/bot{$this->token}/deleteMessage";
        $params = [
            'chat_id' => $this->chatId,
            'message_id' => $messageId
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $response = curl_exec($ch);
        curl_close($ch);

        if ($response === false) {
            return false;
        }
        $data = json_decode($response, true);
        if (is_array($data) && array_key_exists('ok', $data)) {
            return (bool)$data['ok'];
        }
        return true;
    }

    /**
     * Отправка сообщения и возврат его ID
     */
    public function sendMessageGetId(string $text, ?int $messageThreadId = null): ?int {
        if (empty($this->chatId)) return null;

        $url = "https://api.telegram.org/bot{$this->token}/sendMessage";
        $params = [
            'chat_id' => $this->chatId,
            'text' => $text,
            'parse_mode' => 'HTML'
        ];
        if ($messageThreadId !== null && $messageThreadId > 0) {
            $params['message_thread_id'] = $messageThreadId;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $response = curl_exec($ch);
        curl_close($ch);

        if ($response) {
            $data = json_decode($response, true);
            return $data['result']['message_id'] ?? null;
        }

        return null;
    }

    public function sendMessageGetIdWithKeyboard(string $text, array $keyboard, ?int $messageThreadId = null): ?int {
        if (empty($this->chatId)) return null;

        $url = "https://api.telegram.org/bot{$this->token}/sendMessage";
        $params = [
            'chat_id' => $this->chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard], JSON_UNESCAPED_UNICODE)
        ];
        if ($messageThreadId !== null && $messageThreadId > 0) {
            $params['message_thread_id'] = $messageThreadId;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $response = curl_exec($ch);
        curl_close($ch);

        if ($response) {
            $data = json_decode($response, true);
            return $data['result']['message_id'] ?? null;
        }

        return null;
    }

    public function editMessageText(int $messageId, string $text, ?array $keyboard = null): bool {
        if (empty($this->chatId)) return false;

        $url = "https://api.telegram.org/bot{$this->token}/editMessageText";
        $params = [
            'chat_id' => $this->chatId,
            'message_id' => $messageId,
            'text' => $text,
            'parse_mode' => 'HTML'
        ];
        if ($keyboard !== null) {
            $params['reply_markup'] = json_encode(['inline_keyboard' => $keyboard], JSON_UNESCAPED_UNICODE);
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $response = curl_exec($ch);
        curl_close($ch);

        if ($response === false) {
            return false;
        }
        $data = json_decode($response, true);
        if (is_array($data) && array_key_exists('ok', $data)) {
            return (bool)$data['ok'];
        }
        return true;
    }
}
