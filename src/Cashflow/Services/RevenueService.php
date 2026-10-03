<?php

declare(strict_types=1);

namespace App\Cashflow\Services;

use App\Cashflow\Domain\FinanceMap;
use App\Cashflow\Domain\PosterMoney;

/**
 * Live revenue per day straight from Poster — no DB, no manual entry.
 *
 * Per day: total = Σ dash.getCategoriesSales (÷100), hookah = category 47,
 * grabMenu = Σ payed_sum чеков клиента GRAB (виртуальный депозит, не деньги —
 * сами деньги Grab идут колонкой «Grab (поступления)» из финмодуля),
 * food = total − hookah − grabMenu. food + hookah + grabMenu == total by
 * construction, so the 12-July class of double-count is structurally
 * impossible. Σ(day categories) equals the month dash.getAnalytics.revenue
 * (verified), which drives the reconciliation badge.
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
     * @return array{rows:list<array{day:int,date:string,weekday:int,food:int,hookah:int,grabMenu:int,total:int}>,totals:array{food:int,hookah:int,grabMenu:int,total:int},reconcile:array{sumOfDays:int,analytics:?int,delta:?int,ok:bool},isCurrentMonth:bool,lastDay:int}
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
        $grabByDay = $this->grabMenuByDay($first);

        $rows = [];
        $sum  = ['food' => 0, 'hookah' => 0, 'grabMenu' => 0, 'total' => 0];
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
            $date     = new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $d), $tz);
            $grabMenu = $grabByDay[$date->format('Y-m-d')] ?? 0;
            $food     = $total - $hookah - $grabMenu;
            $rows[] = [
                'day'      => $d,
                'date'     => $date->format('Y-m-d'),
                'weekday'  => (int) $date->format('N'),
                'food'     => $food,
                'hookah'   => $hookah,
                'grabMenu' => $grabMenu,
                'total'    => $total,
            ];
            $sum['food']     += $food;
            $sum['hookah']   += $hookah;
            $sum['grabMenu'] += $grabMenu;
            $sum['total']    += $total;
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

    /**
     * Чеки клиента GRAB за месяц (один dash.getTransactions, ~2–6 с) → сумма
     * payed_sum по дню закрытия. Это та же величина, что сидит в выручке по
     * категориям (Σ payed_sum всех чеков == Σ getCategoriesSales, сверено на
     * сентябре 2026). Сбой Poster → пусто: Grab останется внутри «еды», отчёт
     * не падает.
     *
     * @return array<string,int> Y-m-d → ₫
     */
    private function grabMenuByDay(\DateTimeImmutable $first): array
    {
        try {
            $checks = $this->http->get('dash.getTransactions', [
                'dateFrom' => $first->format('Ymd'),
                'dateTo'   => $first->modify('last day of this month')->format('Ymd'),
                'status'   => 2,
            ]);
        } catch (\Throwable) {
            return [];
        }
        $out = [];
        foreach ($checks as $t) {
            if (!is_array($t) || (int) ($t['client_id'] ?? 0) !== FinanceMap::GRAB_CLIENT_ID) {
                continue;
            }
            $day = substr((string) ($t['date_close_date'] ?? ''), 0, 10);
            if ($day === '') {
                continue;
            }
            $out[$day] = ($out[$day] ?? 0) + PosterMoney::fromDashCents($t['payed_sum'] ?? $t['sum'] ?? 0);
        }
        return $out;
    }
}
