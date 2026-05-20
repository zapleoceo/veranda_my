<?php

declare(strict_types=1);

use App\Controllers\Auth\LoginController;
use App\Controllers\Auth\CallbackController;
use App\Controllers\Payday2Controller;
use App\Payday3\Http\Payday3Controller;
use App\Payday3\Http\Actions\LinksAction;
use App\Payday3\Http\Actions\AutoLinkAction;
use App\Payday3\Http\Actions\ManualLinkAction;
use App\Payday3\Http\Actions\UnlinkAction;
use App\Payday3\Http\Actions\ClearLinksAction;
use App\Payday3\Http\Actions\ClearDayAction;
use App\Payday3\Http\Actions\SepaySyncAction;
use App\Payday3\Http\Actions\PosterSyncAction;
use App\Payday3\Http\Actions\OutDataAction;
use App\Payday3\Http\Actions\OutAutoLinkAction;
use App\Payday3\Http\Actions\OutManualLinkAction;
use App\Payday3\Http\Actions\OutUnlinkAction;
use App\Payday3\Http\Actions\OutClearLinksAction;
use App\Payday3\Http\Actions\MailHideAction;
use App\Payday3\Http\Actions\ActualBalanceAction;
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
use App\Payday3\Http\Actions\PosterTransactionCreateAction;
use App\Controllers\StaticController;
use App\Controllers\WebhookController;
use App\Controllers\Admin\DashboardController;
use App\Controllers\Admin\SyncController;
use App\Controllers\Admin\TelegramAdminController;
use App\Controllers\Admin\MenuController;
use App\Controllers\Admin\AccessController;
use App\Controllers\Admin\ReservationsAdminController;
use App\Controllers\Admin\LogsController;
use App\Controllers\KitchenOnlineController;
use App\Controllers\RawdataController;
use App\Controllers\LinksController;
use App\Controllers\BanyaController;
use App\Controllers\RomaController;
use App\Controllers\ZaparaController;
use App\Controllers\EmployeesController;
use App\Schedule\Http\ScheduleController;
use App\Controllers\MenuPublicController;
use App\Controllers\Tr3Controller;
use App\Controllers\ReservationsController;
use App\Middleware\AuthMiddleware;
use App\Middleware\WebhookSecretMiddleware;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

/** @var App $app */

// Root: redirect based on auth state
$app->get('/', function ($req, $res) {
    $location = !empty($_SESSION['user_email']) ? '/admin' : '/links';
    return $res->withHeader('Location', $location)->withStatus(302);
});

// Auth
$app->get('/login', [LoginController::class, 'show']);
$app->post('/login', [LoginController::class, 'handle']);
$app->get('/auth/callback', [CallbackController::class, 'handle']);
$app->get('/logout', [LoginController::class, 'logout']);

// Legacy URL aliases — the .php files were deleted; nginx try_files falls
// through to public/index.php (Slim) which catches these. Google OAuth is
// still configured with GOOGLE_REDIRECT_URI=/auth_callback.php in .env, so
// the callback alias is a hard requirement until the Google Cloud Console
// redirect URI is updated.
$app->get('/login.php',         [LoginController::class, 'show']);
$app->get('/logout.php',        [LoginController::class, 'logout']);
$app->get('/auth_callback.php', [CallbackController::class, 'handle']);

// Legacy /dashboard, /dashboard/, /dashboard.php → /admin (DashboardController
// inside the Admin group renders the same kitchen-stats chart via layout.php
// with sidebar). The ?resync=1 launcher that used to live in dashboard/index.php
// is reachable at /rawdata?resync=1 (RawdataController already owns it).
$app->get('/dashboard',     function ($req, $res) { return $res->withHeader('Location', '/admin')->withStatus(302); });
$app->get('/dashboard/',    function ($req, $res) { return $res->withHeader('Location', '/admin')->withStatus(302); });
$app->get('/dashboard.php', function ($req, $res) { return $res->withHeader('Location', '/admin')->withStatus(302); });

// Telegram webhook (Telegram bot POSTs here; WA bridge uses GET/POST with ?wa_event=).
// .php alias keeps any legacy bot registrations working — nginx serves the
// /telegram_webhook.php shim at the document root, which delegates to Slim,
// and the second route here catches the rewritten request.
$app->map(['GET', 'POST'], '/telegram_webhook', [WebhookController::class, 'handle'])
    ->add(WebhookSecretMiddleware::class);
$app->map(['GET', 'POST'], '/telegram_webhook.php', [WebhookController::class, 'handle'])
    ->add(WebhookSecretMiddleware::class);

// Admin panel (protected by session auth).
// Both /admin and /admin/ render the dashboard. We cannot 301 /admin/ → /admin
// because Apache mod_dir auto-redirects /admin → /admin/ (admin/ is a real
// directory), producing a redirect loop. Instead make both URLs alias the
// same controller.
$app->get('/admin/', [DashboardController::class, 'index'])
    ->add(AuthMiddleware::class);
