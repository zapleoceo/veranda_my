<?php
namespace Banya;

class Model {
    private $api;
    private $token;

    const BANYA_HALL_ID = 9;
    const HOOKAH_CATEGORY_ID = 47;
    const BANYA_TABLES_WITHOUT_DELETED = 1;

    public function __construct(string $token) {
        $this->token = $token;
        if ($token) {
            $this->api = new \App\Classes\PosterAPI($token);
        }
    }

    public function getApi() {
        return $this->api;
    }

    public function loadProductMap(): array {
        $products = $this->api->request('menu.getProducts', []);
        if (!is_array($products)) $products = [];
        $map = [];
        foreach ($products as $p) {
            if (!is_array($p)) continue;
            $pid = (int)($p['product_id'] ?? 0);
            if ($pid <= 0) continue;
            $map[$pid] = [
                'name' => (string)($p['product_name'] ?? ''),
                'category_id' => (int)($p['category_id'] ?? $p['menu_category_id'] ?? $p['main_category_id'] ?? 0),
                'menu_category_id' => (int)($p['menu_category_id'] ?? $p['category_id'] ?? $p['main_category_id'] ?? 0),
                'sub_category_id' => (int)($p['sub_category_id'] ?? $p['menu_category_id2'] ?? $p['category2_id'] ?? 0),
                'category_name' => (string)($p['category_name'] ?? $p['main_category_name'] ?? ''),
                'sub_category_name' => (string)($p['sub_category_name'] ?? $p['category2_name'] ?? ''),
            ];
        }
        return $map;
    }

    public function loadSpotIds(): array {
        $rows = $this->api->request('access.getSpots', [], 'GET');
        if (!is_array($rows)) $rows = [];
        $ids = [];
        foreach ($rows as $r) {
            if (!is_array($r)) continue;
            $sid = (int)($r['spot_id'] ?? $r['id'] ?? 0);
            if ($sid > 0) $ids[] = $sid;
        }
        $ids = array_values(array_unique($ids));
        sort($ids);
        return $ids;
    }

    public function loadTableHalls(int $spotId): array {
        if ($spotId <= 0) return [];
        $rows = $this->api->request('spots.getTableHallTables', [
            'spot_id' => $spotId,
            'without_deleted' => self::BANYA_TABLES_WITHOUT_DELETED,
        ], 'GET');
        if (!is_array($rows)) $rows = [];
        $map = [];
        foreach ($rows as $r) {
            if (!is_array($r)) continue;
            $tid = (int)($r['table_id'] ?? 0);
            $hid = (int)($r['hall_id'] ?? 0);
            if ($tid > 0 && $hid > 0) $map[$tid] = $hid;
        }
        return $map;
    }

