<?php

declare(strict_types=1);

use App\Infrastructure\Config;
use App\Infrastructure\Database;
use App\Infrastructure\HttpClient;
use App\Infrastructure\TelegramBotClient;
use Monolog\Logger;

return [
    Database::class => fn() => Database::getInstance(),

    HttpClient::class => fn() => new HttpClient(
        timeoutSeconds: 10
    ),

    TelegramBotClient::class => fn() => new TelegramBotClient(
        token: Config::require('TELEGRAM_BOT_TOKEN'),
        http: new HttpClient(timeoutSeconds: 15)
    ),
];
