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
    $mw = $db->t('menu_workshops');
    $mwTr = $db->t('menu_workshop_tr');
    $mc = $db->t('menu_categories');
    $mcTr = $db->t('menu_category_tr');
    $mi = $db->t('menu_items');
    $miTr = $db->t('menu_item_tr');

    $sql = "
        SELECT
            p.poster_id AS id,
            COALESCE(NULLIF(itr.title, ''), NULLIF(itr_ru.title, ''), p.name_raw) AS title,
            p.price_raw AS price,
            COALESCE(NULLIF(ctr.name, ''), NULLIF(c.name_raw, ''), NULLIF(p.sub_category_name, '')) AS sub_category,
            COALESCE(NULLIF(wtr.name, ''), NULLIF(w.name_raw, ''), NULLIF(p.main_category_name, '')) AS main_category,
            COALESCE(NULLIF(itr.description, ''), NULLIF(itr_ru.description, ''), '') AS description,
            mi.image_url AS image_url,
            mi.sort_order AS sort_order,
            w.sort_order AS main_sort
        FROM {$mi} mi
        JOIN {$pmi} p ON p.id = mi.poster_item_id AND p.is_active = 1
        JOIN {$mc} c ON c.id = mi.category_id AND c.show_on_site = 1
        JOIN {$mw} w ON w.id = c.workshop_id AND w.show_on_site = 1
        LEFT JOIN {$miTr} itr ON itr.item_id = mi.id AND itr.lang = ?
        LEFT JOIN {$miTr} itr_ru ON itr_ru.item_id = mi.id AND itr_ru.lang = 'ru'
        LEFT JOIN {$mcTr} ctr ON ctr.category_id = c.id AND ctr.lang = ?
        LEFT JOIN {$mwTr} wtr ON wtr.workshop_id = w.id AND wtr.lang = ?
        WHERE mi.is_published = 1
        ORDER BY w.sort_order ASC, mi.sort_order ASC, title ASC
    ";

    $rows = $db->query($sql, [$lang, $lang, $lang])->fetchAll();

    echo json_encode(['lang' => $lang, 'items' => $rows], JSON_UNESCAPED_UNICODE);
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
