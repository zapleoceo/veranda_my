<?php

declare(strict_types=1);

// Только из консоли: cron/ лежит внутри docroot и исполняется nginx'ом.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Forbidden: скрипт запускается только из консоли.\n");
}

require_once __DIR__ . '/../vendor/autoload.php';

use App\AiBot\OfficePollerInterface;
use App\Infrastructure\Config;
use App\Infrastructure\Logger;

Config::load(__DIR__ . '/../.env');
Logger::init(Config::get('LOG_LEVEL', 'info'));

// Наложение запусков: неблокирующий flock, занято - тихо выходим.
$lockFile = Config::privateDir('locks') . '/office_poll.lock';
$lockFh = @fopen($lockFile, 'c');
if ($lockFh === false) {
    $lockFh = fopen(sys_get_temp_dir() . '/veranda_office_poll.lock', 'c');
}
if ($lockFh === false || !flock($lockFh, LOCK_EX | LOCK_NB)) {
    exit(0);
}

// Без OFFICE_API_URL и OFFICE_SERVICE_TOKEN контейнер отдаёт NullOfficePoller: ни БД, ни сети.
$builder = new DI\ContainerBuilder();
$builder->addDefinitions(require __DIR__ . '/../src/Bootstrap/container.php');
$stats = $builder->build()->get(OfficePollerInterface::class)->run();
Logger::get()->info('office_poll.done', $stats);
