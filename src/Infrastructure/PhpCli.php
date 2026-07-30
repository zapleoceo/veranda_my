<?php

declare(strict_types=1);

namespace App\Infrastructure;

/**
 * Путь к CLI-бинарю PHP для фоновых задач, запускаемых из веб-запроса.
 *
 * Зачем отдельный хелпер: под PHP-FPM константа PHP_BINARY указывает на
 * /opt/php82/sbin/php-fpm, а не на CLI. php-fpm не принимает путь к скрипту —
 * он печатает свой usage и выходит с кодом 0. Поэтому «фоновая задача»
 * тихо не выполнялась, а в лог падала справка php-fpm (ровно так месяцами
 * не работал пересинк кухни из /rawdata: RawdataService брал PHP_BINARY).
 *
 * PHP_BINDIR — это compile-time bindir (для нашей сборки /opt/php82/bin),
 * он одинаков у обоих SAPI, поэтому PHP_BINDIR . '/php' даёт настоящий CLI
 * и не требует хардкода пути под конкретный хостинг.
 */
final class PhpCli
{
    /**
     * @return string Абсолютный путь к CLI-бинарю (для подстановки в exec).
     */
    public static function binary(): string
    {
        // В CLI-контексте (cron, скрипты) PHP_BINARY уже правильный.
        if (PHP_SAPI === 'cli' && self::_usable(PHP_BINARY)) {
            return PHP_BINARY;
        }

        foreach ([PHP_BINDIR . '/php', '/opt/php82/bin/php', '/usr/bin/php'] as $candidate) {
            if (self::_usable($candidate)) {
                return $candidate;
            }
        }

        // Последний шанс: пусть решает PATH. Хуже, чем явный путь, но лучше,
        // чем гарантированно неверный php-fpm из PHP_BINARY.
        return 'php';
    }

    private static function _usable(string $path): bool
    {
        return $path !== '' && @is_executable($path) && !str_contains(basename($path), 'fpm');
    }
}
