<?php

declare(strict_types=1);

namespace Tests\Unit\Banya;

use App\Banya\MonthlyPayout;
use PHPUnit\Framework\TestCase;

class MonthlyPayoutTest extends TestCase
{
    public function test_message_matches_the_manual_october_report(): void
    {
        // Ровно то, что Дима отправил в группу 01.10.2026 за сентябрь.
        $this->assertSame(
            "Сумма без кальянов: 120 009 500\nК выплате 24 001 900",
            MonthlyPayout::message(120_009_500)
        );
    }

    public function test_payout_is_twenty_percent_rounded(): void
    {
        $this->assertSame(24_001_900, MonthlyPayout::payout(120_009_500));
        $this->assertSame(1, MonthlyPayout::payout(3));   // 0.6 → 1
        $this->assertSame(0, MonthlyPayout::payout(2));   // 0.4 → 0
        $this->assertSame(0, MonthlyPayout::payout(0));
    }

    public function test_previous_month_on_the_first_at_10_local(): void
    {
        // 01.10 10:00 по Нячангу = 01.10 03:00 UTC
        $p = MonthlyPayout::previousMonth(new \DateTimeImmutable('2026-10-01 03:00:00', new \DateTimeZone('UTC')));
        $this->assertSame(['ym' => '2026-09', 'from' => '2026-09-01', 'to' => '2026-09-30'], $p);
    }

    public function test_previous_month_uses_restaurant_timezone_not_utc(): void
    {
        // 01.03 00:30 по Нячангу — это ещё 28.02 по UTC; прошлый месяц — февраль.
        $p = MonthlyPayout::previousMonth(new \DateTimeImmutable('2026-02-28 17:30:00', new \DateTimeZone('UTC')));
        $this->assertSame(['ym' => '2026-02', 'from' => '2026-02-01', 'to' => '2026-02-28'], $p);
    }

    public function test_january_rolls_back_to_december(): void
    {
        $p = MonthlyPayout::previousMonth(new \DateTimeImmutable('2027-01-01 05:00:00', new \DateTimeZone(MonthlyPayout::TZ)));
        $this->assertSame(['ym' => '2026-12', 'from' => '2026-12-01', 'to' => '2026-12-31'], $p);
    }

    public function test_month_of_validates_input(): void
    {
        $this->assertSame(['ym' => '2024-02', 'from' => '2024-02-01', 'to' => '2024-02-29'], MonthlyPayout::monthOf('2024-02'));
        $this->assertNull(MonthlyPayout::monthOf('2026-13'));
        $this->assertNull(MonthlyPayout::monthOf('2026-9'));
        $this->assertNull(MonthlyPayout::monthOf("2026-09\n"));
    }
}
