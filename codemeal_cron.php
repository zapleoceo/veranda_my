<?php
date_default_timezone_set('Asia/Ho_Chi_Minh');

require_once __DIR__ . '/src/classes/Database.php';
require_once __DIR__ . '/src/classes/CodemealAPI.php';

if (file_exists(__DIR__ . '/.env')) {
    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
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
$tableSuffix = (string)($_ENV['DB_TABLE_SUFFIX'] ?? '');

$baseUrl = $_ENV['CODEMEAL_BASE_URL'] ?? 'https://codemeal.pro';
$auth = $_ENV['CODEMEAL_AUTH'] ?? '';
$clientNumber = $_ENV['CODEMEAL_CLIENT_NUMBER'] ?? '';
$locale = $_ENV['CODEMEAL_LOCALE'] ?? '';
$timezone = $_ENV['CODEMEAL_TIMEZONE'] ?? '';

try {
    $db = new \App\Classes\Database($dbHost, $dbName, $dbUser, $dbPass, $tableSuffix);
    $meta = $db->t('system_meta');
    $ordersTable = $db->t('codemeal_orders');
    $settingsTable = $db->t('codemeal_order_table_settings');
    $db->query("CREATE TABLE IF NOT EXISTS {$meta} (
        meta_key VARCHAR(255) PRIMARY KEY,
        meta_value TEXT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    if ($auth === '') {
        $row = $db->query("SELECT meta_value FROM {$meta} WHERE meta_key='codemeal_auth' LIMIT 1")->fetch();
        $auth = $row ? (string)$row['meta_value'] : '';
    }
    if ($clientNumber === '') {
        $row = $db->query("SELECT meta_value FROM {$meta} WHERE meta_key='codemeal_client_number' LIMIT 1")->fetch();
        $clientNumber = $row ? (string)$row['meta_value'] : '';
    }
    if ($locale === '') {
        $row = $db->query("SELECT meta_value FROM {$meta} WHERE meta_key='codemeal_locale' LIMIT 1")->fetch();
        $locale = $row ? (string)$row['meta_value'] : 'en';
    }
    if ($timezone === '') {
        $row = $db->query("SELECT meta_value FROM {$meta} WHERE meta_key='codemeal_timezone' LIMIT 1")->fetch();
        $timezone = $row ? (string)$row['meta_value'] : 'Asia/Ho_Chi_Minh';
    }

    if ($auth === '' || $clientNumber === '') {
        throw new Exception('Codemeal credentials are not set');
    }
    $db->query("CREATE TABLE IF NOT EXISTS {$ordersTable} (
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

    $db->query("CREATE TABLE IF NOT EXISTS {$settingsTable} (
        id INT PRIMARY KEY,
        payload JSON NOT NULL,
        fetched_at DATETIME NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $api = new \App\Classes\CodemealAPI($baseUrl, $auth, $clientNumber, $locale, $timezone);

    $settings = $api->getOrderTableSettings();
    $db->query(
        "INSERT INTO {$settingsTable} (id, payload, fetched_at)
         VALUES (1, ?, ?)
         ON DUPLICATE KEY UPDATE payload=VALUES(payload), fetched_at=VALUES(fetched_at)",
        [json_encode($settings, JSON_UNESCAPED_UNICODE), date('Y-m-d H:i:s')]
    );

    $fromDate = $_ENV['CODEMEAL_FROM'] ?? null;
    if ($fromDate === null || trim($fromDate) === '') {
        $fromDate = date('Y/m/d 00:00:00', strtotime('-2 days'));
    }

    $toDate = $_ENV['CODEMEAL_TO'] ?? '';
    $state = $_ENV['CODEMEAL_STATE'] ?? '';
    $term = $_ENV['CODEMEAL_TERM'] ?? '';

    $total = 0;
    $pages = 0;
    for ($page = 1; $page <= 200; $page++) {
        $list = $api->getOrders($fromDate, $toDate !== '' ? $toDate : null, $state, $term, $page);
        $pages++;
        if (empty($list)) break;
        foreach ($list as $item) {
            if (!is_array($item)) continue;
            $eid = '';
            foreach (['id','order_id','orderId','code','number'] as $k) {
                if (isset($item[$k]) && $item[$k] !== '') { $eid = (string)$item[$k]; break; }
            }
            if ($eid === '') {
                $eid = sha1(json_encode($item, JSON_UNESCAPED_UNICODE));
            }
            $created = null;
            foreach (['createdAt','created','date','created_date','order_date'] as $k) {
                if (!empty($item[$k])) {
                    $ts = strtotime((string)$item[$k]);
                    if ($ts !== false) {
                        $created = date('Y-m-d H:i:s', $ts);
                        break;
                    }
                }
            }
            $stateVal = null;
            foreach (['state','status','order_state','orderStatus'] as $k) {
                if (isset($item[$k]) && $item[$k] !== '') { $stateVal = (string)$item[$k]; break; }
            }
            $db->query(
                "INSERT INTO {$ordersTable} (external_id, created_at, state, payload)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    created_at = COALESCE(VALUES(created_at), {$ordersTable}.created_at),
                    state = COALESCE(VALUES(state), {$ordersTable}.state),
                    payload = VALUES(payload)",
                [$eid, $created, $stateVal, json_encode($item, JSON_UNESCAPED_UNICODE)]
            );
            $total++;
        }
    }

    $db->query(
        "INSERT INTO {$meta} (meta_key, meta_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)",
        ['codemeal_last_sync_at', date('Y-m-d H:i:s')]
    );
    $db->query(
        "INSERT INTO {$meta} (meta_key, meta_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)",
        ['codemeal_last_sync_info', "pages={$pages}, saved={$total}"]
    );
    echo json_encode(['ok'=>true,'pages'=>$pages,'saved'=>$total,'from'=>$fromDate], JSON_UNESCAPED_UNICODE) . PHP_EOL;

} catch (Exception $e) {
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(1);
}
