<?php

declare(strict_types=1);

namespace App\Order\Infrastructure;

use App\Infrastructure\SessionCsrf;

/**
 * Per-session CSRF token for /neworder.
 *
 * In addition to manager authorization, this token stops
 * arbitrary cross-origin code from creating Poster orders on our
 * behalf: the token is server-rendered into the HTML page, stored
 * in $_SESSION, and the middleware below requires every mutation
 * request to echo it via the `X-Csrf-Token` header.
 *
 * Token is 32 bytes hex (256 bit), rotated lazily when missing or
 * when the operator hits a `rotate()` (logout-equivalent). Constant-
 * time comparison via hash_equals() — never use ===.
 */
final class Csrf
{
    public const SESSION_KEY = 'neworder_csrf';

    public static function token(): string
    {
        return SessionCsrf::token(self::SESSION_KEY);
    }

    public static function verify(string $candidate): bool
    {
        return SessionCsrf::verify(self::SESSION_KEY, $candidate);
    }

    public static function rotate(): void
    {
        SessionCsrf::rotate(self::SESSION_KEY);
    }
}
