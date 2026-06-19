<?php

declare(strict_types=1);

namespace App\OnlineOrder\Infrastructure;

use App\Infrastructure\Session;

/**
 * Session-scoped rate limit for public POST endpoints: a real customer never
 * needs more than a few submits per window, while a stuck retry-loop or a
 * prankster with our CSRF token does. Sits on top of (not instead of) the
 * CSRF/origin checks.
 *
 * The session key is configurable so unrelated endpoints (online orders,
 * blogger self-registration, …) keep independent counters from one class.
 */
final class SubmitThrottle
{
    private const DEFAULT_KEY = 'onlineorder_submits';

    public function __construct(
        private readonly int $maxAttempts   = 3,
        private readonly int $windowSeconds = 600,
        private readonly string $sessionKey = self::DEFAULT_KEY,
    ) {}

    /** True when this session may submit again right now. */
    public function allow(): bool
    {
        Session::start();
        $now  = time();
        $hits = array_values(array_filter(
            is_array($_SESSION[$this->sessionKey] ?? null) ? $_SESSION[$this->sessionKey] : [],
            fn($ts) => is_int($ts) && $ts > $now - $this->windowSeconds,
        ));
        $_SESSION[$this->sessionKey] = $hits;
        return count($hits) < $this->maxAttempts;
    }

    /** Record a successful submission. */
    public function hit(): void
    {
        Session::start();
        $hits   = is_array($_SESSION[$this->sessionKey] ?? null) ? $_SESSION[$this->sessionKey] : [];
        $hits[] = time();
        $_SESSION[$this->sessionKey] = $hits;
    }
}
