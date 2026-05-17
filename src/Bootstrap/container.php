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
use App\Payday3\Contracts\PosterSyncServiceInterface;
use App\Payday3\Contracts\SepaySyncServiceInterface;
use App\Payday3\Contracts\MailServiceInterface;
use App\Payday3\Contracts\FinanceServiceInterface;
use App\Payday3\Contracts\OutLinkRepositoryInterface;
use App\Payday3\Contracts\OutReconciliationServiceInterface;
use App\Payday3\Services\ReconciliationService;
use App\Payday3\Services\DayResetService;
use App\Payday3\Services\PosterSyncService;
use App\Payday3\Services\SepaySyncService;
use App\Payday3\Services\MailImapService;
use App\Payday3\Services\FinancePosterService;
use App\Payday3\Services\OutReconciliationService;
use App\Payday3\Repositories\OutLinkRepository;
use App\Payday3\Http\Actions\OutDataAction;
use App\Payday3\Http\Actions\OutAutoLinkAction;
use App\Payday3\Http\Actions\OutManualLinkAction;
use App\Payday3\Http\Actions\OutUnlinkAction;
use App\Payday3\Http\Actions\OutClearLinksAction;
use App\Payday3\Http\Actions\MailHideAction;
use App\Payday3\Http\Actions\ActualBalanceAction;
use App\Payday3\Contracts\ActualBalanceRepositoryInterface;
use App\Payday3\Repositories\ActualBalanceRepository;
use App\Payday3\Contracts\PosterApiProviderInterface;
use App\Payday3\Contracts\TelegramNotifierInterface;
use App\Payday3\Contracts\PosterCashShiftServiceInterface;
use App\Payday3\Contracts\PosterSuppliesServiceInterface;
use App\Payday3\Contracts\PosterCheckServiceInterface;
use App\Payday3\Services\PosterApiProvider;
use App\Payday3\Services\TelegramNotifier;
use App\Payday3\Services\PosterCashShiftService;
use App\Payday3\Services\PosterSuppliesService;
use App\Payday3\Services\PosterCheckService;
use App\Payday3\Services\PosterLookupService;
use App\Payday3\Services\JsonLocalSettingsRepository;
use App\Payday3\Contracts\PosterLookupServiceInterface;
use App\Payday3\Contracts\LocalSettingsRepositoryInterface;
use App\Payday3\Http\Actions\PosterCashShiftListAction;
use App\Payday3\Http\Actions\PosterCashShiftDetailAction;
use App\Payday3\Http\Actions\PosterSuppliesListAction;
use App\Payday3\Http\Actions\PosterSupplyChangeAccountAction;
use App\Payday3\Http\Actions\PosterCheckFindAction;
use App\Payday3\Http\Actions\PosterCheckRemoveAction;
use App\Payday3\Http\Actions\PosterEmployeesAction;
use App\Payday3\Http\Actions\PosterFinanceAccountsAction;
use App\Payday3\Http\Actions\PosterFinanceCategoriesAction;
use App\Payday3\Http\Actions\SettingsAction;
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
    SepaySyncServiceInterface::class  => fn($c) => new SepaySyncService($c->get(Database::class)),
    SepaySyncAction::class            => fn($c) => new SepaySyncAction($c->get(SepaySyncServiceInterface::class)),
    PosterSyncServiceInterface::class => fn($c) => new PosterSyncService($c->get(Database::class)),
    PosterSyncAction::class           => fn($c) => new PosterSyncAction($c->get(PosterSyncServiceInterface::class)),

    // ─── Payday3 OUT mode ──────────────────────────────────────
    MailServiceInterface::class       => fn($c) => new MailImapService($c->get(Database::class)),
    FinanceServiceInterface::class    => fn($c) => new FinancePosterService(
        $c->get(PosterApiProviderInterface::class),
        $c->get(LocalSettingsRepositoryInterface::class),
    ),
    OutLinkRepositoryInterface::class => fn($c) => new OutLinkRepository($c->get(Database::class)),
    OutReconciliationServiceInterface::class => fn($c) => new OutReconciliationService(
        $c->get(MailServiceInterface::class),
        $c->get(FinanceServiceInterface::class),
        $c->get(OutLinkRepositoryInterface::class),
    ),
    OutDataAction::class       => fn($c) => new OutDataAction(
        $c->get(MailServiceInterface::class),
        $c->get(FinanceServiceInterface::class),
        $c->get(OutLinkRepositoryInterface::class),
    ),
    OutAutoLinkAction::class   => fn($c) => new OutAutoLinkAction(
        $c->get(OutReconciliationServiceInterface::class),
        $c->get(OutLinkRepositoryInterface::class),
    ),
    OutManualLinkAction::class => fn($c) => new OutManualLinkAction(
        $c->get(OutReconciliationServiceInterface::class),
        $c->get(OutLinkRepositoryInterface::class),
    ),
    OutUnlinkAction::class     => fn($c) => new OutUnlinkAction(
        $c->get(OutReconciliationServiceInterface::class),
        $c->get(OutLinkRepositoryInterface::class),
    ),
    OutClearLinksAction::class => fn($c) => new OutClearLinksAction(
        $c->get(OutReconciliationServiceInterface::class),
    ),
    MailHideAction::class      => fn($c) => new MailHideAction($c->get(MailServiceInterface::class)),

    // ─── Payday3 balances footer ───────────────────────────────
    ActualBalanceRepositoryInterface::class => fn($c) => new ActualBalanceRepository($c->get(Database::class)),
    ActualBalanceAction::class              => fn($c) => new ActualBalanceAction($c->get(ActualBalanceRepositoryInterface::class)),

    // ─── Payday3 Poster integrations (replaces payday2 fallbacks) ─
    PosterApiProviderInterface::class       => fn()   => new PosterApiProvider(),

    // LocalSettings — payday3-owned JSON config, with one-time fallback
    // to payday2/local_config.json so existing deploys keep tuned values.
    LocalSettingsRepositoryInterface::class => fn() => new JsonLocalSettingsRepository(
        primaryPath:  dirname(__DIR__, 2) . '/payday3/local_config.json',
        fallbackPath: dirname(__DIR__, 2) . '/payday2/local_config.json',
    ),
    TelegramNotifierInterface::class        => fn($c) => new TelegramNotifier($c->get(LocalSettingsRepositoryInterface::class)),

    PosterCashShiftServiceInterface::class  => fn($c) => new PosterCashShiftService($c->get(PosterApiProviderInterface::class)),
    PosterSuppliesServiceInterface::class   => fn($c) => new PosterSuppliesService($c->get(PosterApiProviderInterface::class)),
    PosterCheckServiceInterface::class      => fn($c) => new PosterCheckService(
        $c->get(PosterApiProviderInterface::class),
        $c->get(TelegramNotifierInterface::class),
        $c->get(LocalSettingsRepositoryInterface::class),
    ),
    PosterLookupServiceInterface::class     => fn($c) => new PosterLookupService($c->get(PosterApiProviderInterface::class)),

    PosterCashShiftListAction::class        => fn($c) => new PosterCashShiftListAction($c->get(PosterCashShiftServiceInterface::class)),
    PosterCashShiftDetailAction::class      => fn($c) => new PosterCashShiftDetailAction($c->get(PosterCashShiftServiceInterface::class)),
    PosterSuppliesListAction::class         => fn($c) => new PosterSuppliesListAction($c->get(PosterSuppliesServiceInterface::class)),
    PosterSupplyChangeAccountAction::class  => fn($c) => new PosterSupplyChangeAccountAction($c->get(PosterSuppliesServiceInterface::class)),
    PosterCheckFindAction::class            => fn($c) => new PosterCheckFindAction($c->get(PosterCheckServiceInterface::class)),
    PosterCheckRemoveAction::class          => fn($c) => new PosterCheckRemoveAction($c->get(PosterCheckServiceInterface::class)),
    PosterEmployeesAction::class            => fn($c) => new PosterEmployeesAction($c->get(PosterLookupServiceInterface::class)),
    PosterFinanceAccountsAction::class      => fn($c) => new PosterFinanceAccountsAction($c->get(PosterLookupServiceInterface::class)),
    PosterFinanceCategoriesAction::class    => fn($c) => new PosterFinanceCategoriesAction($c->get(PosterLookupServiceInterface::class)),
    SettingsAction::class                   => fn($c) => new SettingsAction($c->get(LocalSettingsRepositoryInterface::class)),
];
