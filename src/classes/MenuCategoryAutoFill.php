<?php

namespace App\Classes;

class MenuCategoryAutoFill {
    private Database $db;

    public function __construct(Database $db) {
        $this->db = $db;
    }

    public function run(): array {
        $this->db->createMenuTables();

        $main = $this->db->query("SELECT id, name_raw FROM menu_categories_main ORDER BY id ASC")->fetchAll();
        $sub = $this->db->query("SELECT id, name_raw FROM menu_categories_sub ORDER BY id ASC")->fetchAll();

        $updatedMain = 0;
        $updatedSub = 0;
        $updatedTr = 0;

        foreach ($main as $r) {
            $id = (int)($r['id'] ?? 0);
            $raw = (string)($r['name_raw'] ?? '');
            if ($id <= 0) continue;
            $clean = $this->stripPrefix($raw);
            if ($clean !== $raw && $clean !== '') {
                $this->db->query("UPDATE menu_categories_main SET name_raw=? WHERE id=?", [$clean, $id]);
                $updatedMain++;
            }
            $tr = $this->translate($clean !== '' ? $clean : $raw);
            $updatedTr += $this->upsertTr('menu_categories_main_tr', 'main_category_id', $id, $tr);
        }

        foreach ($sub as $r) {
            $id = (int)($r['id'] ?? 0);
            $raw = (string)($r['name_raw'] ?? '');
            if ($id <= 0) continue;
            $clean = $this->stripPrefix($raw);
            if ($clean !== $raw && $clean !== '') {
                $this->db->query("UPDATE menu_categories_sub SET name_raw=? WHERE id=?", [$clean, $id]);
                $updatedSub++;
            }
            $tr = $this->translate($clean !== '' ? $clean : $raw);
            $updatedTr += $this->upsertTr('menu_categories_sub_tr', 'sub_category_id', $id, $tr);
        }

        $filledMainIds = 0;
        $filledSubIds = 0;
        foreach (['menu_items_ru', 'menu_items_en', 'menu_items_vn'] as $table) {
            $res1 = $this->db->query(
                "UPDATE {$table} mi
                 JOIN poster_menu_items p ON p.id = mi.poster_item_id
                 LEFT JOIN menu_categories_main m ON m.poster_main_category_id = p.main_category_id
                 SET mi.main_category_id = COALESCE(mi.main_category_id, m.id)
                 WHERE mi.main_category_id IS NULL"
            );
            $filledMainIds += (int)($res1->rowCount() ?? 0);

            $res2 = $this->db->query(
                "UPDATE {$table} mi
                 JOIN poster_menu_items p ON p.id = mi.poster_item_id
                 LEFT JOIN menu_categories_sub s ON s.poster_sub_category_id = p.sub_category_id
                 SET mi.sub_category_id = COALESCE(mi.sub_category_id, s.id)
                 WHERE mi.sub_category_id IS NULL"
            );
            $filledSubIds += (int)($res2->rowCount() ?? 0);
        }

        return [
            'ok' => true,
            'main_count' => count($main),
            'sub_count' => count($sub),
            'main_cleaned' => $updatedMain,
            'sub_cleaned' => $updatedSub,
            'translations_upserted' => $updatedTr,
            'main_ids_filled' => $filledMainIds,
            'sub_ids_filled' => $filledSubIds,
        ];
    }

    private function upsertTr(string $table, string $idCol, int $id, array $tr): int {
        $count = 0;
        foreach (['ru', 'en', 'vn'] as $lang) {
            $name = trim((string)($tr[$lang] ?? ''));
            if ($name === '') continue;
            $this->db->query(
                "INSERT INTO {$table} ({$idCol}, lang, name) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    name=IF({$table}.name='' OR {$table}.name=VALUES(name), VALUES(name), {$table}.name)",
                [$id, $lang, $name]
            );
            $count++;
        }
        return $count;
    }

