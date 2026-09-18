<?php

declare(strict_types=1);

namespace App\Infrastructure;

/**
 * Validates an internal redirect target (the `?next=` / `auth_next` value)
 * to prevent open redirects after login. Only same-site absolute paths are
 * allowed — never absolute URLs, protocol-relative `//host` tricks, or the
 * auth endpoints themselves (which would loop or leak the session).
 *
 * Browsers normalise `/\evil.com` and `/<TAB>/evil.com` to `//evil.com`,
 * so backslashes, whitespace and control characters are rejected outright,
 * and anything parse_url() sees a scheme or host in is refused as well.
 *
 * Single source of truth used by LoginController (stash), AuthMiddleware
 * (stash) and CallbackController (consume) so the producer and the consumer
 * of `auth_next` can never disagree.
 */
final class ReturnPath
{
    public static function isSafe(string $path): bool
    {
        if ($path === '' || $path[0] !== '/')    return false;
        if (str_starts_with($path, '//'))         return false;
        if (str_contains($path, '\\'))            return false;
        // Control chars (incl. TAB/CR/LF), DEL and any whitespace.
        // preg_match returns false on invalid UTF-8 — treat that as unsafe too.
        if (preg_match('/[\x00-\x20\x7F]|\s/u', $path) !== 0) return false;
        $parts = parse_url($path);
        if ($parts === false)                     return false;
        if (isset($parts['scheme']) || isset($parts['host'])) return false;
        if (str_starts_with($path, '/login'))     return false;
        if (str_starts_with($path, '/logout'))    return false;
        if (str_starts_with($path, '/auth/'))     return false;
        return true;
    }
}
