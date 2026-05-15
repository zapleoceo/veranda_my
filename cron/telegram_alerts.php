<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Infrastructure\Config;
use App\Infrastructure\Database;
use App\Infrastructure\HttpClient;
use App\Infrastructure\Logger;
use App\Infrastructure\TelegramBotClient;
use App\Repositories\AlertItemRepository;
use App\Repositories\MetaRepository;
use App\Services\ReservationReminderService;
use App\Services\TelegramAlertService;

Config::load(__DIR__ . '/../.env');
Logger::init(Config::get('LOG_LEVEL', 'info'));

$tz = Config::get('POSTER_API_TIMEZONE') ?: Config::get('POSTER_SPOT_TIMEZONE', 'Asia/Ho_Chi_Minh');
date_default_timezone_set($tz);

try {
    $db       = Database::getInstance();
    $http     = new HttpClient(timeoutSeconds: 10);
    $bot      = new TelegramBotClient(
        token:  Config::require('TELEGRAM_BOT_TOKEN'),
        http:   $http,
        chatId: Config::require('TELEGRAM_CHAT_ID')
    );
    $threadId = Config::int('TELEGRAM_THREAD_ID') ?: null;
    $meta     = new MetaRepository($db);
    $items    = new AlertItemRepository($db);

    (new ReservationReminderService($db, $bot, $http))->run();

    (new TelegramAlertService($db, $bot, $meta, $items, $threadId))->run();

} catch (\Throwable $e) {
    Logger::get()->error('telegram_alerts.fatal', [
        'error' => $e->getMessage(),
        'file'  => $e->getFile(),
        'line'  => $e->getLine(),
    ]);
    echo '[' . date('Y-m-d H:i:s') . '] ERROR: ' . $e->getMessage() . "\n";
    exit(1);
}
