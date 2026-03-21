<?php

namespace App\Classes;

class MenuCategoryAutoFill {
    private Database $db;

    public function __construct(Database $db) {
        $this->db = $db;
    }

    public function run(): array {
        $this->db->createMenuTables();

        $mw = $this->db->t('menu_workshops');
        $mc = $this->db->t('menu_categories');

        $workshops = $this->db->query("SELECT id, name_raw FROM {$mw} ORDER BY id ASC")->fetchAll();
        $categories = $this->db->query("SELECT id, name_raw FROM {$mc} ORDER BY id ASC")->fetchAll();

        $updatedWorkshops = 0;
        $updatedCategories = 0;
        $updatedTr = 0;

        foreach ($workshops as $r) {
            $id = (int)($r['id'] ?? 0);
            $raw = (string)($r['name_raw'] ?? '');
            if ($id <= 0) continue;
            $clean = $this->stripPrefix($raw);
            if ($clean !== $raw && $clean !== '') {
                $this->db->query("UPDATE {$mw} SET name_raw=? WHERE id=?", [$clean, $id]);
                $updatedWorkshops++;
            }
            $tr = $this->translate($clean !== '' ? $clean : $raw);
            $updatedTr += $this->upsertTr($this->db->t('menu_workshop_tr'), 'workshop_id', $id, $tr);
        }

        foreach ($categories as $r) {
            $id = (int)($r['id'] ?? 0);
            $raw = (string)($r['name_raw'] ?? '');
            if ($id <= 0) continue;
            $clean = $this->stripPrefix($raw);
            if ($clean !== $raw && $clean !== '') {
                $this->db->query("UPDATE {$mc} SET name_raw=? WHERE id=?", [$clean, $id]);
                $updatedCategories++;
            }
            $tr = $this->translate($clean !== '' ? $clean : $raw);
            $updatedTr += $this->upsertTr($this->db->t('menu_category_tr'), 'category_id', $id, $tr);
        }

        return [
            'ok' => true,
            'workshops_count' => count($workshops),
            'categories_count' => count($categories),
            'workshops_cleaned' => $updatedWorkshops,
            'categories_cleaned' => $updatedCategories,
            'translations_upserted' => $updatedTr,
        ];
    }

    private function upsertTr(string $table, string $idCol, int $id, array $tr): int {
        $count = 0;
        foreach (['ru', 'en', 'vn', 'ko'] as $lang) {
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