$app->group('/admin', function (RouteCollectorProxy $group) {
    $group->get('', [DashboardController::class, 'index']);
    $group->map(['GET', 'POST'], '/sync', [SyncController::class, 'index']);
    $group->post('/sync/start', [SyncController::class, 'start']);
    $group->map(['GET', 'POST'], '/telegram', [TelegramAdminController::class, 'index']);
    $group->map(['GET', 'POST'], '/menu', [MenuController::class, 'index']);
    $group->map(['GET', 'POST'], '/access', [AccessController::class, 'index']);
    $group->map(['GET', 'POST'], '/reservations', [ReservationsAdminController::class, 'index']);
    $group->map(['GET', 'POST'], '/logs', [LogsController::class, 'index']);
})->add(AuthMiddleware::class);

// Phase 4: staff-facing modules (auth-protected). Patterns accept the
// trailing slash variant ([/]) too so direct .htaccess hits delegated
// through <module>/index.php → public/index.php resolve here and render
// via layout.php (with sidebar) instead of legacy view.php standalone.
$app->map(['GET', 'POST'], '/kitchen_online[/]', [KitchenOnlineController::class, 'index'])
    ->add(AuthMiddleware::class);
$app->map(['GET', 'POST'], '/rawdata[/]', [RawdataController::class, 'index'])
    ->add(AuthMiddleware::class);
$app->map(['GET', 'POST'], '/banya[/]', [BanyaController::class, 'index'])
    ->add(AuthMiddleware::class);
$app->map(['GET', 'POST'], '/roma[/]', [RomaController::class, 'index'])
    ->add(AuthMiddleware::class);
$app->map(['GET', 'POST'], '/zapara[/]', [ZaparaController::class, 'index'])
    ->add(AuthMiddleware::class);
$app->map(['GET', 'POST'], '/employees[/]', [EmployeesController::class, 'index'])
    ->add(AuthMiddleware::class);
// Public read-only view of a named version — no auth (anyone with the
// share-code URL can view). MUST be declared BEFORE the auth-protected
// /schedule route so Slim matches the specific path first.
$app->get('/schedule/v/{code:[A-Za-z0-9_-]+}', [ScheduleController::class, 'publicVersion']);

$app->map(['GET', 'POST'], '/schedule[/]', [ScheduleController::class, 'index'])
    ->add(AuthMiddleware::class);

// Phase 4: public links landing + menu
$app->get('/links', [LinksController::class, 'index']);
$app->get('/links/', function ($req, $res) { return $res->withHeader('Location', '/links')->withStatus(301); });
$app->get('/links/menu', [MenuPublicController::class, 'show']);
$app->get('/links/menu.php', [MenuPublicController::class, 'show']);
$app->get('/menu', [MenuPublicController::class, 'show']);

// Phase 4: tr3 (public booking widget)
$app->get('/tr3/', function ($req, $res) { return $res->withHeader('Location', '/tr3')->withStatus(301); });
$app->get('/tr3', [Tr3Controller::class, 'index']);
$app->map(['GET', 'POST'], '/tr3/api',     [Tr3Controller::class, 'api']);
$app->map(['GET', 'POST'], '/tr3/api.php', [Tr3Controller::class, 'api']);

// Phase 4: reservations (auth-protected)
$app->map(['GET', 'POST'], '/reservations[/]', [ReservationsController::class, 'index'])
    ->add(AuthMiddleware::class);

// Phase 5: payday2 (auth-protected). Pattern accepts /payday2 and /payday2/
// so direct .htaccess hits delegated through payday2/index.php → public/index.php
// resolve to this controller (which renders through layout.php with sidebar).
$app->map(['GET', 'POST'], '/payday2[/]', [Payday2Controller::class, 'dispatch'])
    ->add(AuthMiddleware::class);

