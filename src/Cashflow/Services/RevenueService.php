<?php

declare(strict_types=1);

namespace App\Cashflow\Services;

use App\Cashflow\Domain\PosterMoney;

/**
 * Live revenue per day straight from Poster — no DB, no manual entry.
 *
 * Per day: total = Σ dash.getCategoriesSales (÷100), hookah = category 47,
 * food = total − hookah. food + hookah == total by construction, so the
 * 12-July class of double-count is structurally impossible. Σ(day categories)
 * equals the month dash.getAnalytics.revenue (verified), which drives the
 * reconciliation badge.
 *
 * "Day" = Poster business day (date_close), timezone Asia/Ho_Chi_Minh.
 */
final class RevenueService
{
    private const TZ = 'Asia/Ho_Chi_Minh';

    /** Only hookah category today (verified Jan–Aug 2026). TODO §Q1: move to a map table. */
    public const HOOKAH_CATEGORY_IDS = [47];

    public function __construct(private readonly PosterHttp $http) {}

    /**
     * @return array{rows:list<array{day:int,date:string,weekday:int,food:int,hookah:int,total:int}>,totals:array{food:int,hookah:int,total:int},reconcile:array{sumOfDays:int,analytics:?int,delta:?int,ok:bool},isCurrentMonth:bool,lastDay:int}
     */
    public function month(int $year, int $month): array
    {
        $tz    = new \DateTimeZone(self::TZ);
        $first = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month), $tz);
        $today = new \DateTimeImmutable('now', $tz);

        $daysInMonth = (int) $first->format('t');
        $isCurrent   = $first->format('Y-m') === $today->format('Y-m');
        $lastDay     = $isCurrent ? max(1, min($daysInMonth, (int) $today->format('j'))) : $daysInMonth;

        $days      = range(1, $lastDay);
        $paramSets = array_map(
            static fn (int $d): array => [
                'dateFrom' => sprintf('%04d%02d%02d', $year, $month, $d),
                'dateTo'   => sprintf('%04d%02d%02d', $year, $month, $d),
            ],
            $days
        );
        $responses = $this->http->getMany('dash.getCategoriesSales', $paramSets);

        $rows = [];
        $sum  = ['food' => 0, 'hookah' => 0, 'total' => 0];
        foreach ($days as $i => $d) {
            $cats   = is_array($responses[$i] ?? null) ? $responses[$i] : [];
            $total  = 0;
            $hookah = 0;
            foreach ($cats as $c) {
                $rev    = PosterMoney::fromDashCents($c['revenue'] ?? 0);
                $total += $rev;
                if (in_array((int) ($c['category_id'] ?? -1), self::HOOKAH_CATEGORY_IDS, true)) {
                    $hookah += $rev;
                }
            }
            $food = $total - $hookah;
            $date = new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $d), $tz);
            $rows[] = [
                'day'     => $d,
                'date'    => $date->format('Y-m-d'),
                'weekday' => (int) $date->format('N'),
                'food'    => $food,
                'hookah'  => $hookah,
                'total'   => $total,
            ];
            $sum['food']   += $food;
            $sum['hookah'] += $hookah;
            $sum['total']  += $total;
        }

        $analyticsTotal = null;
        try {
            $an = $this->http->get('dash.getAnalytics', [
                'dateFrom' => $first->format('Ymd'),
                'dateTo'   => $first->modify('last day of this month')->format('Ymd'),
            ]);
            $analyticsTotal = PosterMoney::fromAnalytics($an['counters']['revenue'] ?? 0);
        } catch (\Throwable) {
            // Non-fatal: grid still renders, badge shows "unavailable".
        }

        return [
            'rows'   => $rows,
            'totals' => $sum,
            'reconcile' => [
                'sumOfDays' => $sum['total'],
                'analytics' => $analyticsTotal,
                'delta'     => $analyticsTotal === null ? null : $sum['total'] - $analyticsTotal,
                'ok'        => $analyticsTotal !== null && ($sum['total'] - $analyticsTotal) === 0,
            ],
            'isCurrentMonth' => $isCurrent,
            'lastDay'        => $lastDay,
        ];
    }
}
