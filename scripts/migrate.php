<?php

date_default_timezone_set('Asia/Ho_Chi_Minh');

require_once __DIR__ . '/../src/classes/Database.php';
require_once __DIR__ . '/../src/classes/EventLogger.php';

if (file_exists(__DIR__ . '/../.env')) {
    $lines = file(__DIR__ . '/../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        [$name, $value] = explode('=', $line, 2);
        $_ENV[$name] = trim($value);
    }
}

$dbHost = $_ENV['DB_HOST'] ?? 'localhost';
$dbName = $_ENV['DB_NAME'] ?? 'veranda_my';
$dbUser = $_ENV['DB_USER'] ?? 'veranda_my';
$dbPass = $_ENV['DB_PASS'] ?? '';
$suffix = (string)($_ENV['DB_TABLE_SUFFIX'] ?? '');

$db = new \App\Classes\Database($dbHost, $dbName, $dbUser, $dbPass, $suffix);
$logger = new \App\Classes\EventLogger($db, 'migrate');

$ks = $db->t('kitchen_stats');
$meta = $db->t('system_meta');
$users = $db->t('users');
$tgm = $db->t('tg_alert_messages');
$eventLog = $db->t('event_log');

$db->createTables();

$db->query("CREATE TABLE IF NOT EXISTS {$meta} (
    meta_key VARCHAR(255) PRIMARY KEY,
    meta_value TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->query("CREATE TABLE IF NOT EXISTS {$users} (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL,
    telegram_username VARCHAR(64) NULL,
    permissions_json TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_active (is_active),
    KEY idx_users_tg (telegram_username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->query("CREATE TABLE IF NOT EXISTS {$tgm} (
    id INT AUTO_INCREMENT PRIMARY KEY,
    kitchen_stats_id INT NOT NULL,
    transaction_date DATE NOT NULL,
    transaction_id BIGINT NOT NULL,
    dish_id BIGINT NOT NULL,
    item_seq INT NOT NULL DEFAULT 1,
    message_id BIGINT NOT NULL,
    last_text_hash CHAR(40) NOT NULL,
    last_edited_at DATETIME NULL,
    last_seen_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_kitchen_stats_id (kitchen_stats_id),
    KEY idx_tx (transaction_date, transaction_id),
    KEY idx_seen (last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->query("CREATE TABLE IF NOT EXISTS {$eventLog} (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    ts DATETIME NOT NULL,
    level VARCHAR(16) NOT NULL,
    type VARCHAR(32) NOT NULL,
    event VARCHAR(64) NOT NULL,
    context_json JSON NULL,
    request_id CHAR(36) NULL,
    user_email VARCHAR(255) NULL,
    tx_id BIGINT NULL,
    dish_id BIGINT NULL,
    item_seq INT NULL,
    KEY idx_event_log_ts (ts),
    KEY idx_event_log_type_ts (type, ts),
    KEY idx_event_log_level_ts (level, ts),
    KEY idx_event_log_tx (tx_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->createMenuTables();

$tableExists = function (string $table) use ($db, $dbName): bool {
    $row = $db->query(
        "SELECT COUNT(*) AS c
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = ?
           AND TABLE_NAME = ?",
        [$dbName, $table]
    )->fetch();
    return (int)($row['c'] ?? 0) > 0;
};

$migrateMenuSchema = function () use ($db, $tableExists): array {
    $mw = $db->t('menu_workshops');
    $mwTr = $db->t('menu_workshop_tr');
    $mc = $db->t('menu_categories');
    $mcTr = $db->t('menu_category_tr');
    $mi = $db->t('menu_items');
    $miTr = $db->t('menu_item_tr');

    $oldMain = $db->t('menu_categories_main');
    $oldMainTr = $db->t('menu_categories_main_tr');
    $oldSub = $db->t('menu_categories_sub');
    $oldSubTr = $db->t('menu_categories_sub_tr');
    $oldRu = $db->t('menu_items_ru');
    $oldEn = $db->t('menu_items_en');
    $oldVn = $db->t('menu_items_vn');
    $oldKo = $db->t('menu_items_ko');

    $migrated = [
        'workshops' => 0,
        'workshop_tr' => 0,
        'categories' => 0,
        'category_tr' => 0,
        'items' => 0,
        'item_tr' => 0,
    ];

    if ($tableExists($oldMain) && $tableExists($oldSub) && $tableExists($oldRu)) {
        try {
            $migrated['workshops'] = $db->query(
                "INSERT INTO {$mw} (id, poster_id, name_raw, sort_order, show_on_site, created_at, updated_at)
                 SELECT id, poster_main_category_id, name_raw, sort_order, show_in_menu, created_at, updated_at
                 FROM {$oldMain}
                 ON DUPLICATE KEY UPDATE
                    poster_id=VALUES(poster_id),
                    name_raw=VALUES(name_raw),
                    sort_order=VALUES(sort_order),
                    show_on_site=VALUES(show_on_site),
                    updated_at=VALUES(updated_at)"
            )->rowCount();
        } catch (\Exception $e) {
        }

        if ($tableExists($oldMainTr)) {
            try {
                $migrated['workshop_tr'] = $db->query(
                    "INSERT INTO {$mwTr} (workshop_id, lang, name)
                     SELECT main_category_id, lang, name
                     FROM {$oldMainTr}
                     ON DUPLICATE KEY UPDATE name=VALUES(name)"
                )->rowCount();
            } catch (\Exception $e) {
            }
        }

        try {
            $migrated['categories'] = $db->query(
                "INSERT INTO {$mc} (id, poster_id, workshop_id, name_raw, sort_order, show_on_site, created_at, updated_at)
                 SELECT
                    id,
                    poster_sub_category_id,
                    COALESCE(main_category_id_override, main_category_id),
                    name_raw,
                    sort_order,
                    show_in_menu,
                    created_at,
                    updated_at
                 FROM {$oldSub}
                 WHERE COALESCE(main_category_id_override, main_category_id) IS NOT NULL
                 ON DUPLICATE KEY UPDATE
                    poster_id=VALUES(poster_id),
                    workshop_id=VALUES(workshop_id),
                    name_raw=VALUES(name_raw),
                    sort_order=VALUES(sort_order),
                    show_on_site=VALUES(show_on_site),
                    updated_at=VALUES(updated_at)"
            )->rowCount();
        } catch (\Exception $e) {
        }

        if ($tableExists($oldSubTr)) {
            try {
                $migrated['category_tr'] = $db->query(
                    "INSERT INTO {$mcTr} (category_id, lang, name)
                     SELECT sub_category_id, lang, name
                     FROM {$oldSubTr}
                     ON DUPLICATE KEY UPDATE name=VALUES(name)"
                )->rowCount();
            } catch (\Exception $e) {
            }
        }

        try {
            $migrated['items'] = $db->query(
                "INSERT INTO {$mi} (poster_item_id, category_id, image_url, is_published, sort_order)
                 SELECT poster_item_id, sub_category_id, image_url, is_published, sort_order
                 FROM {$oldRu}
                 WHERE sub_category_id IS NOT NULL
                 ON DUPLICATE KEY UPDATE
                    category_id=VALUES(category_id),
                    image_url=VALUES(image_url),
                    is_published=VALUES(is_published),
                    sort_order=VALUES(sort_order)"
            )->rowCount();
        } catch (\Exception $e) {
        }

        $insertTr = function (string $srcTable, string $lang) use ($db, $mi, $miTr, &$migrated): void {
            try {
                $migrated['item_tr'] += (int)$db->query(
                    "INSERT INTO {$miTr} (item_id, lang, title, description)
                     SELECT mi.id, ?, s.title, s.description
                     FROM {$mi} mi
                     JOIN {$srcTable} s ON s.poster_item_id = mi.poster_item_id
                     ON DUPLICATE KEY UPDATE
                        title=VALUES(title),
                        description=VALUES(description)",
                    [$lang]
                )->rowCount();
            } catch (\Exception $e) {
            }
        };

        $insertTr($oldRu, 'ru');
        if ($tableExists($oldEn)) $insertTr($oldEn, 'en');
        if ($tableExists($oldVn)) $insertTr($oldVn, 'vn');
        if ($tableExists($oldKo)) $insertTr($oldKo, 'ko');
    }

    return $migrated;
};

$menuMigrated = $migrateMenuSchema();

$ensureIndex = function (string $table, string $indexName, string $columns) use ($db, $dbName): void {
    $row = $db->query(
        "SELECT COUNT(*) AS c
         FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = ?
           AND TABLE_NAME = ?
           AND INDEX_NAME = ?",
        [$dbName, $table, $indexName]
    )->fetch();
    if ((int)($row['c'] ?? 0) > 0) return;
    $db->query("ALTER TABLE {$table} ADD INDEX {$indexName} ({$columns})");
};

$ensureIndex($ks, 'idx_ks_date_status_sent', 'transaction_date, status, ticket_sent_at');
$ensureIndex($ks, 'idx_ks_date_station_sent', 'transaction_date, station, ticket_sent_at');
$ensureIndex($ks, 'idx_ks_date_tx', 'transaction_date, transaction_id');
$ensureIndex($ks, 'idx_ks_date_exclude', 'transaction_date, exclude_from_dashboard');
$ensureIndex($ks, 'idx_ks_tg_msg', 'tg_message_id');
$ensureIndex($ks, 'idx_ks_tg_ack', 'tg_acknowledged, tg_acknowledged_at');

$logger->info('ok', ['tables' => [$ks, $meta, $users, $tgm, $eventLog], 'menu_migrated' => $menuMigrated]);

echo json_encode([
    'ok' => true,
    'db' => $dbName,
    'suffix' => $suffix,
    'tables' => [$ks, $meta, $users, $tgm, $eventLog],
    'menu_migrated' => $menuMigrated
], JSON_UNESCAPED_UNICODE) . PHP_EOL;
