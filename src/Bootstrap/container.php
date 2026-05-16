<?php

declare(strict_types=1);

use App\Infrastructure\Config;
use App\Infrastructure\Database;
use App\Infrastructure\HttpClient;
use App\Infrastructure\Logger;
use App\Infrastructure\TelegramBotClient;
use App\Services\KitchenOnlineService;
use App\Services\UserPermissionsService;
use App\Services\MenuPublicService;
use App\Services\PosterReservationsService;
use App\Services\RawdataService;
use App\Services\ReservationMessagingService;
use App\Services\ReservationsService;
use App\Payday3\Contracts\LinkRepositoryInterface;
use App\Payday3\Contracts\PosterRepositoryInterface;
use App\Payday3\Contracts\SepayRepositoryInterface;
use App\Payday3\Repositories\LinkRepository;
use App\Payday3\Repositories\PosterRepository;
use App\Payday3\Repositories\SepayRepository;
use App\Payday3\Http\PageDataAssembler;
use App\Payday3\Http\Payday3Controller;
use App\Payday3\Http\Actions\LinksAction;
use App\Payday3\Http\Actions\AutoLinkAction;
use App\Payday3\Http\Actions\ManualLinkAction;
use App\Payday3\Http\Actions\UnlinkAction;
use App\Payday3\Http\Actions\ClearLinksAction;
use App\Payday3\Http\Actions\ClearDayAction;
use App\Payday3\Http\Actions\SepaySyncAction;
use App\Payday3\Http\Actions\PosterSyncAction;
use App\Payday3\Contracts\ReconciliationServiceInterface;
use App\Payday3\Contracts\DayResetServiceInterface;
use App\Payday3\Services\ReconciliationService;
use App\Payday3\Services\DayResetService;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Log\LoggerInterface;
use Slim\Psr7\Factory\ResponseFactory;

return [
    ResponseFactoryInterface::class => fn() => new ResponseFactory(),

    Database::class => fn() => Database::getInstance(),

    UserPermissionsService::class        => fn($c) => new UserPermissionsService($c->get(Database::class)),
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

    // ─── Payday3 ──────────────────────────────────────────────
    SepayRepositoryInterface::class  => fn($c) => new SepayRepository($c->get(Database::class)),
    PosterRepositoryInterface::class => fn($c) => new PosterRepository($c->get(Database::class)),
    LinkRepositoryInterface::class   => fn($c) => new LinkRepository($c->get(Database::class)),
    PageDataAssembler::class => fn($c) => new PageDataAssembler(
        $c->get(SepayRepositoryInterface::class),
        $c->get(PosterRepositoryInterface::class),
        $c->get(LinkRepositoryInterface::class),
    ),
    Payday3Controller::class => fn($c) => new Payday3Controller($c->get(PageDataAssembler::class)),
    ReconciliationServiceInterface::class => fn($c) => new ReconciliationService(
        $c->get(SepayRepositoryInterface::class),
        $c->get(PosterRepositoryInterface::class),
        $c->get(LinkRepositoryInterface::class),
    ),
    LinksAction::class      => fn($c) => new LinksAction($c->get(LinkRepositoryInterface::class)),
    AutoLinkAction::class   => fn($c) => new AutoLinkAction(
        $c->get(ReconciliationServiceInterface::class),
        $c->get(LinkRepositoryInterface::class),
    ),
    ManualLinkAction::class => fn($c) => new ManualLinkAction(
        $c->get(ReconciliationServiceInterface::class),
        $c->get(LinkRepositoryInterface::class),
    ),
    UnlinkAction::class     => fn($c) => new UnlinkAction(
        $c->get(ReconciliationServiceInterface::class),
        $c->get(LinkRepositoryInterface::class),
    ),
    ClearLinksAction::class => fn($c) => new ClearLinksAction(
        $c->get(ReconciliationServiceInterface::class),
    ),
    DayResetServiceInterface::class => fn($c) => new DayResetService($c->get(Database::class)),
    ClearDayAction::class    => fn($c) => new ClearDayAction($c->get(DayResetServiceInterface::class)),
    SepaySyncAction::class   => fn()   => new SepaySyncAction(),
    PosterSyncAction::class  => fn()   => new PosterSyncAction(),
];
