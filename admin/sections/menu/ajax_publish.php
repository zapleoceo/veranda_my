<?php

require_once __DIR__ . '/../../lib/http.php';

function admin_menu_ajax_publish(\App\Classes\Database $db): void
{
    $pmi = $db->t('poster_menu_items');
    $mi = $db->t('menu_items');
    $miTr = $db->t('menu_item_tr');
    $mc = $db->t('menu_categories');

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        admin_json_exit(['ok' => false, 'error' => 'Method not allowed'], 405);
    }

    $payload = admin_read_json_body();
    $posterId = (int)($payload['poster_id'] ?? 0);
    $isPublished = isset($payload['is_published']) ? (bool)$payload['is_published'] : null;
    if ($posterId <= 0 || $isPublished === null) {
        admin_json_exit(['ok' => false, 'error' => 'Bad request'], 400);
    }

    $row = $db->query(
        "SELECT
                p.id poster_item_id,
                p.is_active,
                p.main_category_id,
                p.sub_category_id,
                mi.id menu_item_id,
                mi.is_published,
                ru.title ru_title,
                en.title en_title,
                vn.title vn_title
         FROM {$pmi} p
         LEFT JOIN {$mi} mi ON mi.poster_item_id = p.id
         LEFT JOIN {$miTr} ru ON ru.item_id = mi.id AND ru.lang = 'ru'
         LEFT JOIN {$miTr} en ON en.item_id = mi.id AND en.lang = 'en'
         LEFT JOIN {$miTr} vn ON vn.item_id = mi.id AND vn.lang = 'vn'
         WHERE p.poster_id = ?
         LIMIT 1",
        [$posterId]
    )->fetch();

    if (!$row) {
        admin_json_exit(['ok' => false, 'error' => 'Not found'], 404);
    }

    if ((int)$row['is_active'] === 0) {
        $menuItemId = (int)($row['menu_item_id'] ?? 0);
        if ($menuItemId > 0) {
            $db->query("UPDATE {$mi} SET is_published = 0 WHERE id = ? LIMIT 1", [$menuItemId]);
        }
        admin_json_exit(['ok' => true, 'is_published' => false, 'disabled' => true, 'reason' => 'not_found']);
    }

    $menuItemId = (int)($row['menu_item_id'] ?? 0);
    if ($menuItemId <= 0) {
        $subPosterCategoryId = (int)($row['sub_category_id'] ?? 0);
        if ($subPosterCategoryId > 0) {
            $categoryRow = $db->query("SELECT id FROM {$mc} WHERE poster_id = ? LIMIT 1", [$subPosterCategoryId])->fetch();
            $categoryId = (int)($categoryRow['id'] ?? 0);
            if ($categoryId > 0) {
                $posterItemId = (int)($row['poster_item_id'] ?? 0);
                $db->query(
                    "INSERT INTO {$mi} (poster_item_id, category_id, image_url, is_published, sort_order)
                     VALUES (?, ?, NULL, 0, 0)
                     ON DUPLICATE KEY UPDATE category_id = VALUES(category_id)",
                    [$posterItemId, $categoryId]
                );
                $menuItemId = (int)$db->query("SELECT id FROM {$mi} WHERE poster_item_id = ? LIMIT 1", [$posterItemId])->fetchColumn();
            }
        }
    }
    if ($menuItemId <= 0) {
        admin_json_exit(['ok' => false, 'error' => 'Нет записи menu_items для этого блюда. Выполните синхронизацию меню из Poster.'], 409);
    }

    if ($isPublished) {
        $ruTitle = trim((string)($row['ru_title'] ?? ''));
        $enTitle = trim((string)($row['en_title'] ?? ''));
        $vnTitle = trim((string)($row['vn_title'] ?? ''));
        if ($ruTitle === '' || $enTitle === '' || $vnTitle === '') {
            admin_json_exit(['ok' => false, 'error' => 'Неадаптировано: заполните названия RU/EN/VN перед публикацией'], 400);
        }
    }

    $db->query(
        "UPDATE {$mi} SET is_published = ? WHERE id = ? LIMIT 1",
        [$isPublished ? 1 : 0, $menuItemId]
    );

    admin_json_exit(['ok' => true, 'is_published' => $isPublished]);
}

