<?php

date_default_timezone_set('Asia/Ho_Chi_Minh');

require_once __DIR__ . '/../src/classes/Database.php';

if (file_exists(__DIR__ . '/../.env')) {
    $lines = file(__DIR__ . '/../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $t = trim($line);
        if ($t === '' || $t[0] === '#') continue;
        if (strpos($t, '=') === false) continue;
        [$name, $value] = explode('=', $line, 2);
        $_ENV[$name] = trim($value);
    }
}

$dbHost = $_ENV['DB_HOST'] ?? 'localhost';
$dbName = $_ENV['DB_NAME'] ?? 'veranda_my';
$dbUser = $_ENV['DB_USER'] ?? 'veranda_my';
$dbPass = $_ENV['DB_PASS'] ?? '';

$source = new \App\Classes\Database($dbHost, $dbName, $dbUser, $dbPass, '');
$targetSuffix = (string)($_ENV['DB_TABLE_SUFFIX'] ?? '');
if ($targetSuffix === '') $targetSuffix = '_2';
$target = new \App\Classes\Database($dbHost, $dbName, $dbUser, $dbPass, $targetSuffix);

$srcRu = $source->t('menu_items_ru');
$dstRu = $target->t('menu_items_ru');

$srcEn = $source->t('menu_items_en');
$dstEn = $target->t('menu_items_en');

$srcVn = $source->t('menu_items_vn');
$dstVn = $target->t('menu_items_vn');

$srcKo = $source->t('menu_items_ko');
$dstKo = $target->t('menu_items_ko');

$srcMain = $source->t('menu_categories_main');
$dstMain = $target->t('menu_categories_main');
$srcSub = $source->t('menu_categories_sub');
$dstSub = $target->t('menu_categories_sub');

$srcMainTr = $source->t('menu_categories_main_tr');
$dstMainTr = $target->t('menu_categories_main_tr');
$srcSubTr = $source->t('menu_categories_sub_tr');
$dstSubTr = $target->t('menu_categories_sub_tr');

$rowsRu = 0;
$rowsEn = 0;
$rowsVn = 0;
$rowsKo = 0;
$rowsMain = 0;
$rowsSub = 0;
$rowsMainTr = 0;
$rowsSubTr = 0;

try {
    $rowsRu = $target->query(
        "UPDATE {$dstRu} d
         JOIN {$srcRu} s ON s.poster_item_id = d.poster_item_id
         SET d.is_published = s.is_published,
             d.image_url = s.image_url,
             d.sort_order = s.sort_order,
             d.title = CASE WHEN s.title IS NOT NULL AND s.title <> '' THEN s.title ELSE d.title END,
             d.description = CASE WHEN s.description IS NOT NULL AND s.description <> '' THEN s.description ELSE d.description END"
    )->rowCount();
} catch (\Exception $e) {
}

try {
    $rowsEn = $target->query(
        "UPDATE {$dstEn} d
         JOIN {$srcEn} s ON s.poster_item_id = d.poster_item_id
         SET d.title = CASE WHEN s.title IS NOT NULL AND s.title <> '' THEN s.title ELSE d.title END,
             d.description = CASE WHEN s.description IS NOT NULL AND s.description <> '' THEN s.description ELSE d.description END"
    )->rowCount();
} catch (\Exception $e) {
}

try {
    $rowsVn = $target->query(
        "UPDATE {$dstVn} d
         JOIN {$srcVn} s ON s.poster_item_id = d.poster_item_id
         SET d.title = CASE WHEN s.title IS NOT NULL AND s.title <> '' THEN s.title ELSE d.title END,
             d.description = CASE WHEN s.description IS NOT NULL AND s.description <> '' THEN s.description ELSE d.description END"
    )->rowCount();
} catch (\Exception $e) {
}

try {
    $rowsKo = $target->query(
        "UPDATE {$dstKo} d
         JOIN {$srcKo} s ON s.poster_item_id = d.poster_item_id
         SET d.title = CASE WHEN s.title IS NOT NULL AND s.title <> '' THEN s.title ELSE d.title END,
             d.description = CASE WHEN s.description IS NOT NULL AND s.description <> '' THEN s.description ELSE d.description END"
    )->rowCount();
} catch (\Exception $e) {
}

try {
    $rowsMain = $target->query(
        "UPDATE {$dstMain} d
         JOIN {$srcMain} s ON s.poster_main_category_id = d.poster_main_category_id
         SET d.sort_order = s.sort_order,
             d.show_in_menu = s.show_in_menu"
    )->rowCount();
} catch (\Exception $e) {
}

try {
    $rowsSub = $target->query(
        "UPDATE {$dstSub} d
         JOIN {$srcSub} s ON s.poster_sub_category_id = d.poster_sub_category_id
         SET d.sort_order = s.sort_order,
             d.show_in_menu = s.show_in_menu"
    )->rowCount();
} catch (\Exception $e) {
}

try {
    $rowsMainTr = $target->query(
        "INSERT INTO {$dstMainTr} (main_category_id, lang, name)
         SELECT d.id, s.lang, s.name
         FROM {$srcMainTr} s
         JOIN {$srcMain} sm ON sm.id = s.main_category_id
         JOIN {$dstMain} d ON d.poster_main_category_id = sm.poster_main_category_id
         ON DUPLICATE KEY UPDATE name = VALUES(name)"
    )->rowCount();
} catch (\Exception $e) {
}

try {
    $rowsSubTr = $target->query(
        "INSERT INTO {$dstSubTr} (sub_category_id, lang, name)
         SELECT d.id, s.lang, s.name
         FROM {$srcSubTr} s
         JOIN {$srcSub} ss ON ss.id = s.sub_category_id
         JOIN {$dstSub} d ON d.poster_sub_category_id = ss.poster_sub_category_id
         ON DUPLICATE KEY UPDATE name = VALUES(name)"
    )->rowCount();
} catch (\Exception $e) {
}

echo json_encode([
    'ok' => true,
    'target_suffix' => $targetSuffix,
    'updated' => [
        'menu_items_ru' => $rowsRu,
        'menu_items_en' => $rowsEn,
        'menu_items_vn' => $rowsVn,
        'menu_items_ko' => $rowsKo,
        'menu_categories_main' => $rowsMain,
        'menu_categories_sub' => $rowsSub,
        'menu_categories_main_tr' => $rowsMainTr,
        'menu_categories_sub_tr' => $rowsSubTr,
    ],
], JSON_UNESCAPED_UNICODE) . PHP_EOL;

