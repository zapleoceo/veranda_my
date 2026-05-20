<?php

declare(strict_types=1);

namespace App\Order\Infrastructure;

use App\Infrastructure\Session;

/**
 * Per-session CSRF token for /neworder.
 *
 * Until proper auth lands, this is the primary defence that stops
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
    private const SESSION_KEY = 'neworder_csrf';

    public static function token(): string
    {
        Session::start();
        if (empty($_SESSION[self::SESSION_KEY]) || !is_string($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }
        return (string)$_SESSION[self::SESSION_KEY];
    }

    public static function verify(string $candidate): bool
    {
        Session::start();
        $real = (string)($_SESSION[self::SESSION_KEY] ?? '');
        if ($real === '' || $candidate === '') return false;
        return hash_equals($real, $candidate);
    }

    public static function rotate(): void
    {
        Session::start();
        $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
    }
}
