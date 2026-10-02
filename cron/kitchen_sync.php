<?php

declare(strict_types=1);

// Только из консоли. Каталоги scripts/ и cron/ лежат внутри docroot и
// отдаются nginx'ом напрямую (проверено: GET /scripts/kitchen/cron.php
// возвращает 500, то есть файл ИСПОЛНЯЕТСЯ, а не отдаётся как текст).
// Без этой проверки любой мог по обычной ссылке запустить ресинк Poster,
// перезапись kitchen_stats, удаление сообщений или рассылку персоналу.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Forbidden: скрипт запускается только из консоли.\n");
}

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

// Диагностика молчаливых смертей. 26.09 с 15:05 до 17:00 UTC 22 прогона подряд
// не оставили в журнале ни строки, а в cron.log — только «Killed»: процесс убит
// снаружи (без лимита памяти у CLI его, вероятнее всего, снял OOM-killer).
// Потолок превращает «распухание» в обычную фатальную ошибку, а обработчик ниже
// пишет её в журнал вместе с пиком памяти. Резерв освобождается, чтобы на запись
// хватило памяти даже после «Allowed memory size exhausted».
ini_set('memory_limit', '1024M');
$kitchenSyncFinished = false;
$kitchenSyncReserve  = str_repeat(' ', 1024 * 1024);
Logger::get()->info('kitchen_sync.start', ['pid' => getmypid()]);
register_shutdown_function(static function () use (&$kitchenSyncFinished, &$kitchenSyncReserve): void {
    $kitchenSyncReserve = null;
    $peakMb = round(memory_get_peak_usage(true) / 1048576, 1);
    $error  = error_get_last();
    if (!$kitchenSyncFinished || ($error !== null && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR], true))) {
        Logger::get()->error('kitchen_sync.died', [
            'peak_mb' => $peakMb,
            'error'   => $error['message'] ?? null,
            'file'    => $error['file'] ?? null,
            'line'    => $error['line'] ?? null,
        ]);
    } elseif ($peakMb > 256) {
        Logger::get()->warning('kitchen_sync.high_memory', ['peak_mb' => $peakMb]);
    }
});

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
    $kitchenSyncFinished = true;

} catch (\Throwable $e) {
    Logger::get()->error('kitchen_sync.fatal', [
        'error' => $e->getMessage(),
        'file'  => $e->getFile(),
        'line'  => $e->getLine(),
    ]);
    echo '[' . date('Y-m-d H:i:s') . '] ERROR: ' . $e->getMessage() . "\n";
    exit(1);
}
