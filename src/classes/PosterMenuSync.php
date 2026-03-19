<?php

namespace App\Classes;

class PosterMenuSync {
    private PosterAPI $api;
    private Database $db;

    public function __construct(PosterAPI $api, Database $db) {
        $this->api = $api;
        $this->db = $db;
    }

    public function sync(): array {
        $startedAt = microtime(true);
        $this->db->createMenuTables();

        $categories = $this->fetchCategories();
        $catMainMap = $this->upsertMainCategories($categories['main']);
        $catSubMap = $this->upsertSubCategories($categories['sub'], $catMainMap);
        $workshops = $this->fetchWorkshops();

        $products = $this->fetchProducts();

        $seenPosterIds = [];
        foreach ($products as $p) {
            $posterId = (int)($p['product_id'] ?? $p['id'] ?? 0);
            if ($posterId <= 0) {
                continue;
            }
            if (!$this->isProductVisible($p)) {
                continue;
            }
            $seenPosterIds[$posterId] = true;

            $nameRaw = (string)($p['product_name'] ?? $p['name'] ?? '');
            $priceRaw = $this->normalizePrice($this->extractPriceValue($p));
            $costRaw = $this->normalizePrice($p['cost'] ?? null);

            $workshopId = (int)($p['workshop'] ?? $p['workshop_id'] ?? 0);
            $workshopName = $workshopId > 0 ? ($workshops[$workshopId] ?? null) : null;

            $mainPosterCatId = (int)($p['category_id'] ?? $p['menu_category_id'] ?? $p['main_category_id'] ?? 0);
            $subPosterCatId = (int)($p['sub_category_id'] ?? $p['menu_category_id2'] ?? $p['category2_id'] ?? 0);

            if ($subPosterCatId <= 0 && $mainPosterCatId > 0 && isset($categories['sub_parent'][$mainPosterCatId])) {
                $subPosterCatId = $mainPosterCatId;
                $mainPosterCatId = (int)$categories['sub_parent'][$subPosterCatId];
            }

            $mainCatName = $categories['main_names'][$mainPosterCatId] ?? (string)($p['category_name'] ?? $p['main_category_name'] ?? '');
            $subCatName = $categories['sub_names'][$subPosterCatId] ?? (string)($p['sub_category_name'] ?? $p['category2_name'] ?? '');

            $rawJson = json_encode($p, JSON_UNESCAPED_UNICODE);

            $this->db->query(
                "INSERT INTO poster_menu_items
                    (poster_id, name_raw, price_raw, cost_raw, station_id, station_name, main_category_id, main_category_name, sub_category_id, sub_category_name, raw_json, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
                 ON DUPLICATE KEY UPDATE
                    name_raw=VALUES(name_raw),
                    price_raw=VALUES(price_raw),
                    cost_raw=VALUES(cost_raw),
                    station_id=VALUES(station_id),
                    station_name=VALUES(station_name),
                    main_category_id=VALUES(main_category_id),
                    main_category_name=VALUES(main_category_name),
                    sub_category_id=VALUES(sub_category_id),
                    sub_category_name=VALUES(sub_category_name),
                    raw_json=VALUES(raw_json),
                    is_active=1",
                [
                    $posterId,
                    $nameRaw === '' ? '—' : $nameRaw,
                    $priceRaw,
                    $costRaw,
                    $workshopId > 0 ? $workshopId : null,
                    $workshopName,
                    $mainPosterCatId ?: null,
                    $mainCatName !== '' ? $mainCatName : null,
                    $subPosterCatId ?: null,
                    $subCatName !== '' ? $subCatName : null,
                    $rawJson !== false ? $rawJson : null,
                ]
            );

            $posterRow = $this->db->query("SELECT id FROM poster_menu_items WHERE poster_id = ? LIMIT 1", [$posterId])->fetch();
            $posterItemId = (int)($posterRow['id'] ?? 0);
            if ($posterItemId <= 0) {
                continue;
            }

            $mainId = $mainPosterCatId > 0 ? ($catMainMap[$mainPosterCatId] ?? null) : null;
            $subId = $subPosterCatId > 0 ? ($catSubMap[$subPosterCatId] ?? null) : null;

            $this->db->query(
                "INSERT INTO menu_items_ru (poster_item_id, title, main_category_id, sub_category_id, sub_category, description, image_url, is_published, sort_order)
                 VALUES (?, NULL, ?, ?, NULL, NULL, NULL, 0, 0)
                 ON DUPLICATE KEY UPDATE
                    main_category_id = COALESCE(menu_items_ru.main_category_id, VALUES(main_category_id)),
                    sub_category_id = COALESCE(menu_items_ru.sub_category_id, VALUES(sub_category_id))",
                [$posterItemId, $mainId, $subId]
            );

            $this->db->query(
                "INSERT INTO menu_items_en (poster_item_id, title, main_category_id, sub_category_id, sub_category, description)
                 VALUES (?, NULL, ?, ?, NULL, NULL)
                 ON DUPLICATE KEY UPDATE
                    main_category_id = COALESCE(menu_items_en.main_category_id, VALUES(main_category_id)),
                    sub_category_id = COALESCE(menu_items_en.sub_category_id, VALUES(sub_category_id))",
                [$posterItemId, $mainId, $subId]
            );

            $this->db->query(
                "INSERT INTO menu_items_vn (poster_item_id, title, main_category_id, sub_category_id, sub_category, description)
                 VALUES (?, NULL, ?, ?, NULL, NULL)
                 ON DUPLICATE KEY UPDATE
                    main_category_id = COALESCE(menu_items_vn.main_category_id, VALUES(main_category_id)),
                    sub_category_id = COALESCE(menu_items_vn.sub_category_id, VALUES(sub_category_id))",
                [$posterItemId, $mainId, $subId]
            );

            $this->db->query(
                "INSERT INTO menu_items_ko (poster_item_id, title, main_category_id, sub_category_id, sub_category, description)
                 VALUES (?, NULL, ?, ?, NULL, NULL)
                 ON DUPLICATE KEY UPDATE
                    main_category_id = COALESCE(menu_items_ko.main_category_id, VALUES(main_category_id)),
                    sub_category_id = COALESCE(menu_items_ko.sub_category_id, VALUES(sub_category_id))",
                [$posterItemId, $mainId, $subId]
            );
        }

        $this->markMissingItemsInactive(array_keys($seenPosterIds));
        $durationMs = (int)round((microtime(true) - $startedAt) * 1000);

        return [
            'ok' => true,
            'duration_ms' => $durationMs,
            'items_seen' => count($seenPosterIds),
            'main_categories' => count($catMainMap),
            'sub_categories' => count($catSubMap),
        ];
    }

    private function isProductVisible(array $p): bool {
        if (isset($p['spots']) && is_array($p['spots']) && !empty($p['spots'])) {
            foreach ($p['spots'] as $spot) {
                if (!is_array($spot)) continue;
                if (array_key_exists('visible', $spot)) {
                    if ((int)$spot['visible'] === 1) {
                        return true;
                    }
                }
            }
            return false;
        }
        if (array_key_exists('visible', $p)) {
            return (int)$p['visible'] === 1;
        }
        if (array_key_exists('hidden', $p)) {
            return (int)$p['hidden'] !== 1;
        }
        return true;
    }

    private function fetchProducts(): array {
        try {
            $res = $this->api->request('menu.getProducts');
            return is_array($res) ? $res : [];
        } catch (\Exception $e) {
            return [];
        }
    }

    private function fetchWorkshops(): array {
        $map = [];
        $res = null;
        try {
            $res = $this->api->request('menu.getWorkshops');
        } catch (\Exception $e) {
        }
        if ($res === null) {
            try {
                $res = $this->api->request('menu.getWorkshop');
            } catch (\Exception $e) {
                $res = null;
            }
        }
        if (is_array($res)) {
            foreach ($res as $w) {
                $id = (int)($w['workshop_id'] ?? $w['id'] ?? 0);
                $name = trim((string)($w['workshop_name'] ?? $w['name'] ?? ''));
                if ($id > 0 && $name !== '') {
                    $map[$id] = $name;
                }
            }
        }
        return $map;
    }

    private function fetchCategories(): array {
        $main = [];
        $sub = [];
        $mainNames = [];
        $subNames = [];
        $subParent = [];

        try {
            $res = $this->api->request('menu.getCategories');
            if (is_array($res)) {
                foreach ($res as $c) {
                    $id = (int)($c['category_id'] ?? $c['id'] ?? 0);
                    $name = (string)($c['category_name'] ?? $c['name'] ?? '');
                    $parent = (int)($c['parent_category'] ?? $c['parent_id'] ?? $c['parentCategoryId'] ?? 0);
                    if ($id <= 0) {
                        continue;
                    }
                    if ($parent <= 0) {
                        $main[] = ['id' => $id, 'name' => $name];
                        $mainNames[$id] = $name;
                    } else {
                        $sub[] = ['id' => $id, 'name' => $name, 'parent' => $parent];
                        $subNames[$id] = $name;
                        $subParent[$id] = $parent;
                    }
                }
            }
        } catch (\Exception $e) {
        }

        return [
            'main' => $main,
            'sub' => $sub,
            'main_names' => $mainNames,
            'sub_names' => $subNames,
            'sub_parent' => $subParent
        ];
    }

    private function upsertMainCategories(array $items): array {
        $map = [];
        foreach ($items as $item) {
            $id = (int)($item['id'] ?? 0);
            $name = (string)($item['name'] ?? '');
            if ($id <= 0 || $name === '') {
                continue;
            }
            $sort = $this->extractLeadingSortNumber($name);
            $this->db->query(
                "INSERT INTO menu_categories_main (poster_main_category_id, name_raw, sort_order)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    name_raw=VALUES(name_raw),
                    sort_order=IF(menu_categories_main.sort_order=0, VALUES(sort_order), menu_categories_main.sort_order)",
                [$id, $name, $sort]
            );
        }
        $rows = $this->db->query("SELECT id, poster_main_category_id FROM menu_categories_main")->fetchAll();
        foreach ($rows as $r) {
            $map[(int)$r['poster_main_category_id']] = (int)$r['id'];
        }
        return $map;
    }

    private function upsertSubCategories(array $items, array $mainMap): array {
        $map = [];
        foreach ($items as $item) {
            $id = (int)($item['id'] ?? 0);
            $name = (string)($item['name'] ?? '');
            $parent = (int)($item['parent'] ?? 0);
            if ($id <= 0 || $name === '') {
                continue;
            }
            $mainId = $parent > 0 ? ($mainMap[$parent] ?? null) : null;
            $sort = $this->extractLeadingSortNumber($name);
            $this->db->query(
                "INSERT INTO menu_categories_sub (poster_sub_category_id, main_category_id, name_raw, sort_order)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    name_raw=VALUES(name_raw),
                    main_category_id=VALUES(main_category_id),
                    sort_order=IF(menu_categories_sub.sort_order=0, VALUES(sort_order), menu_categories_sub.sort_order)",
                [$id, $mainId, $name, $sort]
            );
        }
        $rows = $this->db->query("SELECT id, poster_sub_category_id FROM menu_categories_sub")->fetchAll();
        foreach ($rows as $r) {
            $map[(int)$r['poster_sub_category_id']] = (int)$r['id'];
        }
        return $map;
    }

    private function extractLeadingSortNumber(string $name): int {
        $s = trim($name);
        if ($s === '') return 0;
        if (preg_match('/^\s*(\d{1,3})/u', $s, $m)) {
            return (int)$m[1];
        }
        return 999;
    }

    private function markMissingItemsInactive(array $activePosterIds): void {
        if (empty($activePosterIds)) {
            $this->db->query("UPDATE poster_menu_items SET is_active = 0 WHERE is_active = 1");
            $this->db->query("UPDATE menu_items_ru SET is_published = 0 WHERE is_published = 1");
            return;
        }

        $placeholders = implode(',', array_fill(0, count($activePosterIds), '?'));
        $this->db->query("UPDATE poster_menu_items SET is_active = 0 WHERE is_active = 1 AND poster_id NOT IN ($placeholders)", $activePosterIds);

        $inactive = $this->db->query(
            "SELECT r.id
             FROM menu_items_ru r
             JOIN poster_menu_items p ON p.id = r.poster_item_id
             WHERE p.is_active = 0 AND r.is_published = 1"
        )->fetchAll();
        if (!empty($inactive)) {
            $ids = array_map(fn($r) => (int)$r['id'], $inactive);
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $this->db->query("UPDATE menu_items_ru SET is_published = 0 WHERE id IN ($ph)", $ids);
        }
    }

    private function normalizePrice($price): ?float {
        if ($price === null || $price === '') {
            return null;
        }
        if (is_string($price)) {
            $price = str_replace(',', '.', $price);
        }
        if (is_numeric($price)) {
            $v = (float)$price;
            if ($v > 10000) {
                $v = $v / 100;
            }
            return round($v, 2);
        }
        return null;
    }

    private function extractPriceValue(array $product) {
        if (array_key_exists('price', $product)) {
            $price = $product['price'];
            if (is_array($price)) {
                foreach ($price as $v) {
                    if ($v !== null && $v !== '') {
                        return $v;
                    }
                }
            } else {
                return $price;
            }
        }
        if (array_key_exists('price_raw', $product)) {
            return $product['price_raw'];
        }
        if (array_key_exists('cost', $product)) {
            return $product['cost'];
        }
        return null;
    }
}
