<?php

declare(strict_types=1);

use App\Infrastructure\Config;
use App\Infrastructure\Database;
use App\Infrastructure\HttpClient;
use App\Infrastructure\Logger;
use App\Infrastructure\TelegramBotClient;
use App\Services\KitchenOnlineService;
use App\Services\MenuPublicService;
use App\Services\PosterReservationsService;
use App\Services\RawdataService;
use App\Services\ReservationMessagingService;
use App\Services\ReservationsService;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Log\LoggerInterface;
use Slim\Psr7\Factory\ResponseFactory;

return [
    ResponseFactoryInterface::class => fn() => new ResponseFactory(),

    Database::class => fn() => Database::getInstance(),

    KitchenOnlineService::class         => fn($c) => new KitchenOnlineService($c->get(Database::class)),
    RawdataService::class               => fn($c) => new RawdataService($c->get(Database::class)),
    MenuPublicService::class            => fn($c) => new MenuPublicService($c->get(Database::class)),
    ReservationsService::class          => fn($c) => new ReservationsService($c->get(Database::class)),
    PosterReservationsService::class    => fn() => new PosterReservationsService(),
    ReservationMessagingService::class  => fn() => new ReservationMessagingService(),

    HttpClient::class => fn() => new HttpClient(timeoutSeconds: 10),

    TelegramBotClient::class => fn() => new TelegramBotClient(
        token: Config::require('TELEGRAM_BOT_TOKEN'),
        http: new HttpClient(timeoutSeconds: 15)
    ),

    LoggerInterface::class => fn() => Logger::get(),
];
