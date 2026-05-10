<?php

declare(strict_types=1);

namespace App\Classes\TestAI\Repository;

use App\Classes\Database;

/**
 * Reads published menu directly from DB.
 * Only exposes price_raw (selling price). cost_raw is never selected.
 */
class MenuRepository {
    public function __construct(private Database $db) {}

    /**
     * Returns all published menu items with section, category, title, description, price.
     * lang: 'ru' | 'en' | 'vi' | 'ko'
     */
    public function getPublishedItems(string $lang = 'ru'): array {
        $lang = in_array($lang, ['ru', 'en', 'vi', 'ko'], true) ? $lang : 'ru';
        try {
            $rows = $this->db->query(
                "SELECT
                    wt.name           AS section,
                    ct.name           AS category,
                    it.title          AS title,
                    it.description    AS description,
                    p.price_raw       AS price_raw
                 FROM menu_items i
                 JOIN menu_item_tr it      ON it.item_id    = i.id         AND it.lang = ?
                 JOIN poster_menu_items p  ON p.id          = i.poster_item_id
                 JOIN menu_categories c   ON c.id           = i.category_id
                 JOIN menu_workshops w    ON w.id           = c.workshop_id
                 LEFT JOIN menu_workshop_tr wt ON wt.workshop_id = w.id   AND wt.lang = ?
                 LEFT JOIN menu_category_tr ct ON ct.category_id = c.id   AND ct.lang = ?
                 WHERE i.is_published = 1
                   AND p.is_active    = 1
                   AND w.show_on_site = 1
                 ORDER BY w.sort_order, c.sort_order, i.sort_order",
                [$lang, $lang, $lang]
            )->fetchAll();
            return is_array($rows) ? $rows : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Search published items by name.
     */
    public function searchByName(string $query, string $lang = 'ru'): array {
        $lang = in_array($lang, ['ru', 'en', 'vi', 'ko'], true) ? $lang : 'ru';
        $like = '%' . mb_strtolower(trim($query)) . '%';
        try {
            $rows = $this->db->query(
                "SELECT
                    wt.name        AS section,
                    ct.name        AS category,
                    it.title       AS title,
                    it.description AS description,
                    p.price_raw    AS price_raw
                 FROM menu_items i
                 JOIN menu_item_tr it      ON it.item_id    = i.id         AND it.lang = ?
                 JOIN poster_menu_items p  ON p.id          = i.poster_item_id
                 JOIN menu_categories c   ON c.id           = i.category_id
                 JOIN menu_workshops w    ON w.id           = c.workshop_id
                 LEFT JOIN menu_workshop_tr wt ON wt.workshop_id = w.id   AND wt.lang = ?
                 LEFT JOIN menu_category_tr ct ON ct.category_id = c.id   AND ct.lang = ?
                 WHERE i.is_published = 1
                   AND p.is_active    = 1
                   AND (LOWER(it.title) LIKE ? OR LOWER(it.description) LIKE ?)
                 ORDER BY w.sort_order, c.sort_order, i.sort_order
                 LIMIT 30",
                [$lang, $lang, $lang, $like, $like]
            )->fetchAll();
            return is_array($rows) ? $rows : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Format menu items as compact text for Gemini context.
     * Groups by section → category.
     */
    public static function formatForPrompt(array $items): string {
        if (!$items) return '';
        $grouped = [];
        foreach ($items as $it) {
            $sec = trim((string)($it['section'] ?? 'Меню'));
            $cat = trim((string)($it['category'] ?? ''));
            $key = $sec . ($cat !== '' && $cat !== $sec ? ' / ' . $cat : '');
            $title = trim((string)($it['title'] ?? ''));
            $price = (int)($it['price_raw'] ?? 0);
            if ($title === '') continue;
            $grouped[$key][] = $title . ($price > 0 ? ' — ' . number_format($price, 0, '.', ' ') . ' ₫' : '');
        }
        $lines = [];
        foreach ($grouped as $section => $dishes) {
            $lines[] = "## {$section}";
            foreach ($dishes as $d) $lines[] = '  ' . $d;
        }
        return implode("\n", $lines);
    }
}
