<?php

declare(strict_types=1);

namespace App\OnlineOrder\Infrastructure;

use App\Infrastructure\Session;

/**
 * Session-scoped rate limit for the public order endpoint: a real
 * customer never needs more than a few orders per ten minutes, while
 * a stuck retry-loop or a prankster with our CSRF token does. Sits on
 * top of (not instead of) CsrfMiddleware's origin checks.
 */
final class SubmitThrottle
{
    private const SESSION_KEY = 'onlineorder_submits';

    public function __construct(
        private readonly int $maxAttempts   = 3,
        private readonly int $windowSeconds = 600,
    ) {}

    /** True when this session may submit another order right now. */
    public function allow(): bool
    {
        Session::start();
        $now  = time();
        $hits = array_values(array_filter(
            is_array($_SESSION[self::SESSION_KEY] ?? null) ? $_SESSION[self::SESSION_KEY] : [],
            fn($ts) => is_int($ts) && $ts > $now - $this->windowSeconds,
        ));
        $_SESSION[self::SESSION_KEY] = $hits;
        return count($hits) < $this->maxAttempts;
    }

    /** Record a successful submission. */
    public function hit(): void
    {
        Session::start();
        $hits   = is_array($_SESSION[self::SESSION_KEY] ?? null) ? $_SESSION[self::SESSION_KEY] : [];
        $hits[] = time();
        $_SESSION[self::SESSION_KEY] = $hits;
    }
}