// Phase 6: payday3 — clean Slim-native rebuild of payday2 (SOLID/DRY).
// Browser-facing render + REST API under /payday3/api/*. Each AJAX endpoint
// is a single-action class (one responsibility per file).
$app->group('/payday3', function (RouteCollectorProxy $g) {
    $g->get('[/]', [Payday3Controller::class, 'index']);
    $g->group('/api', function (RouteCollectorProxy $api) {
        $api->get(   '/links',                                       LinksAction::class);
        $api->post(  '/links/auto',                                  AutoLinkAction::class);
        $api->post(  '/links/manual',                                ManualLinkAction::class);
        $api->post(  '/links/clear',                                 ClearLinksAction::class);
        $api->delete('/links/{sepayId:[0-9]+}/{posterId:[0-9]+}',    UnlinkAction::class);
        $api->post(  '/day/clear',                                   ClearDayAction::class);
        $api->post(  '/sepay/sync',                                  SepaySyncAction::class);
        $api->post(  '/poster/sync',                                 PosterSyncAction::class);
        // OUT-direction reconciliation (BIDV mail ↔ Poster finance).
        // /out/data kept for back-compat (single bundled response);
        // /out/mail + /out/finance + /out/links are the new
        // fan-out endpoints the front-end calls in parallel.
        $api->get(   '/out/data',                                    OutDataAction::class);
        $api->get(   '/out/mail',                                    \App\Payday3\Http\Actions\OutMailAction::class);
        $api->get(   '/out/finance',                                 \App\Payday3\Http\Actions\OutFinanceAction::class);
        $api->get(   '/out/links',                                   \App\Payday3\Http\Actions\OutLinksAction::class);
        $api->post(  '/out/links/auto',                              OutAutoLinkAction::class);
        $api->post(  '/out/links/manual',                            OutManualLinkAction::class);
        $api->post(  '/out/links/clear',                             OutClearLinksAction::class);
        $api->delete('/out/links/{mailUid:[0-9]+}/{financeId:[0-9]+}', OutUnlinkAction::class);
        $api->post(  '/out/mail/hide',                               MailHideAction::class);
        // Actual balances — Phase 8 footer card.
        $api->get(   '/balances',                                    ActualBalanceAction::class);
        $api->post(  '/balances',                                    ActualBalanceAction::class);
        // Send a screenshot of the balance card to Telegram (sendPhoto).
        $api->post(  '/balances/telegram',                           BalanceScreenshotAction::class);
        // UPLD: push Факт.−Poster delta as a finance.createTransactions
        // correction. Two-step: plan returns a nonce + preview, commit
        // validates the nonce (<5 min TTL) and fires the API call.
        $api->post(  '/balances/sync/plan',                          BalanceSyncPlanAction::class);
        $api->post(  '/balances/sync/commit',                        BalanceSyncCommitAction::class);
        // Poster integrations — replaces /payday2/?ajax=kashshift/supplies/poster_check_*
        $api->get(   '/poster/cashshifts',                           PosterCashShiftListAction::class);
        $api->get(   '/poster/cashshifts/{shiftId:[A-Za-z0-9_-]+}',  PosterCashShiftDetailAction::class);
        $api->get(   '/poster/supplies',                             PosterSuppliesListAction::class);
        $api->post(  '/poster/supplies/account',                     PosterSupplyChangeAccountAction::class);
        $api->get(   '/poster/checks',                               PosterCheckListAction::class);
        $api->get(   '/poster/checks/find',                          PosterCheckFindAction::class);
        $api->delete('/poster/checks/{id:[0-9]+}',                   PosterCheckRemoveAction::class);
        // Итоговый баланс — Poster live snapshot for the 3 configured accounts.
        $api->get(   '/poster/balances',                             PosterBalanceSnapshotAction::class);
        // Lookups for dropdowns (employees, finance accounts/categories).
        $api->get(   '/poster/employees',                            PosterEmployeesAction::class);
        $api->get(   '/poster/finance/accounts',                     PosterFinanceAccountsAction::class);
        $api->get(   '/poster/finance/categories',                   PosterFinanceCategoriesAction::class);
        // "+" button on OUT-mail rows → create a Poster finance tx
        // (income / expense / transfer). Direct port of payday2's
        // ?ajax=create_poster_transaction.
        $api->post(  '/poster/finance/transactions',                 PosterTransactionCreateAction::class);
        // Settings (replaces /payday2/?ajax=save_local_config).
        $api->get(   '/settings',                                    SettingsAction::class);
        $api->post(  '/settings',                                    SettingsAction::class);
        // Full IN-mode snapshot for client-side re-render (after sync / clearDay).
        $api->get(   '/data',                                        InDataAction::class);
        // Финансовые транзакции (Vietnam + Tips) card.
        $api->get(   '/finance/transfers',                           FinanceTransfersAction::class);
        $api->post(  '/finance/transfers/create',                    \App\Payday3\Http\Actions\FinanceTransferCreateAction::class);
    });
})->add(AuthMiddleware::class);

// Static assets from directories outside public/
$app->get('/assets/{file:.+}',              [StaticController::class, 'globalAssets']);
$app->get('/tr3/assets/{file:.+}',          [StaticController::class, 'tr3Assets']);
$app->get('/links/{file:.+}',               [StaticController::class, 'linksStatic']);
$app->get('/reservations/assets/{file:.+}', [StaticController::class, 'reservationsAssets']);
$app->get('/reservations/{file:[\w.-]+}',   [StaticController::class, 'reservationsRoot']);
$app->get('/payday2/assets/{file:.+}',      [StaticController::class, 'payday2Assets']);
$app->get('/payday3/assets/{file:.+}',      [StaticController::class, 'payday3Assets']);
$app->get('/schedule/assets/{file:.+}',     [StaticController::class, 'scheduleAssets']);
$app->get('/banya/{file:[\w.-]+}',          [StaticController::class, 'banyaStatic']);
$app->get('/roma/{file:[\w.-]+}',           [StaticController::class, 'romaStatic']);
$app->get('/employees/{file:[\w.-]+}',      [StaticController::class, 'employeesStatic']);
