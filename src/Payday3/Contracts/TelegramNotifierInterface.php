<?php

declare(strict_types=1);

namespace App\Payday3\Contracts;

interface TelegramNotifierInterface
{
    /**
     * Send a plain-text message to the configured chat / thread.
     *
     * @return array{ok:bool, error?:string}
     */
    public function sendText(string $text, ?string $chatId = null, ?string $threadId = null): array;

    /**
     * Send a binary photo (raw bytes) with optional caption. Used by the
     * "send balance screenshot to Telegram" button — the client renders
     * the card to PNG via html2canvas and POSTs the data URL.
     *
     * @return array{ok:bool, error?:string}
     */
    public function sendPhoto(string $bytes, string $mime = 'image/png', string $caption = '', ?string $chatId = null, ?string $threadId = null): array;
}
