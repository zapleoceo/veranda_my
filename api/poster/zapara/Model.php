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
     * Параллельно тянет все три исходных запроса Poster для одного дня:
     *   menu.getWorkshops, menu.getProducts, dash.getTransactions(первая страница).
     * Через curl_multi → wall-time = max(longest call), а не сумма всех трёх.
     * Никакого кеширования, всё живьём на каждый ?ajax=day.
     *
     * Возвращает: [workshopsArr, productsArr, transactionsFirstBatch].
     */
    private function fetchDayInitialParallel(string $date): array
    {
        // Достаём токен через рефлексию — он private в PosterAPI, переиспользуем
        // тот же ключ без правки публичного API клиента.
        $ref = new \ReflectionClass($this->api);
        $tokenProp = $ref->getProperty('token');
        $tokenProp->setAccessible(true);
        $token = (string)$tokenProp->getValue($this->api);
        if ($token === '') throw new \RuntimeException('Poster token is empty');

        $base   = 'https://joinposter.com/api/';
        $dateYmd = str_replace('-', '', $date);
        $urls = [
            'workshops' => $base . 'menu.getWorkshops?' . http_build_query(['token' => $token]),
            'products'  => $base . 'menu.getProducts?'  . http_build_query(['token' => $token, 'hidden' => 0]),
            'tx'        => $base . 'dash.getTransactions?' . http_build_query([
                'token'             => $token,
                'dateFrom'          => $dateYmd,
                'dateTo'            => $dateYmd,
                'status'            => 2,
                'include_products'  => 'true',
                'include_history'   => 'false',
                'include_delivery'  => 'false',
                'timezone'          => 'client',
            ]),
        ];

        $mh = curl_multi_init();
        $handles = [];
        foreach ($urls as $name => $url) {
            $ch = $this->buildCurl($url);
            curl_multi_add_handle($mh, $ch);
            $handles[$name] = $ch;
        }

        // Классический curl_multi-event-loop. select() блочится на готовности
        // любого из handle'ов чтобы не крутить busy-loop CPU.
        $running = null;
        do {
            $status = curl_multi_exec($mh, $running);
            if ($running > 0) curl_multi_select($mh, 0.1);
        } while ($running > 0 && $status === CURLM_OK);

        $result = [];
        foreach ($handles as $name => $ch) {
            $raw  = curl_multi_getcontent($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
            $result[$name] = $this->parsePosterResponse('menu.'.$name, $raw, $code, $err);
        }
        curl_multi_close($mh);

        return [$result['workshops'], $result['products'], $result['tx']];
    }

    private function buildCurl(string $url)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_ENCODING, 'gzip');
        return $ch;
    }

    private function parsePosterResponse(string $method, $raw, int $code, string $err): array
    {
        if ($err !== '') throw new \RuntimeException('CURL ' . $method . ': ' . $err);
        if (!is_string($raw) || $raw === '') {
            throw new \RuntimeException('Poster ' . $method . ': empty response (http=' . $code . ')');
        }
        $j = json_decode($raw, true);
        if (!is_array($j)) {
            throw new \RuntimeException('Poster ' . $method . ': invalid JSON (http=' . $code . ')');
        }
        if ($code < 200 || $code > 299) {
            throw new \RuntimeException('Poster ' . $method . ': http=' . $code);
        }
        if (isset($j['error']) && $j['error']) {
            $msg = is_string($j['error']) ? $j['error'] : json_encode($j['error']);
            throw new \RuntimeException('Poster ' . $method . ': ' . $msg);
        }
        return is_array($j['response'] ?? null) ? $j['response'] : [];
    }

    /** Построить мэппинг product_id → 'bar'|'kitchen' из сырых ответов Poster. */
    private function buildWorkshopMap(array $workshops, array $products): void
    {
        $this->workshopNames = [];
        $this->workshopGroup = [];
        foreach ($workshops as $w) {
            if (!is_array($w)) continue;
            $wid = (int)($w['workshop_id'] ?? 0);
            if ($wid <= 0) continue;
            $name = trim((string)($w['workshop_name'] ?? ''));
            $this->workshopNames[$wid] = $name;
            $this->workshopGroup[$wid] = $this->classifyWorkshop($name);
        }
        $this->productGroup = [];
        foreach ($products as $p) {
            if (!is_array($p)) continue;
            $pid = (int)($p['product_id'] ?? 0);
            if ($pid <= 0) continue;
            $wid = (int)($p['workshop'] ?? $p['workshop_id'] ?? 0);
            $this->productGroup[$pid] = $this->workshopGroup[$wid] ?? 'kitchen';
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
        // ── Optimisation: вместо последовательной цепочки menu.getWorkshops →
        // menu.getProducts → dash.getTransactions выполняем их параллельно через
        // curl_multi. Все три запроса независимы (mapping product→цех нужен
        // ТОЛЬКО для аггрегации после того как все три ответа пришли).
        // Wall time = max(longest) вместо суммы ≈ −60% на каждый ?ajax=day.
        // Никакого кеширования — каждый вызов тянет всё свежим из Poster.
        [$workshopsRaw, $productsRaw, $firstBatch] = $this->fetchDayInitialParallel($date);
        $this->buildWorkshopMap($workshopsRaw, $productsRaw);

        $hours = [];
        for ($h = 9; $h <= 23; $h++) $hours[] = $h;

        $counts = [
            'checks'  => array_fill_keys(array_map('strval', $hours), 0),
            'dishes'  => array_fill_keys(array_map('strval', $hours), 0),
            'bar'     => array_fill_keys(array_map('strval', $hours), 0),
            'kitchen' => array_fill_keys(array_map('strval', $hours), 0),
        ];
        $totals = ['checks' => 0, 'dishes' => 0, 'bar' => 0, 'kitchen' => 0];
        $seenTx = [];

        // Первая страница — уже параллельно загружена.
        $nextTr = $this->processBatch($firstBatch, $counts, $totals, $seenTx);

        // Пагинация (нужна только если день > 100 транзакций — у Веранды
        // практически никогда не бывает). Идём по next_tr пока не закончится
        // или не повторится. Защитный счётчик от бесконечных циклов.
        $guard = 0;
        while ($nextTr !== null && $guard++ < 100) {
            $batch = $this->api->request('dash.getTransactions', [
                'dateFrom'         => str_replace('-', '', $date),
                'dateTo'           => str_replace('-', '', $date),
                'status'           => 2,
                'include_products' => 'true',
                'include_history'  => 'false',
                'include_delivery' => 'false',
                'timezone'         => 'client',
                'next_tr'          => $nextTr,
            ], 'GET');
            if (!is_array($batch) || count($batch) === 0) break;
            $prevTr = $nextTr;
            $nextTr = $this->processBatch($batch, $counts, $totals, $seenTx);
            if ($nextTr === $prevTr) break;
        }

        $countsByHourChecks  = $counts['checks'];
        $countsByHourDishes  = $counts['dishes'];
        $countsByHourBar     = $counts['bar'];
        $countsByHourKitchen = $counts['kitchen'];
        $totalChecks  = $totals['checks'];
        $totalDishes  = $totals['dishes'];
        $totalBar     = $totals['bar'];
        $totalKitchen = $totals['kitchen'];

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

    /**
     * Обработать одну страницу транзакций (от первой curl_multi-выгрузки
     * или из последующего next_tr): расписать по часам и группам цехов.
     * Все массивы передаются по ссылке. Возвращает кандидата на next_tr
     * (id последней транзакции в батче) или null если страница пустая.
     */
    private function processBatch(array $batch, array &$counts, array &$totals, array &$seenTx): ?int
    {
        if (count($batch) === 0) return null;

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
            $hk = (string)$hour;

            $counts['checks'][$hk] += 1;
            $totals['checks']      += 1;

            $dishCount = 0; $dishBar = 0; $dishKitchen = 0;
            foreach (($tx['products'] ?? []) as $p) {
                if (!is_array($p)) continue;
                $c = $p['count'] ?? $p['quantity'] ?? 1;
                if (is_string($c)) $c = str_replace(',', '.', trim($c));
                $cn = is_numeric($c) ? (float)$c : 1.0;
                if ($cn <= 0) continue;
                $n = (int)round($cn);
                $dishCount += $n;
                $pid = (int)($p['product_id'] ?? 0);
                $group = $this->productGroup[$pid] ?? 'kitchen';
                if ($group === 'bar') $dishBar     += $n;
                else                  $dishKitchen += $n;
            }
            if ($dishCount > 0) {
                $counts['dishes'][$hk]  += $dishCount;
                $counts['bar'][$hk]     += $dishBar;
                $counts['kitchen'][$hk] += $dishKitchen;
                $totals['dishes']       += $dishCount;
                $totals['bar']          += $dishBar;
                $totals['kitchen']      += $dishKitchen;
            }
        }

        $last = end($batch);
        $nextTrId = is_array($last) ? ($last['transaction_id'] ?? null) : null;
        return $nextTrId !== null ? (int)$nextTrId : null;
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

