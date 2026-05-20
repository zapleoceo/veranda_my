<?php

declare(strict_types=1);

use App\Infrastructure\Config;
use App\Infrastructure\Logger;
use App\Infrastructure\Session;
use App\Middleware\AuthMiddleware;
use DI\ContainerBuilder;
use Slim\Factory\AppFactory;

// Bootstrap config and logging before anything else
Config::load(dirname(__DIR__, 2) . '/.env');
Logger::init(Config::get('LOG_LEVEL', 'info'));
// All app code runs in Vietnam local time. Without this, date()/strtotime()
// pick up whatever the host's php.ini default is (typically UTC) and we
// end up sending wrong timestamps to Poster / writing wrong "now" into
// the DB. Legacy payday2 controllers do this per-class; doing it once
// here at the top of bootstrap removes the duplication and the risk of
// missing a class.
date_default_timezone_set(Config::get('APP_TIMEZONE', 'Asia/Ho_Chi_Minh'));
// Set session params (30-day cookie, HttpOnly, Secure-on-HTTPS,
// SameSite=Lax) before ANY session_start call lands. AuthMiddleware
// and individual controllers call Session::start() which honours
// these. Operators stay logged in for a month of activity.
Session::configure();

// Build DI container
$containerBuilder = new ContainerBuilder();
$containerBuilder->addDefinitions(require __DIR__ . '/container.php');
$container = $containerBuilder->build();

AppFactory::setContainer($container);
$app = AppFactory::create();

// Middleware (outermost first = executes last)
$app->addRoutingMiddleware();
$app->addBodyParsingMiddleware();

$errorMiddleware = $app->addErrorMiddleware(
    displayErrorDetails: Config::get('APP_ENV', 'production') === 'development',
    logErrors: true,
    logErrorDetails: true,
    logger: Logger::get()
);

// Routes
require __DIR__ . '/routes.php';

return $app;
