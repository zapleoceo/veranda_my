<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Infrastructure\Config;
use App\Infrastructure\Database;
use App\Infrastructure\HttpClient;
use App\Infrastructure\Logger;
use App\Infrastructure\PosterApiClient;
use App\Repositories\MetaRepository;
use App\Services\KitchenSyncService;

Config::load(__DIR__ . '/../.env');
Logger::init(Config::get('LOG_LEVEL', 'info'));

$spotTzName = Config::get('POSTER_SPOT_TIMEZONE', 'Asia/Ho_Chi_Minh');
$apiTzName  = Config::get('POSTER_API_TIMEZONE') ?: $spotTzName;
date_default_timezone_set($apiTzName);

try {
    $db     = Database::getInstance();
    $http   = new HttpClient(timeoutSeconds: 15);
    $poster = new PosterApiClient(Config::require('POSTER_API_TOKEN'), $http);
    $meta   = new MetaRepository($db);
    $spotTz = new \DateTimeZone($spotTzName);

    (new KitchenSyncService($db, $poster, $meta, $spotTz))->run();

} catch (\Throwable $e) {
    Logger::get()->error('kitchen_sync.fatal', [
        'error' => $e->getMessage(),
        'file'  => $e->getFile(),
        'line'  => $e->getLine(),
    ]);
    echo '[' . date('Y-m-d H:i:s') . '] ERROR: ' . $e->getMessage() . "\n";
    exit(1);
}
