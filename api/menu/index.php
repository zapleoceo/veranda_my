<?php
date_default_timezone_set('Asia/Ho_Chi_Minh');

require_once __DIR__ . '/../../src/classes/Database.php';

header('Content-Type: application/json; charset=utf-8');

$lang = strtolower(trim((string)($_GET['lang'] ?? 'ru')));
if (!in_array($lang, ['ru', 'en', 'vn'], true)) {
    $lang = 'ru';
}

if (file_exists(__DIR__ . '/../../.env')) {
    $lines = file(__DIR__ . '/../../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) {
            continue;
        }
        [$name, $value] = explode('=', $line, 2);
        $_ENV[$name] = trim($value);
    }
}

$dbHost = $_ENV['DB_HOST'] ?? 'localhost';
$dbName = $_ENV['DB_NAME'] ?? 'veranda_my';
$dbUser = $_ENV['DB_USER'] ?? 'veranda_my';
$dbPass = $_ENV['DB_PASS'] ?? '';
$tableSuffix = (string)($_ENV['DB_TABLE_SUFFIX'] ?? '');

try {
    $db = new \App\Classes\Database($dbHost, $dbName, $dbUser, $dbPass, $tableSuffix);

    $pmi = $db->t('poster_menu_items');
    $ruTable = $db->t('menu_items_ru');
    $enTable = $db->t('menu_items_en');
    $vnTable = $db->t('menu_items_vn');
    $mcm = $db->t('menu_categories_main');
    $mcmTr = $db->t('menu_categories_main_tr');
    $mcs = $db->t('menu_categories_sub');
    $mcsTr = $db->t('menu_categories_sub_tr');

    $langTable = $lang === 'ru' ? $ruTable : ($lang === 'en' ? $enTable : $vnTable);

    $sql = "
        SELECT
            p.poster_id AS id,
            COALESCE(NULLIF(mi.title, ''), p.name_raw) AS title,
            p.price_raw AS price,
            COALESCE(NULLIF(mit_sub.name, ''), NULLIF(ms.name_raw, ''), NULLIF(p.sub_category_name, '')) AS sub_category,
            COALESCE(NULLIF(mit_main.name, ''), NULLIF(mm.name_raw, ''), NULLIF(p.main_category_name, '')) AS main_category,
            mi.description AS description,
            ru.image_url AS image_url,
            ru.sort_order AS sort_order,
            mm.sort_order AS main_sort
        FROM {$pmi} p
        JOIN {$ruTable} ru ON ru.poster_item_id = p.id
        LEFT JOIN {$langTable} mi ON mi.poster_item_id = p.id
        LEFT JOIN {$mcm} mm ON mm.id = COALESCE(mi.main_category_id, ru.main_category_id)
        LEFT JOIN {$mcmTr} mit_main ON mit_main.main_category_id = mm.id AND mit_main.lang = ?
        LEFT JOIN {$mcs} ms ON ms.id = COALESCE(mi.sub_category_id, ru.sub_category_id)
        LEFT JOIN {$mcsTr} mit_sub ON mit_sub.sub_category_id = ms.id AND mit_sub.lang = ?
        WHERE p.is_active = 1
          AND ru.is_published = 1
          AND (mm.id IS NULL OR mm.show_in_menu = 1)
          AND (ms.id IS NULL OR ms.show_in_menu = 1)
        ORDER BY main_sort ASC, sort_order ASC, title ASC
    ";

    $rows = $db->query($sql, [$lang, $lang])->fetchAll();

    echo json_encode(['lang' => $lang, 'items' => $rows], JSON_UNESCAPED_UNICODE);
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
