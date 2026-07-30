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

require_once __DIR__ . '/../../reservations/ReservationTelegram.php';

use App\Classes\ReservationTelegram;

$payload = [
    'qr_code' => 'TEST123',
    'start_time' => '2026-05-07 19:00:00',
    'duration' => 120,
    'guests' => 2,
    'table_num' => 'Room',
    'poster_table_id' => 777,
    'hall_name' => 'Veranda Hall',
    'name' => 'Test',
    'phone' => '+10000000000',
];

$text = ReservationTelegram::buildManagerText($payload);

if (strpos($text, 'Veranda Hall') === false || strpos($text, '777') === false) {
    fwrite(STDERR, "FAIL: manager text must include hall_name and poster table_id\n");
    exit(1);
}
if (strpos($text, 'Номер стола') !== false) {
    fwrite(STDERR, "FAIL: manager text should not use table_num label\n");
    exit(1);
}

fwrite(STDOUT, "OK\n");
