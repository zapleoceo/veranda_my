<?php

require_once __DIR__ . '/../../../src/classes/PosterAPI.php';

class ApiPosterZaparaModel
{
    private \App\Classes\PosterAPI $api;
    private \DateTimeZone $tz;

    /** product_id => 'bar' | 'kitchen' (memoised within one PHP request) */
    private ?array $productGroup = null;
    /** workshop_id => 'bar' | 'kitchen' — what each workshop was classified as */
    private ?array $workshopGroup = null;
    /** workshop_id => name (для отладки / отображения) */
    private ?array $workshopNames = null;

    public function __construct(\App\Classes\PosterAPI $api)
    {
        $this->api = $api;
        $this->tz = new \DateTimeZone('Asia/Ho_Chi_Minh');
    }

    /**
     * Загружает мэппинг product_id → группа цеха ('bar' или 'kitchen') живьём
     * из Poster API. Никакой локальной БД: на каждый запрос — свежие данные.
     *
     * Один раз на PHP-процесс (memoised), чтобы внутри одного ?ajax=day
     * не дёргать menu.getProducts повторно.
     */
    private function ensureProductGroups(): void
    {
        if ($this->productGroup !== null) return;

        // 1) workshop_id → name + классификация по названию
        $workshops = $this->api->request('menu.getWorkshops', [], 'GET');
        $this->workshopNames = [];
        $this->workshopGroup = [];
        if (is_array($workshops)) {
            foreach ($workshops as $w) {
                if (!is_array($w)) continue;
                $wid = (int)($w['workshop_id'] ?? 0);
                if ($wid <= 0) continue;
                $name = trim((string)($w['workshop_name'] ?? ''));
                $this->workshopNames[$wid] = $name;
                $this->workshopGroup[$wid] = $this->classifyWorkshop($name);
            }
        }

        // 2) product_id → workshop_id → group
        $products = $this->api->request('menu.getProducts', [], 'GET');
        $this->productGroup = [];
        if (is_array($products)) {
            foreach ($products as $p) {
                if (!is_array($p)) continue;
                $pid = (int)($p['product_id'] ?? 0);
                if ($pid <= 0) continue;
                $wid = (int)($p['workshop'] ?? $p['workshop_id'] ?? 0);
                $this->productGroup[$pid] = $this->workshopGroup[$wid] ?? 'kitchen';
            }
        }
    }

    /**
     * Простая классификация по названию цеха. Всё что содержит «бар»/«bar»/
     * «напит»/«drink»/«кофе»/«coffee» — это бар, остальное считается кухней.
     * Если в Веранде у цеха будет «нестандартное» название и он попадёт не туда,
     * правим один список ключевых слов здесь.
     */
    private function classifyWorkshop(string $name): string
    {
        if ($name === '') return 'kitchen';
        $lower = mb_strtolower($name, 'UTF-8');
        $barKeywords = ['бар', 'bar', 'напит', 'drink', 'кофе', 'coffee'];
        foreach ($barKeywords as $kw) {
            if (mb_strpos($lower, $kw) !== false) return 'bar';
        }
        return 'kitchen';
    }

