<?php

date_default_timezone_set('Asia/Ho_Chi_Minh');

require_once __DIR__ . '/../src/classes/Database.php';

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

$ks = $db->t('kitchen_stats');
$meta = $db->t('system_meta');
$users = $db->t('users');
$tgm = $db->t('tg_alert_messages');
$codemealOrders = $db->t('codemeal_orders');
$codemealSettings = $db->t('codemeal_order_table_settings');
$chefItems = $db->t('chef_assistant_items');
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

$db->query("CREATE TABLE IF NOT EXISTS {$codemealOrders} (
    id INT AUTO_INCREMENT PRIMARY KEY,
    external_id VARCHAR(128) NOT NULL,
    created_at DATETIME NULL,
    state VARCHAR(64) NULL,
    payload JSON NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_codemeal_external_id (external_id),
    KEY idx_codemeal_created (created_at),
    KEY idx_codemeal_state (state)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->query("CREATE TABLE IF NOT EXISTS {$codemealSettings} (
    id INT PRIMARY KEY,
    payload JSON NOT NULL,
    fetched_at DATETIME NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->query("CREATE TABLE IF NOT EXISTS {$chefItems} (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    dish_name_raw VARCHAR(255) NOT NULL,
    dish_name_norm VARCHAR(255) NOT NULL,
    send_at DATETIME NULL,
    start_at DATETIME NULL,
    end_at DATETIME NULL,
    ready_at DATETIME NULL,
    cooking_time_sec INT NULL,
    status_desc VARCHAR(64) NULL,
    status_css VARCHAR(64) NULL,
    fetched_at DATETIME NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_chef_assistant_order_dish (order_id, dish_name_norm),
    KEY idx_chef_assistant_ready (ready_at),
    KEY idx_chef_assistant_order (order_id)
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

echo json_encode([
    'ok' => true,
    'db' => $dbName,
    'suffix' => $suffix,
    'tables' => [$ks, $meta, $users, $tgm, $chefItems, $eventLog]
], JSON_UNESCAPED_UNICODE) . PHP_EOL;
