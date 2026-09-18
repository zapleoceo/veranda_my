<?php

declare(strict_types=1);

namespace Tests\Unit\Payday3;

use App\Payday3\Domain\AmountTimeMatcher;
use PHPUnit\Framework\TestCase;

/**
 * Единый алгоритм авто-связи для всех трёх пар таблиц payday3
 * (поступления ↔ чеки, поступления ↔ приходы Poster, расходы ↔ расходы
 * Poster). Правило владельца: «совпадает сумма и более-менее время».
 */
final class AmountTimeMatcherTest extends TestCase
{
    private const T0 = 1789000000;

    private static function bank(int $id, int $amount, int $offset): array
    {
        return ['id' => $id, 'amount' => $amount, 'ts' => self::T0 + $offset];
    }

    private static function poster(int $id, int $amount, int $offset, bool $eligible = true): array
    {
        return ['id' => $id, 'amount' => $amount, 'ts' => self::T0 + $offset, 'eligible' => $eligible];
    }

    public function test_same_amount_within_ten_minutes_is_green(): void
    {
        // 16.09: SePay SHIRIAEVA 23:12:44 ↔ Poster «компенсация…» 23:13:00.
        $pairs = AmountTimeMatcher::match([self::bank(82328778, 1331200, 0)], [self::poster(11076, 1331200, 16)]);
        $this->assertSame([['bank' => 82328778, 'poster' => 11076, 'type' => 'auto_green']], $pairs);
    }

    public function test_closest_time_wins_among_same_amounts(): void
    {
        $pairs = AmountTimeMatcher::match(
            [self::bank(1, 500, 500), self::bank(2, 500, 30)],
            [self::poster(9, 500, 0)],
        );
        $this->assertSame(2, $pairs[0]['bank'], 'ближе по времени — 30 с, а не 500 с');
    }

    public function test_same_amount_far_in_time_is_yellow(): void
    {
        $pairs = AmountTimeMatcher::match([self::bank(1, 700, 3 * 3600)], [self::poster(9, 700, 0)]);
        $this->assertSame([['bank' => 1, 'poster' => 9, 'type' => 'auto_yellow']], $pairs);
    }

    public function test_different_amounts_never_match(): void
    {
        $this->assertSame([], AmountTimeMatcher::match([self::bank(1, 700, 0)], [self::poster(9, 701, 0)]));
    }

    public function test_each_row_is_used_once(): void
    {
        $pairs = AmountTimeMatcher::match(
            [self::bank(1, 100, 0)],
            [self::poster(8, 100, 10), self::poster(9, 100, 20)],
        );
        $this->assertCount(1, $pairs, 'одно поступление — одна пара');
    }

    public function test_ineligible_poster_rows_are_skipped_but_count_as_neighbours(): void
    {
        // p2 is sandwiched between p1 and p3 that go green in this run → green
        // even though its bank row is 2 h away (interpolation pass).
        $pairs = AmountTimeMatcher::match(
            [self::bank(1, 100, 0), self::bank(2, 250, 7200), self::bank(3, 300, 120)],
            [self::poster(11, 100, 10), self::poster(12, 250, 60), self::poster(13, 300, 110), self::poster(14, 999, 5, false)],
        );
        $byPoster = array_column($pairs, null, 'poster');
        $this->assertSame('auto_green', $byPoster[12]['type'], 'зажата между двумя зелёными — зелёная');
        $this->assertArrayNotHasKey(14, $byPoster, 'неподходящая строка не связывается');
    }

    public function test_poster_order_is_chronological_whatever_the_input_order(): void
    {
        // Poster finance comes newest-first; the interpolation pass must still
        // see the true neighbours.
        $bank   = [self::bank(1, 100, 0), self::bank(2, 250, 7200), self::bank(3, 300, 120)];
        $asc    = [self::poster(11, 100, 10), self::poster(12, 250, 60), self::poster(13, 300, 110)];
        $this->assertEquals(
            AmountTimeMatcher::match($bank, $asc),
            AmountTimeMatcher::match($bank, array_reverse($asc)),
        );
    }

    public function test_rows_without_time_or_amount_are_ignored(): void
    {
        $this->assertSame([], AmountTimeMatcher::match(
            [['id' => 1, 'amount' => 100, 'ts' => 0], self::bank(2, 0, 0)],
            [self::poster(9, 100, 0), self::poster(8, 0, 0)],
        ));
    }

    public function test_ts_parses_datetime_and_rejects_garbage(): void
    {
        $this->assertSame(strtotime('2026-09-16 23:12:44'), AmountTimeMatcher::ts('2026-09-16 23:12:44'));
        $this->assertSame(0, AmountTimeMatcher::ts(''));
        $this->assertSame(0, AmountTimeMatcher::ts('не дата'));
    }
}
