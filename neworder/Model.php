<?php

require_once __DIR__ . '/../src/classes/Database.php';
require_once __DIR__ . '/../src/classes/PosterAPI.php';

class NewOrderModel
{
    private \App\Classes\Database $db;
    private ?\App\Classes\PosterAPI $posterApi;
    private int $spotId;

    public function __construct(\App\Classes\Database $db, ?\App\Classes\PosterAPI $posterApi, int $spotId)
    {
        $this->db = $db;
        $this->posterApi = $posterApi;
        $this->spotId = $spotId > 0 ? $spotId : 1;
    }

    public function getMenuGroups(string $trLang = 'ru'): array
    {
        $metaTable = $this->db->t('system_meta');
        $pmi = $this->db->t('poster_menu_items');
        $mw = $this->db->t('menu_workshops');
        $mwTr = $this->db->t('menu_workshop_tr');
        $mc = $this->db->t('menu_categories');
        $mcTr = $this->db->t('menu_category_tr');
        $mi = $this->db->t('menu_items');
        $miTr = $this->db->t('menu_item_tr');

        $lastMenuSyncAt = null;
        try {
            $row = $this->db->query("SELECT meta_value FROM {$metaTable} WHERE meta_key = 'menu_last_sync_at' LIMIT 1")->fetch();
            if (is_array($row) && !empty($row['meta_value'])) {
                $lastMenuSyncAt = (string)$row['meta_value'];
            }
        } catch (\Throwable $e) {
        }

        $rows = $this->db->query(
            "SELECT
                c.id AS category_id,
                COALESCE(NULLIF(ctr.name,''), NULLIF(c.name_raw,''), '') AS category_label,
                mi.id AS menu_item_id,
                p.poster_id,
                p.price_raw,
                COALESCE(NULLIF(itr.title,''), NULLIF(itr_ru.title,''), NULLIF(p.name_raw,''), '') AS title,
                COALESCE(NULLIF(itr.description,''), NULLIF(itr_ru.description,''), '') AS description,
                COALESCE(NULLIF(mi.image_url,''), '') AS image_url,
                COALESCE(mi.sort_order, 0) AS sort_order,
                COALESCE(c.sort_order, 0) AS category_sort
             FROM {$mi} mi
             JOIN {$pmi} p ON p.id = mi.poster_item_id AND p.is_active = 1
             JOIN {$mc} c ON c.id = mi.category_id AND c.show_on_site = 1
             JOIN {$mw} w ON w.id = c.workshop_id AND w.show_on_site = 1
             LEFT JOIN {$miTr} itr ON itr.item_id = mi.id AND itr.lang = ?
             LEFT JOIN {$miTr} itr_ru ON itr_ru.item_id = mi.id AND itr_ru.lang = 'ru'
             LEFT JOIN {$mcTr} ctr ON ctr.category_id = c.id AND ctr.lang = ?
             LEFT JOIN {$mwTr} wtr ON wtr.workshop_id = w.id AND wtr.lang = ?
             WHERE mi.is_published = 1
             ORDER BY
                c.sort_order ASC,
                category_label ASC,
                mi.sort_order ASC,
                title ASC",
            [$trLang, $trLang, $trLang]
        )->fetchAll();

        $groups = [];
        foreach ($rows as $it) {
            if (!is_array($it)) {
                continue;
            }

            $categoryId = (int)($it['category_id'] ?? 0);
            $categoryLabel = trim((string)($it['category_label'] ?? ''));
            if ($categoryId <= 0 || $categoryLabel === '') {
                continue;
            }

            if (!isset($groups[$categoryId])) {
                $groups[$categoryId] = [
                    'id' => $categoryId,
                    'title' => $categoryLabel,
                    'sort' => (int)($it['category_sort'] ?? 0),
                    'items' => [],
                ];
            }

            $title = trim((string)($it['title'] ?? ''));
            if ($title === '') {
                continue;
            }

            $priceRaw = (string)($it['price_raw'] ?? '');
            $price = is_numeric($priceRaw) ? (int)$priceRaw : null;

            $groups[$categoryId]['items'][] = [
                'id' => (int)($it['poster_id'] ?? 0),
                'menu_item_id' => (int)($it['menu_item_id'] ?? 0),
                'name' => $title,
                'desc' => trim((string)($it['description'] ?? '')),
                'price' => $price,
                'image_url' => trim((string)($it['image_url'] ?? '')),
                'sort' => (int)($it['sort_order'] ?? 0),
            ];
        }

        $out = array_values($groups);
        usort(
            $out,
            fn($a, $b) => ((int)($a['sort'] ?? 0) <=> (int)($b['sort'] ?? 0)) ?: strcmp((string)($a['title'] ?? ''), (string)($b['title'] ?? ''))
        );
        foreach ($out as &$g) {
            $items = isset($g['items']) && is_array($g['items']) ? $g['items'] : [];
            usort(
                $items,
                fn($a, $b) => ((int)($a['sort'] ?? 0) <=> (int)($b['sort'] ?? 0)) ?: strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''))
            );
            $g['items'] = $items;
        }
        unset($g);

        return [$out, $lastMenuSyncAt];
    }

    public function searchProducts(string $query, int $limit = 30): array
    {
        $q = trim($query);
        if ($q === '') {
            return [];
        }

        $all = $this->getPosterProductsCached();
        $out = [];

        foreach ($all as $p) {
            if (!is_array($p)) {
                continue;
            }

            if ((string)($p['hidden'] ?? '0') === '1') {
                continue;
            }

            $name = trim((string)($p['product_name'] ?? ''));
            if ($name === '') {
                continue;
            }

            if (mb_stripos($name, $q) === false) {
                continue;
            }

            $price = $this->extractProductPrice($p);
            $out[] = [
                'id' => (int)($p['product_id'] ?? 0),
                'name' => $name,
                'desc' => trim((string)($p['category_name'] ?? '')),
                'price' => $price,
            ];

            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    public function createIncomingOrder(string $phoneE164, string $name, int $serviceMode, array $products): array
    {
        if (!$this->posterApi) {
            throw new \RuntimeException('Poster API Token not set');
        }

        $orderData = [
            'spot_id' => $this->spotId,
            'phone' => $phoneE164,
            'first_name' => $name,
            'service_mode' => $serviceMode,
            'products' => $products,
        ];

        $resp = $this->posterApi->request('incomingOrders.createIncomingOrder', $orderData, 'POST', true);
        $orderId = (int)($resp['incoming_order_id'] ?? $resp['id'] ?? 0);
        if ($orderId <= 0) {
            return ['order_id' => 0, 'raw' => $resp];
        }

        return ['order_id' => $orderId];
    }

    private function getPosterProductsCached(): array
    {
        if (!$this->posterApi) {
            throw new \RuntimeException('Poster API Token not set');
        }

        $ttlSec = 300;
        $now = time();
        $cached = '';
        $cachedAt = 0;

        try {
            $meta = $this->db->t('system_meta');
            $row = $this->db->query("SELECT meta_value FROM {$meta} WHERE meta_key = 'poster_products_cache_json' LIMIT 1")->fetch();
            $cached = (string)($row['meta_value'] ?? '');
            $row2 = $this->db->query("SELECT meta_value FROM {$meta} WHERE meta_key = 'poster_products_cache_updated_at' LIMIT 1")->fetch();
            $cachedAt = (int)($row2['meta_value'] ?? 0);
        } catch (\Throwable $e) {
        }

        if ($cached !== '' && $cachedAt > 0 && ($now - $cachedAt) < $ttlSec) {
            $decoded = json_decode($cached, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $resp = $this->posterApi->request('menu.getProducts', ['type' => 'products']);
        $products = is_array($resp) ? $resp : [];

        try {
            $meta = $this->db->t('system_meta');
            $this->db->query(
                "INSERT INTO {$meta} (meta_key, meta_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)",
                ['poster_products_cache_json', json_encode($products, JSON_UNESCAPED_UNICODE)]
            );
            $this->db->query(
                "INSERT INTO {$meta} (meta_key, meta_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)",
                ['poster_products_cache_updated_at', (string)$now]
            );
        } catch (\Throwable $e) {
        }

        return $products;
    }

    private function extractProductPrice(array $p): ?int
    {
        $spots = $p['spots'] ?? null;
        if (is_array($spots)) {
            foreach ($spots as $s) {
                if (!is_array($s)) {
                    continue;
                }
                if ((int)($s['spot_id'] ?? 0) !== $this->spotId) {
                    continue;
                }
                if ((string)($s['visible'] ?? '1') === '0') {
                    continue;
                }
                $v = $s['price'] ?? null;
                if (is_numeric($v)) {
                    return (int)$v;
                }
                if (is_string($v) && preg_match('/^\d+$/', $v)) {
                    return (int)$v;
                }
            }
        }

        $price = $p['price'] ?? null;
        if (is_array($price)) {
            $key = (string)$this->spotId;
            if (isset($price[$key]) && is_numeric($price[$key])) {
                return (int)$price[$key];
            }
            foreach ($price as $v) {
                if (is_numeric($v)) {
                    return (int)$v;
                }
                if (is_string($v) && preg_match('/^\d+$/', $v)) {
                    return (int)$v;
                }
            }
        }

        return null;
    }
}

