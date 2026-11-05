<?php

declare(strict_types=1);

namespace App\Infrastructure;

final class TelegramFileException extends \RuntimeException
{
    public function __construct(string $message, public readonly bool $retryable = true)
    {
        parent::__construct($message);
    }
}
