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
}
