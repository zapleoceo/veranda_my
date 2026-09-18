<?php

declare(strict_types=1);

namespace Tests\Unit\Payday3;

use App\Payday3\Domain\Money;
use App\Payday3\Domain\PosterTime;
use App\Payday3\Services\PosterCheckService;
use PHPUnit\Framework\TestCase;

/**
 * Денежные единицы Poster. Самое дорогое место в проекте: ошибка здесь не
 * падает, а показывает неверную сумму в отчёте по деньгам.
 *
 * У Poster ДВА формата, и оба «с копейками», но по-разному:
 *   • dash.*                      → минорные единицы целым: 49500000  = 495 000 ₫
 *   • transactions.getTransactions → донги строкой:          "495000.00" = 495 000 ₫
 *
 * Сверено на живом проде 2026-07-30, чек 24821 в обоих эндпоинтах.
 * Первый нужно делить на 100, второй — категорически нет. Перепутанный
 * конвертер занижал суммы ровно в 100 раз (495 000 ₫ показывались как 4 950).
 *
 * Ожидаемое значение ниже — целые донги: копейки не отображаем, но и лишнего
 * деления не делаем.
 */
final class MoneyConversionTest extends TestCase
{
    /**
     * Путь transactions.getTransactions (бывший приватный
     * PosterCheckService::vndFromV3, теперь единый Money::toInt).
     */
    private function vndFromV3(mixed $raw): int
    {
        return Money::toInt($raw);
    }

    // ─── путь dash.* (минорные единицы) ───────────────────────────────────

    /** Реальные значения из dash.getTransactions за 2026-07-30. */
    public function test_dash_minor_units_are_divided_by_100(): void
    {
        $this->assertSame(495000, Money::posterMinorToVnd(49500000), 'чек 24821');
        $this->assertSame(270000, Money::posterMinorToVnd(27000000), 'чек 24837');
        $this->assertSame(180000, Money::posterMinorToVnd(18000000), 'чек 24823');
        $this->assertSame(2465000, Money::posterMinorToVnd(246500000), 'чек 24819');
    }

    public function test_dash_zero_and_empty(): void
    {
        $this->assertSame(0, Money::posterMinorToVnd(0));
        $this->assertSame(0, Money::posterMinorToVnd(null));
        $this->assertSame(0, Money::posterMinorToVnd(''));
    }

    // ─── путь transactions.getTransactions (донги с копейками) ────────────

    /**
     * Те же самые чеки, но из другого эндпоинта. Значения обязаны совпасть
     * с dash-веткой выше — это и есть проверка «не делим лишний раз».
     */
    public function test_v3_decimal_strings_are_not_divided(): void
    {
        $this->assertSame(495000, $this->vndFromV3('495000.00'), 'чек 24821');
        $this->assertSame(270000, $this->vndFromV3('270000.00'), 'чек 24837');
        $this->assertSame(180000, $this->vndFromV3('180000.00'), 'чек 24823');
        $this->assertSame(229500, $this->vndFromV3('229500.00'), 'чек 24837, payed_sum');
    }

    /** Ключевая регрессия: 495 000 ₫ не должны превратиться в 4 950. */
    public function test_v3_value_is_not_hundred_times_smaller(): void
    {
        $this->assertNotSame(4950, $this->vndFromV3('495000.00'));
        $this->assertSame(495000, $this->vndFromV3('495000.00'));
    }

    /** Оба эндпоинта на одном и том же чеке дают одинаковый результат. */
    public function test_both_endpoints_agree_on_the_same_check(): void
    {
        $fromDash = Money::posterMinorToVnd(49500000);
        $fromV3   = $this->vndFromV3('495000.00');

        $this->assertSame($fromDash, $fromV3, 'расхождение веток = суммы в отчёте зависят от источника');
    }

    /** Копейки в дробной части округляются, а не отбрасываются в мусор. */
    public function test_v3_fractional_part_is_rounded(): void
    {
        $this->assertSame(495001, $this->vndFromV3('495000.60'));
        $this->assertSame(495000, $this->vndFromV3('495000.40'));
    }

    public function test_v3_handles_numeric_types_and_garbage(): void
    {
        $this->assertSame(495000, $this->vndFromV3(495000));
        $this->assertSame(495000, $this->vndFromV3(495000.0));
        $this->assertSame(0, $this->vndFromV3(null));
        $this->assertSame(0, $this->vndFromV3(''));
        $this->assertSame(0, $this->vndFromV3('не число'));
    }

    /** Формат вывода: разряды разделены, копеек нет. */
    public function test_format_shows_no_kopecks(): void
    {
        $this->assertSame("495\u{202F}000", Money::vnd(495000)->format());
        $this->assertSame("-12\u{202F}500", Money::vnd(-12500)->format());
    }

    // ─── единые хелперы (бывшие moneyToInt / normMoneyMinor копии) ─────────

    public function test_to_int_matches_old_money_to_int_copies(): void
    {
        $this->assertSame(1500, Money::toInt(1500));
        $this->assertSame(1500, Money::toInt(1499.6));
        $this->assertSame(1500, Money::toInt('1500'));
        $this->assertSame(1500, Money::toInt('1500.00'));
        $this->assertSame(1500, Money::toInt('1 500,00'), 'пробелы и запятая');
        $this->assertSame(0, Money::toInt(['x' => 1]), 'не-скаляр → 0');
        $this->assertSame(0, Money::toInt(null));
    }

    public function test_poster_minor_to_vnd_tolerates_spaces_like_old_norm_money_minor(): void
    {
        $this->assertSame(835000, Money::posterMinorToVnd('83 500 000'));
        $this->assertSame(-835000, Money::posterMinorToVnd('-83500000'));
    }

    public function test_poster_time_handles_seconds_ms_and_strings(): void
    {
        $sec = 1_789_000_000;
        $this->assertSame($sec, PosterTime::toUnix($sec));
        $this->assertSame($sec, PosterTime::toUnix($sec * 1000), 'миллисекунды (порог 2e12 их пропускал)');
        $this->assertSame($sec, PosterTime::toUnix((string)($sec * 1000)));
        $this->assertSame(strtotime('2026-09-18 10:00:00'), PosterTime::toUnix('2026-09-18 10:00:00'));
        $this->assertNull(PosterTime::toUnix(''));
        $this->assertNull(PosterTime::toUnix(null));
        $this->assertNull(PosterTime::toUnix('garbage'));
    }
}