    private function stripPrefix(string $s): string {
        $s = trim($s);
        if ($s === '') return '';
        $s2 = preg_replace('/^\s*[\d\.,\-\)\(]+(?:\s+)?/u', '', $s);
        $out = trim($s2 ?? $s);
        $out2 = preg_replace('/^\s*\.\s*/u', '', $out);
        return trim($out2 ?? $out);
    }

    private function translate(string $raw): array {
        $s = $this->stripPrefix($raw);
        if ($s === '') {
            $s = trim($raw);
        }

        $ru = '';
        $en = '';
        $vn = '';

        if (strpos($s, '/') !== false) {
            [$left, $right] = explode('/', $s, 2);
            $left = trim($left);
            $right = trim($right);
            $leftHasRu = (bool)preg_match('/\p{Cyrillic}/u', $left);
            $rightHasEn = (bool)preg_match('/[A-Za-z]/', $right);
            if ($leftHasRu && $rightHasEn) {
                $ru = $left;
                $en = $right;
                $vn = $right;
                return ['ru' => $ru, 'en' => $en, 'vn' => $vn];
            }
        }

        $low = mb_strtolower($s, 'UTF-8');
        $low = preg_replace('/\s+/u', ' ', $low) ?? $low;
        $dict = $this->dict();
        if (array_key_exists($low, $dict)) {
            return $dict[$low];
        }

        $hasRu = (bool)preg_match('/\p{Cyrillic}/u', $s);
        if ($hasRu) {
            $ru = $s;
            $en = $s;
            $vn = $s;
        } else {
            $en = $s;
            $ru = $s;
            $vn = $s;
        }
        return ['ru' => $ru, 'en' => $en, 'vn' => $vn];
    }

    private function dict(): array {
        return [
            'burgers' => ['ru' => 'Бургеры', 'en' => 'Burgers', 'vn' => 'Burger'],
            'salads' => ['ru' => 'Салаты', 'en' => 'Salads', 'vn' => 'Salad'],
            'soups' => ['ru' => 'Супы', 'en' => 'Soups', 'vn' => 'Súp'],
            'snacks' => ['ru' => 'Закуски', 'en' => 'Snacks', 'vn' => 'Ăn vặt'],
            'starters' => ['ru' => 'Закуски', 'en' => 'Starters', 'vn' => 'Khai vị'],
            'bbq' => ['ru' => 'Гриль', 'en' => 'BBQ', 'vn' => 'Nướng'],
            'main dishes' => ['ru' => 'Основные блюда', 'en' => 'Main Dishes', 'vn' => 'Món chính'],
            'desserts' => ['ru' => 'Десерты', 'en' => 'Desserts', 'vn' => 'Tráng miệng'],
            'drinks' => ['ru' => 'Напитки', 'en' => 'Drinks', 'vn' => 'Đồ uống'],
            'coffee' => ['ru' => 'Кофе', 'en' => 'Coffee', 'vn' => 'Cà phê'],
            'tea' => ['ru' => 'Чай', 'en' => 'Tea', 'vn' => 'Trà'],
            'fruits&milks' => ['ru' => 'Фрукты и молочные', 'en' => 'Fruits & Milks', 'vn' => 'Nước ép & Sữa'],
            'mocktails' => ['ru' => 'Моктейли', 'en' => 'Mocktails', 'vn' => 'Mocktail'],
            'cocktails' => ['ru' => 'Коктейли', 'en' => 'Cocktails', 'vn' => 'Cocktail'],
            'strong alcohol' => ['ru' => 'Крепкий алкоголь', 'en' => 'Strong Alcohol', 'vn' => 'Rượu mạnh'],
            'beer' => ['ru' => 'Пиво', 'en' => 'Beer', 'vn' => 'Bia'],
            'wine' => ['ru' => 'Вино', 'en' => 'Wine', 'vn' => 'Rượu vang'],
            'shots' => ['ru' => 'Шоты', 'en' => 'Shots', 'vn' => 'Shots'],
            'soft drinks' => ['ru' => 'Безалкогольные', 'en' => 'Soft Drinks', 'vn' => 'Nước ngọt'],
        ];
    }
}