    public function loadTablesForHall(int $spotId, int $hallId): array {
        if ($spotId <= 0 || $hallId <= 0) return [];
        $rows = $this->api->request('spots.getTableHallTables', [
            'spot_id' => $spotId,
            'hall_id' => $hallId,
            'without_deleted' => self::BANYA_TABLES_WITHOUT_DELETED,
        ], 'GET');
        if (!is_array($rows)) $rows = [];
        $out = [];
        foreach ($rows as $r) {
            if (!is_array($r)) continue;
            $tid = (int)($r['table_id'] ?? 0);
            if ($tid <= 0) continue;
            $out[] = [
                'table_id' => $tid,
                'table_num' => (string)($r['table_num'] ?? ''),
                'table_title' => (string)($r['table_title'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * Итоги бани за период одним запросом dash.getTransactions — для
     * ежемесячного отчёта (cron → Telegram). Фильтры те же, что у страницы
     * /banya (ajax=load / load_day): закрытые чеки столов зала бани (+ стол
     * 141), кальяны = позиции категории 47. Пагинацию next_tr не используем:
     * месяц приходит одним ответом (~2 300 чеков, ~6 с), а цикл next_tr
     * растягивался на минуты и не влез бы в таймаут Cloudflare. Сверено:
     * сентябрь 2026 → 120 009 500, как на странице.
     *
     * @return array{checks:int, sum_minor:int, hookah_minor:int, without_minor:int, fetched:int}
     */
    public function periodTotals(string $dateFrom, string $dateTo): array {
        $productCat = [];
        foreach ($this->loadProductMap() as $pid => $p) {
            $productCat[$pid] = (int)$p['menu_category_id'];
        }
        $spotIds = $this->loadSpotIds() ?: [1];
        $hallByTable = [];
        foreach ($spotIds as $sid) {
            $rows = $this->api->request('spots.getTableHallTables', [
                'spot_id' => (int)$sid,
                'without_deleted' => 0,
            ], 'GET');
            foreach (is_array($rows) ? $rows : [] as $r) {
                if (!is_array($r)) continue;
                $tid = (int)($r['table_id'] ?? 0);
                $hid = (int)($r['hall_id'] ?? $r['table_hall_id'] ?? 0);
                if ($tid > 0 && $hid > 0) $hallByTable[$tid] = $hid;
            }
        }

        $batch = $this->api->request('dash.getTransactions', [
            'dateFrom' => str_replace('-', '', $dateFrom),
            'dateTo' => str_replace('-', '', $dateTo),
            'include_products' => 'true',
            'status' => 2,
        ], 'GET');
        $batch = is_array($batch) ? $batch : [];

        $seen = [];
        $checks = 0;
        $sumMinor = 0;
        $hookahMinor = 0;
        foreach ($batch as $tx) {
            if (!is_array($tx)) continue;
            $txId = (int)($tx['transaction_id'] ?? 0);
            if ($txId <= 0 || isset($seen[$txId])) continue;
            $seen[$txId] = true;
            $tableId = (int)($tx['table_id'] ?? 0);
            if ($tableId <= 0) continue;
            if ((int)($hallByTable[$tableId] ?? 0) !== self::BANYA_HALL_ID && $tableId !== 141) continue;

            $checkSum = (int)($tx['payed_sum'] ?? $tx['sum'] ?? 0);
            if ($checkSum <= 0) continue;

            $hookahInCheck = 0;
            foreach (is_array($tx['products'] ?? null) ? $tx['products'] : [] as $p) {
                if (!is_array($p)) continue;
                if (($productCat[(int)($p['product_id'] ?? 0)] ?? 0) !== self::HOOKAH_CATEGORY_ID) continue;
                $numRaw = $p['num'] ?? $p['count'] ?? 0;
                $num = is_numeric($numRaw) ? (float)$numRaw : 0;
                $line = isset($p['payed_sum']) ? (int)$p['payed_sum'] : (int)($p['product_sum'] ?? 0);
                if ($line <= 0) $line = (int)round(((int)($p['product_price'] ?? 0)) * $num);
                if ($line > 0) $hookahInCheck += $line;
            }

            $checks++;
            $sumMinor += $checkSum;
            $hookahMinor += $hookahInCheck;
        }

        return [
            'checks' => $checks,
            'sum_minor' => $sumMinor,
            'hookah_minor' => $hookahMinor,
            'without_minor' => $sumMinor - $hookahMinor,
            'fetched' => count($batch),
        ];
    }

    public function parseDate(string $s): ?string {
        $t = trim($s);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $t) ? $t : null;
    }

    public function fmtVnd($minor): string {
        $vnd = (int)round(((float)$minor) / 100);
        return number_format($vnd, 0, '.', ' ');
    }

    public function fmtTs(?int $ms): string {
        if (!$ms || $ms <= 0) return '';
        $dt = new \DateTime('@' . (int)round($ms / 1000));
        $dt->setTimezone(new \DateTimeZone('Asia/Ho_Chi_Minh'));
        return $dt->format('Y-m-d H:i:s');
    }
}
