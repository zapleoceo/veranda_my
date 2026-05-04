<?php
require_once __DIR__ . '/src/classes/Database.php';
require_once __DIR__ . '/src/classes/PosterAPI.php';
require_once __DIR__ . '/src/classes/PosterMenuSync.php';

if (file_exists(__DIR__ . '/.env')) {
    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        [$name, $value] = explode('=', $line, 2);
        $_ENV[$name] = trim($value);
    }
}

$spotTzName = trim((string)($_ENV['POSTER_SPOT_TIMEZONE'] ?? ''));
if ($spotTzName === '' || !in_array($spotTzName, timezone_identifiers_list(), true)) {
    $spotTzName = 'Asia/Ho_Chi_Minh';
}
$apiTzName = trim((string)($_ENV['POSTER_API_TIMEZONE'] ?? ''));
if ($apiTzName === '' || !in_array($apiTzName, timezone_identifiers_list(), true)) {
    $apiTzName = $spotTzName;
}
date_default_timezone_set($apiTzName);

$dbHost = $_ENV['DB_HOST'] ?? 'localhost';
$dbName = $_ENV['DB_NAME'] ?? 'veranda_my';
$dbUser = $_ENV['DB_USER'] ?? 'veranda_my';
$dbPass = $_ENV['DB_PASS'] ?? '';
$token = $_ENV['POSTER_API_TOKEN'] ?? '';
$tableSuffix = (string)($_ENV['DB_TABLE_SUFFIX'] ?? '');
$forceCategories = false;
if (isset($argv) && is_array($argv) && in_array('--force-categories', $argv, true)) {
    $forceCategories = true;
}

$now = date('Y-m-d H:i:s');

try {
    if ($token === '') {
        throw new Exception('POSTER_API_TOKEN is empty');
    }

    $db = new \App\Classes\Database($dbHost, $dbName, $dbUser, $dbPass, $tableSuffix);
    $meta = $db->t('system_meta');

    $api = new \App\Classes\PosterAPI($token);
    $sync = new \App\Classes\PosterMenuSync($api, $db);

    echo "[{$now}] Starting menu sync...\n";
    $result = $sync->sync($forceCategories);
    $now2 = date('Y-m-d H:i:s');

    $summary = 'ok=1';
    if (is_array($result)) {
        $parts = [];
        foreach (['duration_ms', 'items_seen', 'workshops', 'categories', 'main_categories', 'sub_categories'] as $k) {
            if (isset($result[$k])) {
                $parts[] = $k . '=' . (string)$result[$k];
            }
        }
        if (!empty($parts)) {
            $summary = implode(', ', $parts);
        }
    }

    $db->query(
        "INSERT INTO {$meta} (meta_key, meta_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value), updated_at=CURRENT_TIMESTAMP",
        ['menu_last_sync_at', $now2]
    );
    $db->query(
        "INSERT INTO {$meta} (meta_key, meta_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value), updated_at=CURRENT_TIMESTAMP",
        ['menu_last_sync_result', $summary]
    );
    $db->query(
        "INSERT INTO {$meta} (meta_key, meta_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value), updated_at=CURRENT_TIMESTAMP",
        ['menu_last_sync_error', '']
    );

    echo "[{$now2}] Done: {$summary}\n";
} catch (Exception $e) {
    $err = $e->getMessage();
    $now3 = date('Y-m-d H:i:s');
    echo "[{$now3}] ERROR: {$err}\n";
    try {
        $db = isset($db) ? $db : new \App\Classes\Database($dbHost, $dbName, $dbUser, $dbPass, $tableSuffix);
        $meta = $db->t('system_meta');
        $db->query(
            "INSERT INTO {$meta} (meta_key, meta_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value), updated_at=CURRENT_TIMESTAMP",
            ['menu_last_sync_error', mb_substr($err, 0, 250, 'UTF-8')]
        );
        $db->query(
            "INSERT INTO {$meta} (meta_key, meta_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value), updated_at=CURRENT_TIMESTAMP",
            ['menu_last_sync_at', $now3]
        );
        $db->query(
            "INSERT INTO {$meta} (meta_key, meta_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value), updated_at=CURRENT_TIMESTAMP",
            ['menu_last_sync_result', 'ok=0']
        );
    } catch (Exception $e2) {
    }
    exit(1);
}
