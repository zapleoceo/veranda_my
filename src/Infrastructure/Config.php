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
            $value = trim($value);

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
}
