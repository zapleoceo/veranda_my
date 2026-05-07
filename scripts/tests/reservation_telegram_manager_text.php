<?php
declare(strict_types=1);

require_once __DIR__ . '/../../reservations/ReservationTelegram.php';

use App\Classes\ReservationTelegram;

$payload = [
    'qr_code' => 'TEST123',
    'start_time' => '2026-05-07 19:00:00',
    'duration' => 120,
    'guests' => 2,
    'table_num' => 'Room',
    'poster_table_id' => 777,
    'name' => 'Test',
    'phone' => '+10000000000',
];

$text = ReservationTelegram::buildManagerText($payload);

if (strpos($text, '777') === false) {
    fwrite(STDERR, "FAIL: manager text must include poster table_id\n");
    exit(1);
}
if (strpos($text, 'Номер стола') !== false) {
    fwrite(STDERR, "FAIL: manager text should not use table_num label\n");
    exit(1);
}

fwrite(STDOUT, "OK\n");

