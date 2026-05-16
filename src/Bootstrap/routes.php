<?php

declare(strict_types=1);

use App\Controllers\Auth\LoginController;
use App\Controllers\Auth\CallbackController;
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
use App\Controllers\MenuPublicController;
use App\Controllers\Tr3Controller;
use App\Controllers\ReservationsController;
use App\Middleware\AuthMiddleware;
use App\Middleware\WebhookSecretMiddleware;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

/** @var App $app */

// Auth
$app->get('/login', [LoginController::class, 'show']);
$app->post('/login', [LoginController::class, 'handle']);
$app->get('/auth/callback', [CallbackController::class, 'handle']);
$app->get('/logout', [LoginController::class, 'logout']);

// Telegram webhook (Telegram bot POSTs here; WA bridge uses GET/POST with ?wa_event=)
$app->map(['GET', 'POST'], '/telegram_webhook', [WebhookController::class, 'handle'])
    ->add(WebhookSecretMiddleware::class);

// Admin panel (protected by session auth)
$app->group('/admin', function (RouteCollectorProxy $group) {
    $group->get('', [DashboardController::class, 'index']);
    $group->get('/sync', [SyncController::class, 'index']);
    $group->post('/sync/start', [SyncController::class, 'start']);
    $group->get('/telegram', [TelegramAdminController::class, 'index']);
    $group->get('/menu', [MenuController::class, 'index']);
    $group->get('/access', [AccessController::class, 'index']);
    $group->post('/access', [AccessController::class, 'save']);
    $group->get('/reservations', [ReservationsAdminController::class, 'index']);
    $group->get('/logs', [LogsController::class, 'index']);
})->add(AuthMiddleware::class);

// Phase 4: staff-facing modules (auth-protected)
$app->map(['GET', 'POST'], '/kitchen_online', [KitchenOnlineController::class, 'index'])
    ->add(AuthMiddleware::class);
$app->map(['GET', 'POST'], '/rawdata', [RawdataController::class, 'index'])
    ->add(AuthMiddleware::class);

// Phase 4: public menu
$app->get('/links/menu', [MenuPublicController::class, 'show']);
$app->get('/menu', [MenuPublicController::class, 'show']);

// Phase 4: tr3 (public booking widget)
$app->get('/tr3', [Tr3Controller::class, 'index']);
$app->map(['GET', 'POST'], '/tr3/api', [Tr3Controller::class, 'api']);

// Phase 4: reservations (auth-protected)
$app->map(['GET', 'POST'], '/reservations', [ReservationsController::class, 'index'])
    ->add(AuthMiddleware::class);
