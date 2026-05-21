<?php

declare(strict_types=1);

namespace App\Schedule\Services;

/**
 * Builds the day list for the requested period. Pure logic, no I/O.
 *
 * Output rows:
 *   [{idx, iso, date, mon, dow, weekend}]
 */
final class PeriodBuilder
{
    private const DOW_RU = ['вс','пн','вт','ср','чт','пт','сб'];
    private const MON_RU = ['','янв','фев','мар','апр','мая','июн','июл','авг','сен','окт','ноя','дек'];

    private const MAX_DAYS = 366;

    public function build(string $from, string $to): array
    {
        $cur = strtotime($from);
        $end = strtotime($to);
        if ($cur === false || $end === false || $cur > $end) return [];

        $out = [];
        $i = 0;
        while ($cur <= $end && $i < self::MAX_DAYS) {
            $out[] = [
                'idx'     => $i,
                'iso'     => date('Y-m-d', $cur),
                'date'    => (string) date('j', $cur),
                'mon'     => self::MON_RU[(int) date('n', $cur)],
                'dow'     => self::DOW_RU[(int) date('w', $cur)],
                // Пт/Сб/Вс (5/6/0) — выделяем как «уикенд». Должно
                // совпадать с JS (schedule.js dayWarnReasons + popover
                // defaults), иначе пятница в сетке будет будним днём,
                // но получит выходные правила. Раньше было [0, 6] —
                // источник рассинхрона.
                'weekend' => in_array((int) date('w', $cur), [0, 5, 6], true),
            ];
            $cur = (int) strtotime('+1 day', $cur);
            $i++;
        }
        return $out;
    }

    /** Normalize a date input — returns valid 'YYYY-MM-DD' or null. */
    public static function normalizeDate(?string $s): ?string
    {
        if (!is_string($s) || $s === '') return null;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return null;
        $ts = strtotime($s);
        return $ts === false ? null : date('Y-m-d', $ts);
    }

    /** Default period: current Monday → +13 days (two weeks). */
    public static function defaultRange(): array
    {
        $from = date('Y-m-d', (int) strtotime('monday this week'));
        $to   = date('Y-m-d', (int) strtotime($from . ' +13 days'));
        return ['from' => $from, 'to' => $to];
    }
}
