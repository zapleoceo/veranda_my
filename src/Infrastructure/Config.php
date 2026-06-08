<?php

declare(strict_types=1);

namespace App\Infrastructure;

class Config
{
    private static array $_data = [];
    private static bool $_loaded = false;

    public static function load(string $path): void
    {
        if (self::$_loaded) {
            return;
        }

        if (!file_exists($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if (str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$name, $value] = explode('=', $line, 2);
            $name  = trim($name);
            // Strip surrounding single or double quotes so values like
            // MAIL_PASS="..." in production .env load correctly. Legacy
            // auth_check.php has always done this; previously the Slim
            // loader carried the quotes into $_ENV and Gmail IMAP
            // rejected MAIL_PASS with [AUTHENTICATIONFAILED].
            $value = trim(trim($value), "\"'");

            self::$_data[$name] = $value;
            // also populate $_ENV for legacy code that reads it directly
            $_ENV[$name] = $value;
        }

        self::$_loaded = true;
    }

    public static function get(string $key, string $default = ''): string
    {
        return self::$_data[$key] ?? $_ENV[$key] ?? $default;
    }

    /** @throws \RuntimeException when key is missing */
    public static function require(string $key): string
    {
        $value = self::get($key);
        if ($value === '') {
            throw new \RuntimeException("Required config key '{$key}' is missing");
        }
        return $value;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);
        if ($value === '') {
            return $default;
        }
        return in_array(strtolower($value), ['true', '1', 'yes', 'on'], true);
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = self::get($key);
        return $value !== '' ? (int) $value : $default;
    }

    /**
     * Base URL of this site — no trailing slash.
     * Reads SITE_BASE_URL from env; falls back to deriving from the current request.
     * This is the single source of truth: change SITE_BASE_URL in .env to migrate domains.
     */
    public static function baseUrl(): string
    {
        $env = rtrim(self::get('SITE_BASE_URL'), '/');
        if ($env !== '') {
            return $env;
        }
        // За Cloudflare/прокси $_SERVER['HTTPS'] не выставлен (SSL терминируется на edge,
        // на origin идёт http) — учитываем прокси-заголовки, иначе og/JSON-LD уходят как http.
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
            || (stripos((string)($_SERVER['HTTP_CF_VISITOR'] ?? ''), 'https') !== false)
            || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);
        $host  = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
        return ($https ? 'https' : 'http') . '://' . $host;
    }
}
