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
use App\Payday3\Services\DbLocalSettingsRepository;
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
use App\Payday3\Http\Actions\PosterCheckListAction;
use App\Payday3\Http\Actions\PosterBalanceSnapshotAction;
use App\Payday3\Http\Actions\SettingsAction;
use App\Payday3\Http\Actions\InDataAction;
use App\Payday3\Http\Actions\FinanceTransfersAction;
use App\Payday3\Http\Actions\BalanceScreenshotAction;
use App\Payday3\Http\Actions\BalanceSyncPlanAction;
use App\Payday3\Http\Actions\BalanceSyncCommitAction;
use App\Payday3\Contracts\BalanceSyncServiceInterface;
use App\Payday3\Services\BalanceSyncService;
use App\Payday3\Http\Actions\PosterTransactionCreateAction;
use App\Payday3\Contracts\PosterTransactionCreateServiceInterface;
use App\Payday3\Services\PosterTransactionCreateService;
use App\Payday3\Contracts\FinanceTransferServiceInterface;
use App\Payday3\Services\FinanceTransferService;
use App\Payday3\Contracts\PosterBalanceServiceInterface;
use App\Payday3\Services\PosterBalanceService;
use App\Schedule\Contracts\EmployeeRateRepositoryInterface;
use App\Schedule\Contracts\EmployeesProviderInterface;
use App\Schedule\Contracts\HallsProviderInterface;
use App\Schedule\Contracts\SnapshotRepositoryInterface;
use App\Schedule\Contracts\StaffTagRepositoryInterface;
use App\Schedule\Contracts\ZoneRepositoryInterface;
use App\Schedule\Http\Actions\AddZoneAction;
use App\Schedule\Http\Actions\DebugPosterAction;
use App\Schedule\Http\Actions\DeleteSnapshotAction;
use App\Schedule\Http\Actions\DeleteZoneAction;
use App\Schedule\Http\Actions\ListSnapshotsAction;
use App\Schedule\Http\Actions\LoadAction;
use App\Schedule\Http\Actions\LoadSnapshotAction;
use App\Schedule\Http\Actions\ReloadPosterAction;
use App\Schedule\Http\Actions\RenameSnapshotAction;
use App\Schedule\Http\Actions\SaveAction;
use App\Schedule\Http\Actions\SaveStaffTagsAction;
use App\Schedule\Http\Actions\SaveVersionAction;
use App\Schedule\Http\JsonResponder;
use App\Schedule\Http\ScheduleController;
use App\Schedule\Infrastructure\SchemaManager;
use App\Schedule\Services\HeatmapBuilder;
use App\Schedule\Services\PeriodBuilder;
use App\Schedule\Repositories\EmployeeRateRepository;
use App\Schedule\Repositories\MetaCache;
use App\Schedule\Repositories\SnapshotRepository;
use App\Schedule\Repositories\StaffTagRepository;
use App\Schedule\Repositories\ZoneRepository;
use App\Schedule\Services\PosterEmployeesProvider;
use App\Schedule\Services\PosterHallsProvider;
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
    // Split OutDataAction into three so the JS can fan out — IMAP
    // ( ~2s ), Poster finance ( ~500ms ) and the DB link query
    // ( ~50ms ) now run concurrently instead of summing.
    \App\Payday3\Http\Actions\OutMailAction::class    => fn($c) => new \App\Payday3\Http\Actions\OutMailAction(
        $c->get(MailServiceInterface::class),
    ),
    \App\Payday3\Http\Actions\OutFinanceAction::class => fn($c) => new \App\Payday3\Http\Actions\OutFinanceAction(
        $c->get(FinanceServiceInterface::class),
    ),
    \App\Payday3\Http\Actions\OutLinksAction::class   => fn($c) => new \App\Payday3\Http\Actions\OutLinksAction(
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
    \App\Payday3\Http\Actions\SepayHideAction::class => fn($c) => new \App\Payday3\Http\Actions\SepayHideAction(
        $c->get(SepayRepositoryInterface::class),
    ),

    // ─── Payday3 balances footer ───────────────────────────────
    ActualBalanceRepositoryInterface::class => fn($c) => new ActualBalanceRepository($c->get(Database::class)),
    ActualBalanceAction::class              => fn($c) => new ActualBalanceAction($c->get(ActualBalanceRepositoryInterface::class)),

    // ─── Payday3 Poster integrations (replaces payday2 fallbacks) ─
    PosterApiProviderInterface::class       => fn()   => new PosterApiProvider(),

    // LocalSettings — DB-backed (payday3_settings.config_json). On the
    // first boot the table is empty so DbLocalSettingsRepository pulls
    // values from the JSON repository (payday3/local_config.json) and
    // writes them in — zero-touch migration for live deployments.
    LocalSettingsRepositoryInterface::class => function ($c) {
        $json = new JsonLocalSettingsRepository(
            primaryPath: dirname(__DIR__, 2) . '/payday3/local_config.json',
        );
        return new DbLocalSettingsRepository($c->get(Database::class), $json);
    },
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
    PosterCheckListAction::class            => fn($c) => new PosterCheckListAction($c->get(PosterCheckServiceInterface::class)),
    PosterBalanceServiceInterface::class    => fn($c) => new PosterBalanceService(
        $c->get(PosterApiProviderInterface::class),
        $c->get(LocalSettingsRepositoryInterface::class),
    ),
    PosterBalanceSnapshotAction::class      => fn($c) => new PosterBalanceSnapshotAction($c->get(PosterBalanceServiceInterface::class)),
    SettingsAction::class                   => fn($c) => new SettingsAction($c->get(LocalSettingsRepositoryInterface::class)),

    // ─── Payday3 IN AJAX-refresh + Финансовые транзакции ──────
    InDataAction::class => fn($c) => new InDataAction($c->get(PageDataAssembler::class)),
    FinanceTransferServiceInterface::class => fn($c) => new FinanceTransferService(
        $c->get(Database::class),
        $c->get(PosterApiProviderInterface::class),
        $c->get(LocalSettingsRepositoryInterface::class),
    ),
    FinanceTransfersAction::class => fn($c) => new FinanceTransfersAction(
        $c->get(FinanceTransferServiceInterface::class),
        $c->get(LocalSettingsRepositoryInterface::class),
    ),
    \App\Payday3\Http\Actions\FinanceTransferCreateAction::class => fn($c) =>
        new \App\Payday3\Http\Actions\FinanceTransferCreateAction(
            $c->get(FinanceTransferServiceInterface::class),
        ),
    BalanceScreenshotAction::class => fn($c) => new BalanceScreenshotAction(
        $c->get(TelegramNotifierInterface::class),
        $c->get(LoggerInterface::class),
    ),

    // ─── Balance UPLD flow (Факт. − Poster → finance.createTransactions)
    BalanceSyncServiceInterface::class => fn($c) => new BalanceSyncService(
        $c->get(PosterApiProviderInterface::class),
        $c->get(LocalSettingsRepositoryInterface::class),
    ),
    BalanceSyncPlanAction::class => fn($c) => new BalanceSyncPlanAction(
        $c->get(BalanceSyncServiceInterface::class),
    ),
    BalanceSyncCommitAction::class => fn($c) => new BalanceSyncCommitAction(
        $c->get(BalanceSyncServiceInterface::class),
    ),

    // ─── "+" create Poster transaction from an OUT-mail row ───
    PosterTransactionCreateServiceInterface::class => fn($c) => new PosterTransactionCreateService(
        $c->get(PosterApiProviderInterface::class),
    ),
    PosterTransactionCreateAction::class => fn($c) => new PosterTransactionCreateAction(
        $c->get(PosterTransactionCreateServiceInterface::class),
    ),

    // ─── /neworder (live Poster menu → create incoming order) ───
    // SOLID separation: one provider per Poster API concern (menu,
    // locations, open checks), one service for writes (orders). All
    // injected via interfaces so future tests can swap fakes in.
    \App\Order\Contracts\PosterMenuProviderInterface::class    => fn($c) =>
        new \App\Order\Services\PosterMenuProvider($c->get(PosterApiProviderInterface::class)),
    \App\Order\Contracts\PosterLocationProviderInterface::class => fn($c) =>
        new \App\Order\Services\PosterLocationProvider($c->get(PosterApiProviderInterface::class)),
    \App\Order\Contracts\OpenChecksProviderInterface::class    => fn($c) =>
        new \App\Order\Services\OpenChecksProvider($c->get(PosterApiProviderInterface::class)),
    \App\Order\Contracts\OrdersServiceInterface::class         => fn($c) =>
        new \App\Order\Services\OrdersService($c->get(PosterApiProviderInterface::class)),

    \App\Order\Http\NewOrderController::class                  => fn()  =>
        new \App\Order\Http\NewOrderController(),
    \App\Order\Http\Actions\MenuAction::class                  => fn($c) =>
        new \App\Order\Http\Actions\MenuAction($c->get(\App\Order\Contracts\PosterMenuProviderInterface::class)),
    \App\Order\Http\Actions\LocationsAction::class             => fn($c) =>
        new \App\Order\Http\Actions\LocationsAction($c->get(\App\Order\Contracts\PosterLocationProviderInterface::class)),
    \App\Order\Http\Actions\OpenChecksAction::class            => fn($c) =>
        new \App\Order\Http\Actions\OpenChecksAction($c->get(\App\Order\Contracts\OpenChecksProviderInterface::class)),
    \App\Order\Http\Actions\OrderCreateAction::class           => fn($c) =>
        new \App\Order\Http\Actions\OrderCreateAction($c->get(\App\Order\Contracts\OrdersServiceInterface::class)),
    \App\Order\Http\Actions\OrderAppendAction::class           => fn($c) =>
        new \App\Order\Http\Actions\OrderAppendAction($c->get(\App\Order\Contracts\OrdersServiceInterface::class)),
    \App\Order\Http\Middleware\CsrfMiddleware::class           => fn()  =>
        new \App\Order\Http\Middleware\CsrfMiddleware(),

    // ─── /onlineorder — public customer delivery checkout ───────
    // Same SOLID layering as /neworder, delivery-specific concerns
    // behind interfaces: geocoding (Google ↔ Nominatim), delivery
    // quoting (Grab live ↔ Maxim ↔ keyless distance tariff ↔ none),
    // courier dispatch, payment QR, Telegram alerts. These closures
    // ARE the provider factories — OnlineOrderConfig decides which
    // implementation backs each interface, swap via .env, zero code.
    \App\OnlineOrder\Infrastructure\OnlineOrderConfig::class => fn() =>
        new \App\OnlineOrder\Infrastructure\OnlineOrderConfig(),

    \App\OnlineOrder\Contracts\GeocoderInterface::class => function ($c) {
        $cfg = $c->get(\App\OnlineOrder\Infrastructure\OnlineOrderConfig::class);
        // Google needs a server-usable key; Nominatim is the keyless
        // fallback that works the day the page ships.
        if ($cfg->geocoderName() === 'google' && $cfg->googleServerKey() !== '') {
            return new \App\OnlineOrder\Services\GoogleGeocoder($c->get(HttpClient::class), $cfg->googleServerKey());
        }
        return new \App\OnlineOrder\Services\NominatimGeocoder($c->get(HttpClient::class));
    },

    // Concrete ride-hailing providers (each implements quote + dispatch).
    \App\OnlineOrder\Services\GrabDeliveryProvider::class => fn($c) =>
        new \App\OnlineOrder\Services\GrabDeliveryProvider(
            $c->get(\App\OnlineOrder\Infrastructure\OnlineOrderConfig::class),
            $c->get(HttpClient::class),
        ),
    \App\OnlineOrder\Services\MaximDeliveryProvider::class => fn($c) =>
        new \App\OnlineOrder\Services\MaximDeliveryProvider(
            $c->get(\App\OnlineOrder\Infrastructure\OnlineOrderConfig::class),
            $c->get(HttpClient::class),
        ),

    \App\OnlineOrder\Contracts\DeliveryQuoteProviderInterface::class => function ($c) {
        $cfg = $c->get(\App\OnlineOrder\Infrastructure\OnlineOrderConfig::class);
        return match ($cfg->deliveryMode()) {
            'live'     => $cfg->deliveryProviderName() === 'maxim'
                ? $c->get(\App\OnlineOrder\Services\MaximDeliveryProvider::class)
                : $c->get(\App\OnlineOrder\Services\GrabDeliveryProvider::class),
            'distance' => new \App\OnlineOrder\Services\DistanceTariffProvider($cfg),
            default    => new \App\OnlineOrder\Services\NullDeliveryProvider($cfg->deliveryProviderName()),
        };
    },

    \App\OnlineOrder\Services\DeliveryQuoteService::class => fn($c) =>
        new \App\OnlineOrder\Services\DeliveryQuoteService(
            $c->get(\App\OnlineOrder\Infrastructure\OnlineOrderConfig::class),
            $c->get(\App\OnlineOrder\Contracts\GeocoderInterface::class),
            $c->get(\App\OnlineOrder\Contracts\DeliveryQuoteProviderInterface::class),
        ),

    \App\OnlineOrder\Contracts\IncomingOrderServiceInterface::class => fn($c) =>
        new \App\OnlineOrder\Services\IncomingOrderService(
            $c->get(PosterApiProviderInterface::class),
            $c->get(\App\OnlineOrder\Infrastructure\OnlineOrderConfig::class),
        ),
    \App\OnlineOrder\Contracts\PaymentQrProviderInterface::class => fn($c) =>
        new \App\OnlineOrder\Services\VietQrPaymentProvider(
            $c->get(\App\OnlineOrder\Infrastructure\OnlineOrderConfig::class),
        ),
    \App\OnlineOrder\Contracts\OrderNotifierInterface::class => fn() =>
        new \App\OnlineOrder\Services\TelegramOrderNotifier(),

    \App\OnlineOrder\Http\OnlineOrderController::class => fn($c) =>
        new \App\OnlineOrder\Http\OnlineOrderController(
            $c->get(\App\OnlineOrder\Infrastructure\OnlineOrderConfig::class),
        ),
    \App\OnlineOrder\Http\Actions\QuoteAction::class => fn($c) =>
        new \App\OnlineOrder\Http\Actions\QuoteAction(
            $c->get(\App\OnlineOrder\Services\DeliveryQuoteService::class),
        ),
    \App\OnlineOrder\Http\Actions\OrderCreateAction::class => function ($c) {
        $cfg = $c->get(\App\OnlineOrder\Infrastructure\OnlineOrderConfig::class);
        // Courier auto-dispatch only exists when a real provider is
        // live — the same object that quoted (Grab/Maxim implement
        // both interfaces); null otherwise.
        $dispatch = null;
        if ($cfg->hasTaxiDispatch()) {
            $provider = $c->get(\App\OnlineOrder\Contracts\DeliveryQuoteProviderInterface::class);
            if ($provider instanceof \App\OnlineOrder\Contracts\TaxiDispatchInterface) {
                $dispatch = $provider;
            }
        }
        return new \App\OnlineOrder\Http\Actions\OrderCreateAction(
            $c->get(\App\Order\Contracts\PosterMenuProviderInterface::class),
            $c->get(\App\OnlineOrder\Services\DeliveryQuoteService::class),
            $c->get(\App\OnlineOrder\Contracts\IncomingOrderServiceInterface::class),
            $c->get(\App\OnlineOrder\Contracts\PaymentQrProviderInterface::class),
            $c->get(\App\OnlineOrder\Contracts\OrderNotifierInterface::class),
            new \App\OnlineOrder\Infrastructure\SubmitThrottle(),
            $cfg,
            $dispatch,
        );
    },

    // ─── /poster-app — POS iframe widget for PIN-learning + shift tracking ───
    // All Domain/Contracts/Repositories/Services/Actions live under
    // src/PosterApp/ — separate namespace, separate tables, isolated
    // from Order/. Auth between widget and backend is a stateless
    // 12h HMAC token (no cookies — iframe-friendly).
    \App\PosterApp\Infrastructure\Schema::class                       => fn($c) =>
        new \App\PosterApp\Infrastructure\Schema($c->get(Database::class)),
    \App\PosterApp\Infrastructure\PosterAppConfig::class              => fn()   =>
        new \App\PosterApp\Infrastructure\PosterAppConfig(),
    \App\PosterApp\Infrastructure\PosterAppToken::class               => fn($c) =>
        new \App\PosterApp\Infrastructure\PosterAppToken(
            $c->get(\App\PosterApp\Infrastructure\PosterAppConfig::class),
        ),
    \App\PosterApp\Contracts\EmployeePinRepositoryInterface::class    => fn($c) =>
        new \App\PosterApp\Repositories\EmployeePinRepository(
            $c->get(Database::class),
            $c->get(\App\PosterApp\Infrastructure\Schema::class),
        ),
    \App\PosterApp\Contracts\WorkShiftRepositoryInterface::class      => fn($c) =>
        new \App\PosterApp\Repositories\WorkShiftRepository(
            $c->get(Database::class),
            $c->get(\App\PosterApp\Infrastructure\Schema::class),
        ),
    \App\PosterApp\Services\PinAuthService::class                     => fn($c) =>
        new \App\PosterApp\Services\PinAuthService(
            $c->get(\App\PosterApp\Contracts\EmployeePinRepositoryInterface::class),
        ),
    \App\PosterApp\Services\WorkShiftService::class                   => fn($c) =>
        new \App\PosterApp\Services\WorkShiftService(
            $c->get(\App\PosterApp\Contracts\WorkShiftRepositoryInterface::class),
        ),
    \App\PosterApp\Http\PosterAppController::class                    => fn($c) =>
        new \App\PosterApp\Http\PosterAppController(
            $c->get(\App\PosterApp\Infrastructure\PosterAppConfig::class),
        ),
    \App\PosterApp\Http\Actions\WidgetLoginAction::class              => fn($c) =>
        new \App\PosterApp\Http\Actions\WidgetLoginAction(
            $c->get(\App\PosterApp\Services\PinAuthService::class),
            $c->get(\App\PosterApp\Services\WorkShiftService::class),
            $c->get(\App\PosterApp\Infrastructure\PosterAppToken::class),
        ),
    \App\PosterApp\Http\Actions\WidgetShiftStartAction::class         => fn($c) =>
        new \App\PosterApp\Http\Actions\WidgetShiftStartAction(
            $c->get(\App\PosterApp\Services\WorkShiftService::class),
            $c->get(\App\PosterApp\Infrastructure\PosterAppToken::class),
        ),
    \App\PosterApp\Http\Actions\WidgetShiftEndAction::class           => fn($c) =>
        new \App\PosterApp\Http\Actions\WidgetShiftEndAction(
            $c->get(\App\PosterApp\Services\WorkShiftService::class),
            $c->get(\App\PosterApp\Infrastructure\PosterAppToken::class),
        ),
    \App\PosterApp\Http\Middleware\PosterOriginMiddleware::class      => fn()   =>
        new \App\PosterApp\Http\Middleware\PosterOriginMiddleware(),

    // ─── Schedule (shift planner) ─────────────────────────────
    // SchemaManager is a singleton — all schedule repos receive it and
    // call ensure() in their constructor. ensure() is gated by a static
    // flag + version stamp in system_meta, so DDL runs at most once per
    // deploy + once per process.
    SchemaManager::class                     => fn($c) => new SchemaManager($c->get(Database::class)),
    MetaCache::class                         => fn($c) => new MetaCache($c->get(Database::class)),
    SnapshotRepositoryInterface::class       => fn($c) => new SnapshotRepository($c->get(Database::class), $c->get(SchemaManager::class)),
    ZoneRepositoryInterface::class           => fn($c) => new ZoneRepository($c->get(Database::class), $c->get(SchemaManager::class)),
    StaffTagRepositoryInterface::class       => fn($c) => new StaffTagRepository($c->get(Database::class), $c->get(SchemaManager::class)),
    // Hourly-rate store shared with the /employees/ page (employee_rates).
    EmployeeRateRepositoryInterface::class   => fn($c) => new EmployeeRateRepository($c->get(Database::class), $c->get(SchemaManager::class)),
    EmployeesProviderInterface::class        => fn($c) => new PosterEmployeesProvider(
        $c->get(StaffTagRepositoryInterface::class),
        $c->get(EmployeeRateRepositoryInterface::class),
        $c->get(MetaCache::class),
        Config::get('POSTER_API_TOKEN', ''),
    ),
    HallsProviderInterface::class            => fn($c) => new PosterHallsProvider(
        $c->get(MetaCache::class),
        Config::get('POSTER_API_TOKEN', ''),
    ),
    // ScheduleController is auto-wired by PHP-DI: each constructor arg
    // (services + 12 action instances) gets resolved by type-hint.
    // No explicit factory — keeps DI graph simple and avoids the
    // ContainerInterface-injection issue that broke prod earlier.

    // ─── Bloggers (referral system) ───────────────────────────
    // Poster I/O + local cashback store behind interfaces so BloggerService
    // stays pure domain logic (unit-tested with fakes). BloggersController
    // auto-wires from BloggerService.
    \App\Bloggers\Contracts\PosterClientsGatewayInterface::class => fn($c) =>
        new \App\Bloggers\Services\PosterClientsGateway($c->get(PosterApiProviderInterface::class)),
    \App\Bloggers\Contracts\BloggerRepositoryInterface::class => fn($c) =>
        new \App\Bloggers\Repositories\BloggerRepository($c->get(Database::class)),
    \App\Bloggers\Services\BloggerService::class => fn($c) =>
        new \App\Bloggers\Services\BloggerService(
            $c->get(\App\Bloggers\Contracts\PosterClientsGatewayInterface::class),
            $c->get(\App\Bloggers\Contracts\BloggerRepositoryInterface::class),
        ),
];