    public function day(string $date): array
    {
        $this->ensureProductGroups();

        $hours = [];
        for ($h = 9; $h <= 23; $h++) $hours[] = $h;

        $countsByHourChecks = [];
        $countsByHourDishes = [];
        $countsByHourBar     = [];
        $countsByHourKitchen = [];
        foreach ($hours as $h) {
            $countsByHourChecks[(string)$h]  = 0;
            $countsByHourDishes[(string)$h]  = 0;
            $countsByHourBar[(string)$h]     = 0;
            $countsByHourKitchen[(string)$h] = 0;
        }

        $totalChecks  = 0;
        $totalDishes  = 0;
        $totalBar     = 0;
        $totalKitchen = 0;

        $nextTr = null;
        $prevNextTr = null;
        $guard = 0;
        $seenTx = [];

        do {
            $guard++;
            if ($guard > 20000) break;
            $params = [
                'dateFrom' => str_replace('-', '', $date),
                'dateTo' => str_replace('-', '', $date),
                'status' => 2,
                'include_products' => 'true',
                'include_history' => 'false',
                'include_delivery' => 'false',
                'timezone' => 'client',
            ];
            if ($nextTr !== null) $params['next_tr'] = $nextTr;
            $batch = $this->api->request('dash.getTransactions', $params, 'GET');
            if (!is_array($batch) || count($batch) === 0) break;

            $last = end($batch);
            $nextTrCandidate = is_array($last) ? ($last['transaction_id'] ?? null) : null;

            foreach ($batch as $tx) {
                if (!is_array($tx)) continue;
                $txId = (int)($tx['transaction_id'] ?? 0);
                if ($txId <= 0) continue;
                if (isset($seenTx[$txId])) continue;
                $seenTx[$txId] = true;

                $v = $tx['date_start_new'] ?? $tx['date_start'] ?? null;
                if ($v === null) continue;
                $ts = (int)$v;
                if ($ts > 10000000000) $ts = (int)round($ts / 1000);
                if ($ts <= 0) continue;

                $dt = (new \DateTimeImmutable('@' . $ts))->setTimezone($this->tz);
                $hour = (int)$dt->format('G');
                if ($hour < 9 || $hour > 23) continue;
                $hourKey = (string)$hour;
                $countsByHourChecks[$hourKey] += 1;
                $totalChecks++;

                $dishCount    = 0;
                $dishBar      = 0;
                $dishKitchen  = 0;
                $prods = $tx['products'] ?? null;
                if (is_array($prods)) {
                    foreach ($prods as $p) {
                        if (!is_array($p)) continue;
                        $c = $p['count'] ?? $p['quantity'] ?? 1;
                        if (is_string($c)) $c = str_replace(',', '.', trim($c));
                        $cn = is_numeric($c) ? (float)$c : 1.0;
                        if ($cn <= 0) continue;
                        $n = (int)round($cn);
                        $dishCount += $n;

                        // Каждый product_id отображаем на группу цеха
                        // ('bar' / 'kitchen'). По умолчанию (если товара нет в
                        // мэппинге — например удалён или это услуга) считаем
                        // как кухню, чтобы общий total = bar + kitchen.
                        $pid = (int)($p['product_id'] ?? 0);
                        $group = $this->productGroup[$pid] ?? 'kitchen';
                        if ($group === 'bar') $dishBar     += $n;
                        else                  $dishKitchen += $n;
                    }
                }
                if ($dishCount > 0) {
                    $countsByHourDishes[$hourKey]  += $dishCount;
                    $countsByHourBar[$hourKey]     += $dishBar;
                    $countsByHourKitchen[$hourKey] += $dishKitchen;
                    $totalDishes  += $dishCount;
                    $totalBar     += $dishBar;
                    $totalKitchen += $dishKitchen;
                }
            }

            $prevNextTr = $nextTr;
            $nextTr = $nextTrCandidate;
            if ($nextTr === null || $nextTr === $prevNextTr) break;
        } while (true);

        $dow = (int)(new \DateTimeImmutable($date . ' 12:00:00', $this->tz))->format('N');

        return [
            'ok' => true,
            'date' => $date,
            'dow' => $dow,
            'status' => 2,
            'hours' => $hours,
            'counts_by_hour_checks'  => $countsByHourChecks,
            'counts_by_hour_dishes'  => $countsByHourDishes,
            'counts_by_hour_bar'     => $countsByHourBar,
            'counts_by_hour_kitchen' => $countsByHourKitchen,
            'total_checks'  => $totalChecks,
            'total_dishes'  => $totalDishes,
            'total_bar'     => $totalBar,
            'total_kitchen' => $totalKitchen,
            // Для отладки — какие цеха увидели и куда их положили. Если
            // классификация неверна (например цех «Десертная» попал в kitchen,
            // а он по сути bar) — будет видно в /zapara/?ajax=day&date=...
            'workshops' => $this->workshopNames ?? [],
            'workshop_groups' => $this->workshopGroup ?? [],
        ];
    }

    public function data(string $dateFrom, string $dateTo): array
    {
        $fromTs = strtotime($dateFrom . ' 00:00:00');
        $toTs = strtotime($dateTo . ' 00:00:00');
        if ($fromTs === false || $toTs === false) {
            throw new \RuntimeException('Bad request');
        }
        $days = (int)floor(($toTs - $fromTs) / 86400) + 1;
        if ($days <= 0 || $days > 366) {
            throw new \RuntimeException('Слишком большой диапазон');
        }

        $hours = [];
        for ($h = 9; $h <= 23; $h++) $hours[] = $h;

        $counts = [];
        for ($dow = 1; $dow <= 7; $dow++) {
            $counts[(string)$dow] = [];
            foreach ($hours as $h) $counts[(string)$dow][(string)$h] = 0;
        }

        $total = 0;
        $seen = [];
        $nextTr = null;
        $prevNextTr = null;
        $guard = 0;

        do {
            $guard++;
            if ($guard > 2000) break;
            $params = [
                'dateFrom' => str_replace('-', '', $dateFrom),
                'dateTo' => str_replace('-', '', $dateTo),
                'status' => 0,
                'include_products' => 'false',
                'include_history' => 'false',
                'include_delivery' => 'false',
                'timezone' => 'client',
            ];
            if ($nextTr !== null) $params['next_tr'] = $nextTr;
            $batch = $this->api->request('dash.getTransactions', $params, 'GET');
            if (!is_array($batch) || count($batch) === 0) break;

            $last = end($batch);
            $nextTrCandidate = is_array($last) ? ($last['transaction_id'] ?? null) : null;

            foreach ($batch as $tx) {
                if (!is_array($tx)) continue;
                $txId = (int)($tx['transaction_id'] ?? 0);
                if ($txId <= 0) continue;
                if (isset($seen[$txId])) continue;
                $seen[$txId] = true;

                $v = $tx['date_start_new'] ?? $tx['date_start'] ?? null;
                if ($v === null) continue;
                $ts = (int)$v;
                if ($ts > 10000000000) $ts = (int)round($ts / 1000);
                if ($ts <= 0) continue;

                $dt = (new \DateTimeImmutable('@' . $ts))->setTimezone($this->tz);
                $hour = (int)$dt->format('G');
                if ($hour < 9 || $hour > 23) continue;
                $dow = (int)$dt->format('N');
                $dk = (string)$dow;
                $hk = (string)$hour;
                if (!isset($counts[$dk]) || !array_key_exists($hk, $counts[$dk])) continue;
                $counts[$dk][$hk] += 1;
                $total++;
            }

            $prevNextTr = $nextTr;
            $nextTr = $nextTrCandidate;
            if ($nextTr === null || $nextTr === $prevNextTr) break;
        } while (true);

        return [
            'ok' => true,
            'request' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'days' => $days,
            ],
            'hours' => $hours,
            'counts_by_dow' => $counts,
            'total' => $total,
        ];
    }
}

