<?php

declare(strict_types=1);

namespace App\Payday3\Http;

/**
 * Per-session, per-key throttle. Stops a user from spamming an
 * expensive endpoint (sepay sync, poster sync, IMAP fetch) faster
 * than once every N seconds.
 *
 * Sessions are the trust boundary — every payday3 endpoint is
 * behind AuthMiddleware, so $_SESSION is always present and is the
 * natural per-user key.
 *
 * Actions call ::guard('sepay-sync', 5) at the top of __invoke;
 * if the previous hit was less than 5 seconds ago, the method
 * throws a TooManyRequestsException that the Action catches and
 * turns into a 429 with Retry-After.
 */
final class RequestThrottle
{
    /**
     * @throws TooManyRequestsException with seconds-remaining if a
     *         duplicate call lands inside the cooldown window.
     */
    public static function guard(string $key, int $cooldownSeconds): void
    {
        \App\Infrastructure\Session::start();
        $bucketKey = '__pd3_throttle__';
        $now    = time();
        $bucket = $_SESSION[$bucketKey] ?? [];
        $last   = (int)($bucket[$key] ?? 0);
        $elapsed = $now - $last;
        if ($last > 0 && $elapsed < $cooldownSeconds) {
            throw new TooManyRequestsException(
                'Слишком часто. Подожди ' . ($cooldownSeconds - $elapsed) . ' сек.',
                $cooldownSeconds - $elapsed,
            );
        }
        $bucket[$key] = $now;
        $_SESSION[$bucketKey] = $bucket;
    }
}
