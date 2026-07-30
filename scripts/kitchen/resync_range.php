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

/**
 * Пересинк кухонной статистики за диапазон дат.
 *
 * Использование: php resync_range.php [ГГГГ-ММ-ДД] [ГГГГ-ММ-ДД] [job_id]
 *
 * Раньше здесь лежала СВОЯ копия шагов синхронизации (computeProbCloseAt,
 * autoExclude, обновление close-метаданных) — вторая из трёх в проекте.
 * Копии успели разъехаться: ключи результата отличались ('hookah' против
 * 'set_hookah'), категория кальяна была захардкожена вместо константы, а
 * шага _reconcileOpenStatus (добор реальных статусов из Poster) тут не было
 * вовсе. Из-за этого цифры после ручного пересинка отличались от цифр после
 * ночного крона — молча, без единой ошибки.
 *
 * Теперь скрипт — тонкая обёртка над KitchenSyncService::runForDate():
 * один и тот же код и для крона, и для пересинка.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Infrastructure\Config;
use App\Infrastructure\Database;
use App\Infrastructure\HttpClient;
use App\Infrastructure\Logger;
use App\Infrastructure\PosterApiClient;
use App\Repositories\MetaRepository;
use App\Services\KitchenSyncService;

Config::load(__DIR__ . '/../../.env');
Logger::init(Config::get('LOG_LEVEL', 'info'));

$spotTzName = Config::get('POSTER_SPOT_TIMEZONE', 'Asia/Ho_Chi_Minh');
$apiTzName  = Config::get('POSTER_API_TIMEZONE') ?: $spotTzName;
date_default_timezone_set($apiTzName);

$from  = $argv[1] ?? date('Y-m-01');
$to    = $argv[2] ?? date('Y-m-d');
$jobId = (string) ($argv[3] ?? '');

$fromTs = strtotime($from);
$toTs   = strtotime($to);
if ($fromTs === false || $toTs === false || $fromTs > $toTs) {
    fwrite(STDERR, "Некорректный диапазон: ожидается ГГГГ-ММ-ДД ГГГГ-ММ-ДД\n");
    exit(2);
}

$db      = Database::getInstance();
$http    = new HttpClient(timeoutSeconds: 15);
$poster  = new PosterApiClient(Config::require('POSTER_API_TOKEN'), $http);
$meta    = new MetaRepository($db);
$service = new KitchenSyncService($db, $poster, $meta, new DateTimeZone($spotTzName));

$totalDays = 0;
for ($t = $fromTs; $t <= $toTs; $t = strtotime('+1 day', $t)) {
    $totalDays++;
}

/** Прогресс для панели /admin/sync. Без job_id его никто не читает. */
$setJobMeta = static function (array $pairs) use ($meta, $jobId): void {
    if ($jobId === '') {
        return;
    }
    try {
        $meta->setMany($pairs);
    } catch (\Throwable) {
        // Прогресс вспомогательный — ронять из-за него пересинк нельзя.
    }
};

$setJobMeta([
    'kitchen_resync_job_id'             => $jobId,
    'kitchen_resync_job_from'           => $from,
    'kitchen_resync_job_to'             => $to,
    'kitchen_resync_job_status'         => 'running',
    'kitchen_resync_job_started_at'     => date('Y-m-d H:i:s'),
    'kitchen_resync_job_last_update_at' => date('Y-m-d H:i:s'),
]);

$index = 0;
for ($t = $fromTs; $t <= $toTs; $t = strtotime('+1 day', $t)) {
    $date = date('Y-m-d', $t);
    $index++;

    echo '[' . date('Y-m-d H:i:s') . "] Синхронизирую {$date} ({$index}/{$totalDays})...\n";
    $setJobMeta([
        'kitchen_resync_job_last_date'      => $date,
        'kitchen_resync_job_progress'       => $index . '/' . $totalDays,
        'kitchen_resync_job_last_update_at' => date('Y-m-d H:i:s'),
    ]);

    try {
        $service->runForDate($date);
        echo "  готово\n";
    } catch (\Throwable $e) {
        echo '  ОШИБКА: ' . $e->getMessage() . "\n";
        $setJobMeta([
            'kitchen_resync_job_status'         => 'error',
            'kitchen_resync_job_error'          => $e->getMessage(),
            'kitchen_resync_job_last_update_at' => date('Y-m-d H:i:s'),
        ]);
        exit(2);
    }
}

echo '[' . date('Y-m-d H:i:s') . "] ГОТОВО\n";
$setJobMeta([
    'kitchen_resync_job_status'         => 'done',
    'kitchen_resync_job_done_at'        => date('Y-m-d H:i:s'),
    'kitchen_resync_job_last_update_at' => date('Y-m-d H:i:s'),
]);
