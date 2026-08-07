<?php

declare(strict_types=1);

namespace App\Cashflow\Services;

use App\Cashflow\Domain\PosterMoney;

/**
 * Live revenue grid straight from Poster — no DB, no manual entry.
 *
 * Per day: total = Σ dash.getCategoriesSales (÷100), hookah = category 47,
 * food = total − hookah. food + hookah == total by construction, so the
 * 12-July class of double-count is structurally impossible. Σ(day categories)
 * equals the month dash.getAnalytics.revenue (verified 2026-08-07), which
 * drives the reconciliation badge.
 *
 * "Day" = Poster business day (date_close), timezone Asia/Ho_Chi_Minh.
 */
final class RevenueService
{
    private const API = 'https://joinposter.com/api/';
    private const TZ  = 'Asia/Ho_Chi_Minh';

    /** Only hookah category today (verified Jan–Aug 2026). TODO §Q1: move to a map table. */
    public const HOOKAH_CATEGORY_IDS = [47];

    private const RU_MONTHS = [
        1 => 'Январь', 2 => 'Февраль', 3 => 'Март', 4 => 'Апрель', 5 => 'Май', 6 => 'Июнь',
        7 => 'Июль', 8 => 'Август', 9 => 'Сентябрь', 10 => 'Октябрь', 11 => 'Ноябрь', 12 => 'Декабрь',
    ];

    public function __construct(
        private readonly string $token,
        private readonly string $caInfo = ''
    ) {}

    /**
     * Build the month revenue grid.
     *
     * @return array{year:int,month:int,label:string,rows:list<array{day:int,date:string,weekday:int,food:int,hookah:int,total:int}>,totals:array{food:int,hookah:int,total:int},reconcile:array{sumOfDays:int,analytics:?int,delta:?int,ok:bool},isCurrentMonth:bool,generatedAt:string}
     */
    public function month(int $year, int $month): array
    {
        $tz    = new \DateTimeZone(self::TZ);
        $first = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month), $tz);
        $today = new \DateTimeImmutable('now', $tz);

        $daysInMonth = (int) $first->format('t');
        $isCurrent   = $first->format('Y-m') === $today->format('Y-m');
        // Current month → only elapsed days (future days are all zeros in Poster);
        // any past/future month → all days.
        $lastDay = $isCurrent ? max(1, min($daysInMonth, (int) $today->format('j'))) : $daysInMonth;

        $days      = range(1, $lastDay);
        $paramSets = array_map(
            static fn (int $d): array => [
                'dateFrom' => sprintf('%04d%02d%02d', $year, $month, $d),
                'dateTo'   => sprintf('%04d%02d%02d', $year, $month, $d),
            ],
            $days
        );
        $responses = $this->getMany('dash.getCategoriesSales', $paramSets);

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
                'weekday' => (int) $date->format('N'), // 1=Mon … 7=Sun
                'food'    => $food,
                'hookah'  => $hookah,
                'total'   => $total,
            ];
            $sum['food']   += $food;
            $sum['hookah'] += $hookah;
            $sum['total']  += $total;
        }

        // Reconciliation: Σ days vs the month analytics counter (§7.3).
        $analyticsTotal = null;
        try {
            $an = $this->get('dash.getAnalytics', [
                'dateFrom' => $first->format('Ymd'),
                'dateTo'   => $first->modify('last day of this month')->format('Ymd'),
            ]);
            $analyticsTotal = PosterMoney::fromAnalytics($an['counters']['revenue'] ?? 0);
        } catch (\Throwable) {
            // Non-fatal: the grid still renders, badge shows "unavailable".
        }

        return [
            'year'   => $year,
            'month'  => $month,
            'label'  => (self::RU_MONTHS[$month] ?? (string) $month) . ' ' . $year,
            'rows'   => $rows,
            'totals' => $sum,
            'reconcile' => [
                'sumOfDays' => $sum['total'],
                'analytics' => $analyticsTotal,
                'delta'     => $analyticsTotal === null ? null : $sum['total'] - $analyticsTotal,
                'ok'        => $analyticsTotal !== null && ($sum['total'] - $analyticsTotal) === 0,
            ],
            'isCurrentMonth' => $isCurrent,
            'generatedAt'    => $today->format('Y-m-d H:i'),
        ];
    }

    /** Single GET → response array. Throws on transport/API error. */
    private function get(string $method, array $params): array
    {
        $params['token'] = $this->token;
        $ch = curl_init(self::API . $method . '?' . http_build_query($params));
        $this->applyOpts($ch);
        $body = curl_exec($ch);
        if ($body === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException("Poster {$method}: {$err}");
        }
        curl_close($ch);
        $data = json_decode((string) $body, true);
        if (!is_array($data)) {
            throw new \RuntimeException("Poster {$method}: bad JSON");
        }
        if (!empty($data['error'])) {
            throw new \RuntimeException("Poster {$method}: error " . json_encode($data['error'], JSON_UNESCAPED_UNICODE));
        }
        return $data['response'] ?? [];
    }

    /**
     * Parallel GETs via curl_multi. Returns responses indexed like $paramSets.
     * A failed handle yields [] for that index (that day shows zeros) rather
     * than failing the whole grid.
     *
     * @param  list<array<string,string>> $paramSets
     * @return array<int,array>
     */
    private function getMany(string $method, array $paramSets): array
    {
        if ($paramSets === []) {
            return [];
        }
        $mh      = curl_multi_init();
        $handles = [];
        foreach ($paramSets as $i => $params) {
            $params['token'] = $this->token;
            $ch = curl_init(self::API . $method . '?' . http_build_query($params));
            $this->applyOpts($ch);
            $handles[$i] = $ch;
            curl_multi_add_handle($mh, $ch);
        }
        do {
            $status = curl_multi_exec($mh, $running);
            if ($running) {
                curl_multi_select($mh, 1.0);
            }
        } while ($running && $status === CURLM_OK);

        $out = [];
        foreach ($handles as $i => $ch) {
            $body    = curl_multi_getcontent($ch);
            $data    = is_string($body) ? json_decode($body, true) : null;
            $out[$i] = (is_array($data) && isset($data['response']) && is_array($data['response']))
                ? $data['response']
                : [];
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
        return $out;
    }

    private function applyOpts(\CurlHandle $ch): void
    {
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        if ($this->caInfo !== '') {
            curl_setopt($ch, CURLOPT_CAINFO, $this->caInfo);
        }
    }
}
