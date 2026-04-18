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
