<?php

declare(strict_types=1);

namespace App\Infrastructure;

/**
 * Centralised PHP session bootstrap.
 *
 * Pre-this helper, every entry point called raw `session_start()` and
 * inherited php.ini's `session.gc_maxlifetime = 1440` (24 minutes).
 * Operators got logged out after a quick lunch.
 *
 * Configure() must run **before any session_start** — Bootstrap/app.php
 * does that. Start() is idempotent and rolls the cookie's expires
 * forward on every authenticated request, so an actively-used session
 * never expires (sliding window).
 *
 *   Cookie lifetime          30 days
 *   GC max lifetime          30 days
 *   HttpOnly                 yes  (XSS can't steal it)
 *   Secure                   auto (on when request came over HTTPS,
 *                                  including through a TLS-terminating
 *                                  proxy that set X-Forwarded-Proto)
 *   SameSite                 Lax  (cross-site GETs work for OAuth
 *                                  callback, cross-site POSTs blocked)
 */
final class Session
{
    /** 30 days, in seconds. */
    public const LIFETIME = 30 * 24 * 60 * 60;

    private static bool $configured = false;

    /** Call once at boot, BEFORE any session_start. */
    public static function configure(): void
    {
        if (self::$configured) return;
        self::$configured = true;
        if (PHP_SAPI === 'cli') return;
        if (session_status() === PHP_SESSION_ACTIVE) return; // too late, just record

        $secure = self::isHttps();
        @ini_set('session.gc_maxlifetime',  (string)self::LIFETIME);
        @ini_set('session.cookie_lifetime', (string)self::LIFETIME);
        @ini_set('session.cookie_httponly', '1');
        @ini_set('session.cookie_samesite', 'Lax');
        if ($secure) @ini_set('session.cookie_secure', '1');

        // session_set_cookie_params() is the authoritative source for
        // these on some hosts (PHP-FPM pool overrides, opcache.ini
        // ordering). Set both layers to be safe.
        @session_set_cookie_params([
            'lifetime' => self::LIFETIME,
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    /**
     * Start the session if it isn't already running. Cheap to call —
     * use it instead of raw session_start() everywhere.
     *
     * Also implements the sliding-expiry: each call bumps the
     * session cookie's expires forward by LIFETIME, so a user who
     * actively uses the app stays logged in indefinitely.
     */
    public static function start(): void
    {
        self::configure();
        if (PHP_SAPI === 'cli') return;
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        // Roll the cookie expiry forward (sliding window). Only if
        // the cookie already exists — don't auto-set one on first
        // visit without an active session.
        $name = session_name();
        if ($name === false || empty($_COOKIE[$name])) return;
        if (headers_sent()) return;
        $p = session_get_cookie_params();
        @setcookie($name, session_id() ?: '', [
            'expires'  => time() + self::LIFETIME,
            'path'     => $p['path']     ?? '/',
            'domain'   => $p['domain']   ?? '',
            'secure'   => $p['secure']   ?? self::isHttps(),
            'httponly' => $p['httponly'] ?? true,
            'samesite' => $p['samesite'] ?? 'Lax',
        ]);
    }

    /**
     * Release the session file lock so concurrent requests from the
     * same client can run in parallel. PHP's default file session
     * handler holds an exclusive lock from session_start() until
     * either session_write_close() OR script end — that's why two
     * AJAX requests from the same operator run sequentially even
     * when the server is otherwise idle.
     *
     * Idempotent: safe to call multiple times. After close, $_SESSION
     * stays readable in-memory; only **new writes** are silently
     * dropped unless start() reopens the session first.
     */
    public static function close(): void
    {
        if (PHP_SAPI === 'cli') return;
        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_write_close();
        }
    }

    private static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') return true;
        $fp = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
        return strtolower((string)$fp) === 'https';
    }
}
