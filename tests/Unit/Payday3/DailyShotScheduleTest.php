<?php

declare(strict_types=1);

namespace Tests\Unit\Payday3;

use App\Payday3\Services\BalanceShotStore;
use PHPUnit\Framework\TestCase;

/**
 * Ежедневный снимок: cron запускается каждый час, отправка — только в
 * DAILY_HOUR по Вьетнаму. Проверяем момент времени, а не часовой пояс сервера.
 */
final class DailyShotScheduleTest extends TestCase
{
    public function test_eight_am_in_vietnam_is_due(): void
    {
        // 08:00 ICT = 01:00 UTC
        $this->assertTrue(BalanceShotStore::isDailySendHour(new \DateTimeImmutable('2026-11-27T01:00:00+00:00')));
    }

    public function test_minutes_inside_the_hour_are_still_due(): void
    {
        $this->assertTrue(BalanceShotStore::isDailySendHour(new \DateTimeImmutable('2026-11-27T01:45:00+00:00')));
    }

    public function test_neighbouring_hours_are_not_due(): void
    {
        $this->assertFalse(BalanceShotStore::isDailySendHour(new \DateTimeImmutable('2026-11-27T00:59:59+00:00')), '07:59 ICT');
        $this->assertFalse(BalanceShotStore::isDailySendHour(new \DateTimeImmutable('2026-11-27T02:00:00+00:00')), '09:00 ICT');
    }

    public function test_server_timezone_does_not_change_the_answer(): void
    {
        // Один и тот же момент, объект в часовом поясе сервера (Kyiv, летом UTC+3):
        // 08:00 ICT = 01:00 UTC = 04:00 Kyiv (EEST) — отправка должна быть одна и та же.
        $kyiv = new \DateTimeImmutable('2026-07-15T04:00:00', new \DateTimeZone('Europe/Kyiv'));
        $this->assertTrue(BalanceShotStore::isDailySendHour($kyiv));

        $kyivWinter = new \DateTimeImmutable('2026-11-27T03:00:00', new \DateTimeZone('Europe/Kyiv'));
        $this->assertTrue(BalanceShotStore::isDailySendHour($kyivWinter));
    }

    public function test_wrong_server_hour_for_summer_is_not_due(): void
    {
        // Старая строка 03:00 Kyiv летом = 07:00 ICT — отправки не будет.
        $kyivSummerThree = new \DateTimeImmutable('2026-07-15T03:00:00', new \DateTimeZone('Europe/Kyiv'));
        $this->assertFalse(BalanceShotStore::isDailySendHour($kyivSummerThree));
    }
}
