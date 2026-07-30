<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure;

use App\Infrastructure\Database;
use PHPUnit\Framework\TestCase;

/**
 * Единые часы приложения и БД.
 *
 * На проде было три разных времени одновременно: PHP приложения — Вьетнам
 * (+07:00), MySQL с time_zone=SYSTEM — киевское (+03:00, сервер в EEST),
 * PHP CLI без явной установки — UTC. В одни и те же таблицы часть меток
 * писал PHP через date(), часть — MySQL через DEFAULT CURRENT_TIMESTAMP,
 * и они расходились на 4 часа.
 *
 * Хуже всего вело себя CURDATE(): до 04:00 по Нячангу оно возвращало
 * ВЧЕРАШНЮЮ дату, а ресторан работает за полночь — то есть ночные операции
 * попадали в предыдущий день.
 *
 * Теперь сессия MySQL переводится на смещение заведения при подключении.
 */
final class SpotTimeZoneTest extends TestCase
{
    public function test_offset_matches_vietnam(): void
    {
        $this->assertSame('+07:00', Database::spotTimeZoneOffset());
    }

    /** Формат обязан быть пригоден для SET time_zone = '...'. */
    public function test_offset_has_mysql_format(): void
    {
        $this->assertMatchesRegularExpression(
            '/^[+-]\d{2}:\d{2}$/',
            Database::spotTimeZoneOffset()
        );
    }

    /**
     * Числовое смещение, а не 'Asia/Ho_Chi_Minh': именованные зоны требуют
     * загруженных таблиц mysql.time_zone_name, которых на хостинге нет.
     */
    public function test_offset_is_numeric_not_named_zone(): void
    {
        $this->assertStringNotContainsString('/', Database::spotTimeZoneOffset());
    }

    /**
     * Ключевое свойство: смещение совпадает с тем, в котором работает PHP.
     * Если эти двое разъедутся, вернётся исходный баг.
     */
    public function test_offset_equals_php_spot_timezone_offset(): void
    {
        $phpOffset = (new \DateTimeZone('Asia/Ho_Chi_Minh'))
            ->getOffset(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));

        $expected = sprintf('+%02d:%02d', intdiv($phpOffset, 3600), intdiv($phpOffset % 3600, 60));

        $this->assertSame($expected, Database::spotTimeZoneOffset());
    }

    /** Вьетнам не переходит на летнее время — смещение стабильно круглый год. */
    public function test_offset_is_stable_across_seasons(): void
    {
        $tz     = new \DateTimeZone('Asia/Ho_Chi_Minh');
        $winter = $tz->getOffset(new \DateTimeImmutable('2026-01-15', new \DateTimeZone('UTC')));
        $summer = $tz->getOffset(new \DateTimeImmutable('2026-07-15', new \DateTimeZone('UTC')));

        $this->assertSame($winter, $summer, 'появился переход на летнее время — фиксированное смещение больше не годится');
    }
}
