<?php

declare(strict_types=1);

namespace App\Infrastructure;

/**
 * Per-session CSRF token store, keyed by a namespace (one token per
 * sub-app: /neworder, /payday3 …) so rotating one never logs another
 * page out.
 *
 * Token is 32 bytes hex (256 bit), created lazily. Comparison is
 * constant-time via hash_equals() — never use ===.
 */
final class SessionCsrf
{
    public static function token(string $sessionKey): string
    {
        Session::start();
        if (empty($_SESSION[$sessionKey]) || !is_string($_SESSION[$sessionKey])) {
            $_SESSION[$sessionKey] = bin2hex(random_bytes(32));
        }
        return (string)$_SESSION[$sessionKey];
    }

    public static function verify(string $sessionKey, string $candidate): bool
    {
        Session::start();
        $real = $_SESSION[$sessionKey] ?? '';
        if (!is_string($real) || $real === '' || $candidate === '') return false;
        return hash_equals($real, $candidate);
    }

    public static function rotate(string $sessionKey): void
    {
        Session::start();
        $_SESSION[$sessionKey] = bin2hex(random_bytes(32));
    }
}
