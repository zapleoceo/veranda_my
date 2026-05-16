<?php

declare(strict_types=1);

namespace App\Infrastructure;

use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Level;
use Monolog\Logger as Monolog;

class Logger
{
    private static Monolog|null $_instance = null;

    public static function init(string $level = 'info'): void
    {
        $monolog = new Monolog('app');

        $logLevel = Level::fromName(strtolower($level));
        $logPath  = dirname(__DIR__, 2) . '/logs/app.log';

        @mkdir(dirname($logPath), 0755, true);

        $monolog->pushHandler(new RotatingFileHandler($logPath, maxFiles: 14, level: $logLevel));

        // Also log to stderr in development
        if (Config::get('APP_ENV') === 'development') {
            $monolog->pushHandler(new StreamHandler('php://stderr', $logLevel));
        }

        self::$_instance = $monolog;
    }

    public static function get(): Monolog
    {
        if (self::$_instance === null) {
            self::init();
        }
        return self::$_instance;
    }
}
