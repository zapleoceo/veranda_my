<?php

declare(strict_types=1);

namespace App\Payday3\Http;

final class TooManyRequestsException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $retryAfter)
    {
        parent::__construct($message, 429);
    }
}
