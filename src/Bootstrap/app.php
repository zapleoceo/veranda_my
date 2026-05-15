<?php

declare(strict_types=1);

use App\Infrastructure\Config;
use App\Infrastructure\Logger;
use App\Middleware\AuthMiddleware;
use DI\ContainerBuilder;
use Slim\Factory\AppFactory;

// Bootstrap config and logging before anything else
Config::load(dirname(__DIR__, 2) . '/.env');
Logger::init(Config::get('LOG_LEVEL', 'info'));

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
