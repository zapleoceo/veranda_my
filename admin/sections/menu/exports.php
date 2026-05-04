<?php

function admin_menu_export_categories_csv(\App\Classes\Database $db): void
{
    $menuWorkshopsTable = $db->t('menu_workshops');
    $menuWorkshopsTrTable = $db->t('menu_workshop_tr');
    $menuCategoriesTable = $db->t('menu_categories');
    $menuCategoriesTrTable = $db->t('menu_category_tr');

    ignore_user_abort(true);
    @set_time_limit(300);
    header('Content-Type: text/csv; charset=utf-8');
    $file = 'menu_categories_' . (new DateTime('now', new DateTimeZone('Asia/Ho_Chi_Minh')))->format('Ymd_His') . '.csv';
    header('Content-Disposition: attachment; filename="' . $file . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    if ($out === false) {
        http_response_code(500);
        echo 'Cannot open output';
        exit;
    }
    fwrite($out, "\xEF\xBB\xBF");

    fputcsv($out, [
        'Тип',
        'Poster ID',
        'Parent Poster ID',
        'Raw',
        'RU',
        'EN',
        'VN',
        'KO',
        'Отображать',
        'Sort',
    ], ';');

    $wRows = $db->query(
        "SELECT
            w.poster_id,
            w.name_raw,
            w.sort_order,
            w.show_on_site,
            COALESCE(wtr_ru.name, '') name_ru,
            COALESCE(wtr_en.name, '') name_en,
            COALESCE(wtr_vn.name, '') name_vn,
            COALESCE(wtr_ko.name, '') name_ko
         FROM {$menuWorkshopsTable} w
         LEFT JOIN {$menuWorkshopsTrTable} wtr_ru ON wtr_ru.workshop_id = w.id AND wtr_ru.lang = 'ru'
         LEFT JOIN {$menuWorkshopsTrTable} wtr_en ON wtr_en.workshop_id = w.id AND wtr_en.lang = 'en'
         LEFT JOIN {$menuWorkshopsTrTable} wtr_vn ON wtr_vn.workshop_id = w.id AND wtr_vn.lang = 'vn'
         LEFT JOIN {$menuWorkshopsTrTable} wtr_ko ON wtr_ko.workshop_id = w.id AND wtr_ko.lang = 'ko'
         ORDER BY w.sort_order ASC, w.poster_id ASC"
    )->fetchAll();

    foreach ($wRows as $r) {
        fputcsv($out, [
            'workshop',
            (string)($r['poster_id'] ?? ''),
            '',
            (string)($r['name_raw'] ?? ''),
            (string)($r['name_ru'] ?? ''),
            (string)($r['name_en'] ?? ''),
            (string)($r['name_vn'] ?? ''),
            (string)($r['name_ko'] ?? ''),
            !empty($r['show_on_site']) ? '1' : '0',
            (string)($r['sort_order'] ?? '0'),
        ], ';');
    }

    $cRows = $db->query(
        "SELECT
            c.poster_id,
            w.poster_id workshop_poster_id,
            c.name_raw,
            c.sort_order,
            c.show_on_site,
            COALESCE(ctr_ru.name, '') name_ru,
            COALESCE(ctr_en.name, '') name_en,
            COALESCE(ctr_vn.name, '') name_vn,
            COALESCE(ctr_ko.name, '') name_ko
         FROM {$menuCategoriesTable} c
         LEFT JOIN {$menuWorkshopsTable} w ON w.id = c.workshop_id
         LEFT JOIN {$menuCategoriesTrTable} ctr_ru ON ctr_ru.category_id = c.id AND ctr_ru.lang = 'ru'
         LEFT JOIN {$menuCategoriesTrTable} ctr_en ON ctr_en.category_id = c.id AND ctr_en.lang = 'en'
         LEFT JOIN {$menuCategoriesTrTable} ctr_vn ON ctr_vn.category_id = c.id AND ctr_vn.lang = 'vn'
         LEFT JOIN {$menuCategoriesTrTable} ctr_ko ON ctr_ko.category_id = c.id AND ctr_ko.lang = 'ko'
         ORDER BY COALESCE(w.sort_order, 255) ASC, COALESCE(w.poster_id, 0) ASC, c.sort_order ASC, c.poster_id ASC"
    )->fetchAll();

    foreach ($cRows as $r) {
        fputcsv($out, [
            'category',
            (string)($r['poster_id'] ?? ''),
            (string)($r['workshop_poster_id'] ?? ''),
            (string)($r['name_raw'] ?? ''),
            (string)($r['name_ru'] ?? ''),
            (string)($r['name_en'] ?? ''),
            (string)($r['name_vn'] ?? ''),
            (string)($r['name_ko'] ?? ''),
            !empty($r['show_on_site']) ? '1' : '0',
            (string)($r['sort_order'] ?? '0'),
        ], ';');
    }

    fclose($out);
    exit;
}

function admin_menu_export_items_csv(\App\Classes\Database $db): void
{
    $posterMenuItemsTable = $db->t('poster_menu_items');
    $menuCategoriesTable = $db->t('menu_categories');
    $menuCategoriesTrTable = $db->t('menu_category_tr');
    $menuItemsTable = $db->t('menu_items');
    $menuItemsTrTable = $db->t('menu_item_tr');

    ignore_user_abort(true);
    @set_time_limit(300);
    header('Content-Type: text/csv; charset=utf-8');
    $file = 'menu_export_' . (new DateTime('now', new DateTimeZone('Asia/Ho_Chi_Minh')))->format('Ymd_His') . '.csv';
    header('Content-Disposition: attachment; filename="' . $file . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    if ($out === false) {
        http_response_code(500);
        echo 'Cannot open output';
        exit;
    }
    fwrite($out, "\xEF\xBB\xBF");

    fputcsv($out, [
        'Poster ID',
        'Poster raw',
        'Название RU',
        'Описание RU',
        'Название EN',
        'Описание EN',
        'Название VN',
        'Описание VN',
        'Название KO',
        'Описание KO',
        'Цена',
        'Категория Poster',
        'Категория адапт. RU',
        'Категория адапт. EN',
        'Категория адапт. VN',
        'Категория адапт. KO',
    ], ';');

    $rows = $db->query(
        "SELECT
            p.poster_id,
            p.name_raw,
            COALESCE(ru.title, '') ru_title,
            COALESCE(ru.description, '') ru_desc,
            COALESCE(en.title, '') en_title,
            COALESCE(en.description, '') en_desc,
            COALESCE(vn.title, '') vn_title,
            COALESCE(vn.description, '') vn_desc,
            COALESCE(ko.title, '') ko_title,
            COALESCE(ko.description, '') ko_desc,
            p.price_raw,
            COALESCE(p.sub_category_name, '') poster_category,
            COALESCE(ctr_ru.name, c.name_raw, '') adapted_category_ru,
            COALESCE(ctr_en.name, c.name_raw, '') adapted_category_en,
            COALESCE(ctr_vn.name, c.name_raw, '') adapted_category_vn,
            COALESCE(ctr_ko.name, c.name_raw, '') adapted_category_ko
         FROM {$posterMenuItemsTable} p
         LEFT JOIN {$menuItemsTable} mi ON mi.poster_item_id = p.id
         LEFT JOIN {$menuItemsTrTable} ru ON ru.item_id = mi.id AND ru.lang = 'ru'
         LEFT JOIN {$menuItemsTrTable} en ON en.item_id = mi.id AND en.lang = 'en'
         LEFT JOIN {$menuItemsTrTable} vn ON vn.item_id = mi.id AND vn.lang = 'vn'
         LEFT JOIN {$menuItemsTrTable} ko ON ko.item_id = mi.id AND ko.lang = 'ko'
         LEFT JOIN {$menuCategoriesTable} c ON c.id = mi.category_id
         LEFT JOIN {$menuCategoriesTrTable} ctr_ru ON ctr_ru.category_id = c.id AND ctr_ru.lang = 'ru'
         LEFT JOIN {$menuCategoriesTrTable} ctr_en ON ctr_en.category_id = c.id AND ctr_en.lang = 'en'
         LEFT JOIN {$menuCategoriesTrTable} ctr_vn ON ctr_vn.category_id = c.id AND ctr_vn.lang = 'vn'
         LEFT JOIN {$menuCategoriesTrTable} ctr_ko ON ctr_ko.category_id = c.id AND ctr_ko.lang = 'ko'
         WHERE p.is_active = 1
         ORDER BY p.poster_id ASC"
    )->fetchAll();

    foreach ($rows as $r) {
        fputcsv($out, [
            (string)($r['poster_id'] ?? ''),
            (string)($r['name_raw'] ?? ''),
            (string)($r['ru_title'] ?? ''),
            (string)($r['ru_desc'] ?? ''),
            (string)($r['en_title'] ?? ''),
            (string)($r['en_desc'] ?? ''),
            (string)($r['vn_title'] ?? ''),
            (string)($r['vn_desc'] ?? ''),
            (string)($r['ko_title'] ?? ''),
            (string)($r['ko_desc'] ?? ''),
            (string)($r['price_raw'] ?? ''),
            (string)($r['poster_category'] ?? ''),
            (string)($r['adapted_category_ru'] ?? ''),
            (string)($r['adapted_category_en'] ?? ''),
            (string)($r['adapted_category_vn'] ?? ''),
            (string)($r['adapted_category_ko'] ?? ''),
        ], ';');
    }

    fclose($out);
    exit;
}

function admin_menu_export_items_missing_ko_csv(\App\Classes\Database $db): void
{
    $posterMenuItemsTable = $db->t('poster_menu_items');
    $menuItemsTable = $db->t('menu_items');
    $menuItemsTrTable = $db->t('menu_item_tr');

    ignore_user_abort(true);
    @set_time_limit(300);
    header('Content-Type: text/csv; charset=utf-8');
    $file = 'menu_missing_ko_' . (new DateTime('now', new DateTimeZone('Asia/Ho_Chi_Minh')))->format('Ymd_His') . '.csv';
    header('Content-Disposition: attachment; filename="' . $file . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    if ($out === false) {
        http_response_code(500);
        echo 'Cannot open output';
        exit;
    }
    fwrite($out, "\xEF\xBB\xBF");

    fputcsv($out, [
        'Item ID',
        'Poster ID',
        'Poster raw',
        'Название RU',
        'Название KO',
    ], ';');

    $rows = $db->query(
        "SELECT
            mi.id item_id,
            p.poster_id,
            p.name_raw,
            COALESCE(ru.title, '') ru_title,
            COALESCE(ko.title, '') ko_title
         FROM {$menuItemsTable} mi
         JOIN {$posterMenuItemsTable} p ON p.id = mi.poster_item_id
         LEFT JOIN {$menuItemsTrTable} ru ON ru.item_id = mi.id AND ru.lang = 'ru'
         LEFT JOIN {$menuItemsTrTable} ko ON ko.item_id = mi.id AND ko.lang = 'ko'
         WHERE p.is_active = 1
           AND COALESCE(NULLIF(TRIM(ru.title), ''), '') <> ''
           AND COALESCE(NULLIF(TRIM(COALESCE(ko.title, '')), ''), '') = ''
         ORDER BY p.poster_id ASC"
    )->fetchAll();

    foreach ($rows as $r) {
        fputcsv($out, [
            (string)($r['item_id'] ?? ''),
            (string)($r['poster_id'] ?? ''),
            (string)($r['name_raw'] ?? ''),
            (string)($r['ru_title'] ?? ''),
            (string)($r['ko_title'] ?? ''),
        ], ';');
    }

    fclose($out);
    exit;
}
