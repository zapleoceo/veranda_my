<?php

require_once __DIR__ . '/src/classes/Database.php';
require_once __DIR__ . '/src/classes/PosterAPI.php';
require_once __DIR__ . '/src/classes/TelegramBot.php';
require_once __DIR__ . '/src/classes/MetaRepository.php';
require_once __DIR__ . '/src/classes/KitchenAnalytics.php';
require_once __DIR__ . '/src/classes/EventLogger.php';

date_default_timezone_set('Asia/Ho_Chi_Minh');

if (file_exists(__DIR__ . '/.env')) {
    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $t = trim($line);
        if ($t === '' || $t[0] === '#' || strpos($t, '=') === false) continue;
        [$name, $value] = explode('=', $line, 2);
        $_ENV[$name] = trim($value);
    }
}

try {
    $startedAt = microtime(true);
    $dbHost = $_ENV['DB_HOST'] ?? 'localhost';
    $dbName = $_ENV['DB_NAME'] ?? 'veranda_my';
    $dbUser = $_ENV['DB_USER'] ?? 'veranda_my';
    $dbPass = $_ENV['DB_PASS'] ?? '';
    $tableSuffix = (string)($_ENV['DB_TABLE_SUFFIX'] ?? '');

    $db = new \App\Classes\Database($dbHost, $dbName, $dbUser, $dbPass, $tableSuffix);
    $metaTable = $db->t('system_meta');
    $ks = $db->t('kitchen_stats');
    $tgThreads = $db->t('tg_alert_threads');

    $db->query(
        "CREATE TABLE IF NOT EXISTS {$tgThreads} (
            transaction_date DATE NOT NULL,
            transaction_id BIGINT NOT NULL,
            message_id BIGINT NULL,
            receipt_number VARCHAR(64) NULL,
            table_number VARCHAR(64) NULL,
            waiter_name VARCHAR(255) NULL,
            last_text_hash CHAR(40) NULL,
            last_edited_at DATETIME NULL,
            last_seen_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (transaction_date, transaction_id),
            KEY idx_tg_threads_msg (message_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
    );

    $logger = new \App\Classes\EventLogger($db, 'telegram_alerts');
    $metaRepo = new \App\Classes\MetaRepository($db);

    $token = trim((string)($_ENV['POSTER_API_TOKEN'] ?? $_ENV['POSTER_TOKEN'] ?? ''));
    $tgToken = trim((string)($_ENV['TELEGRAM_BOT_TOKEN'] ?? $_ENV['TG_BOT_TOKEN'] ?? ''));
    $tgChatId = trim((string)($_ENV['TELEGRAM_CHAT_ID'] ?? $_ENV['TG_CHAT_ID'] ?? ''));
    $tgThreadId = trim((string)($_ENV['TELEGRAM_THREAD_ID'] ?? $_ENV['TG_THREAD_ID'] ?? ''));
    $tgThreadId = $tgThreadId !== '' ? (int)$tgThreadId : null;

    if ($token === '') throw new \Exception('Missing POSTER_API_TOKEN');
    if ($tgToken === '') throw new \Exception('Missing TELEGRAM_BOT_TOKEN');
    if ($tgChatId === '') throw new \Exception('Missing TELEGRAM_CHAT_ID');

    $api = new \App\Classes\PosterAPI($token);
    $bot = new \App\Classes\TelegramBot($tgToken, $tgChatId);

    $getMeta = function (string $key, string $default = '') use ($metaRepo): string {
        $vals = $metaRepo->getMany([$key]);
        return array_key_exists($key, $vals) ? (string)$vals[$key] : $default;
    };
    $setMeta = function (string $key, string $value) use ($db, $metaTable): void {
        $db->query(
            "INSERT INTO {$metaTable} (meta_key, meta_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value), updated_at = CURRENT_TIMESTAMP",
            [$key, $value]
        );
    };

    $today = date('Y-m-d');

    try {
        $lastSyncAt = $getMeta('poster_last_sync_at', '');
        $last = $lastSyncAt !== '' ? strtotime($lastSyncAt) : 0;
        if ($last <= 0 || (time() - $last) >= 5) {
            $analytics = new \App\Classes\KitchenAnalytics($api);
            $stats = $analytics->getDailyStats($today);
            if (is_array($stats) && count($stats) > 0) {
                $db->saveStats($stats);
            }
            $setMeta('poster_last_sync_at', date('Y-m-d H:i:s'));
        }
    } catch (\Throwable $e) {
        $logger->warn('sync_failed', ['error' => $e->getMessage()]);
    }

    $settingKeys = [
        'alert_timing_low_load' => 20,
        'alert_load_threshold' => 25,
        'alert_timing_high_load' => 30,
        'exclude_partners_from_load' => 0
    ];
    $settings = [];
    $settingValues = $metaRepo->getMany(array_keys($settingKeys));
    foreach ($settingKeys as $key => $default) {
        $val = array_key_exists($key, $settingValues) ? $settingValues[$key] : $default;
        $settings[$key] = is_numeric($default) ? (int)$val : (string)$val;
    }

    $partnersCount = 0;
    $otherCount = 0;
    if (!empty($settings['exclude_partners_from_load'])) {
        $partnersCountRow = $db->query("SELECT COUNT(DISTINCT transaction_id) as c FROM {$ks} WHERE status = 1 AND transaction_date = ? AND table_number = 'Partners'", [$today])->fetch();
        $partnersCount = (int)($partnersCountRow['c'] ?? 0);
        $otherCountRow = $db->query("SELECT COUNT(DISTINCT transaction_id) as c FROM {$ks} WHERE status = 1 AND transaction_date = ? AND table_number != 'Partners'", [$today])->fetch();
        $otherCount = (int)($otherCountRow['c'] ?? 0);
        $loadCount = $otherCount;
        $openChecksDisplay = "{$otherCount}+{$partnersCount}";
    } else {
        $openCountRow = $db->query("SELECT COUNT(DISTINCT transaction_id) as c FROM {$ks} WHERE status = 1 AND transaction_date = ?", [$today])->fetch();
        $openCount = (int)($openCountRow['c'] ?? 0);
        $loadCount = $openCount;
        $openChecksDisplay = (string)$openCount;
    }

    $waitLimit = ($loadCount < (int)$settings['alert_load_threshold'])
        ? (int)$settings['alert_timing_low_load']
        : (int)$settings['alert_timing_high_load'];

    $cutoffTime = date('Y-m-d H:i:s', strtotime("-{$waitLimit} minutes"));

    $rows = $db->query(
        "SELECT id, transaction_id, receipt_number, table_number, waiter_name, dish_name, ticket_sent_at
         FROM {$ks}
         WHERE ready_pressed_at IS NULL
           AND ticket_sent_at IS NOT NULL
           AND transaction_date = ?
           AND status = 1
           AND COALESCE(was_deleted, 0) = 0
           AND COALESCE(exclude_from_dashboard, 0) = 0
           AND NOT (COALESCE(dish_category_id, 0) = 47 OR COALESCE(dish_sub_category_id, 0) = 47)
           AND COALESCE(tg_acknowledged, 0) = 0
           AND ticket_sent_at < ?
         ORDER BY transaction_id ASC, ticket_sent_at ASC, id ASC",
        [$today, $cutoffTime]
    )->fetchAll();
    if (!is_array($rows)) $rows = [];

    $groups = [];
    foreach ($rows as $r) {
        $txId = (int)($r['transaction_id'] ?? 0);
        if ($txId <= 0) continue;
        if (!isset($groups[$txId])) {
            $groups[$txId] = [
                'receipt_number' => (string)($r['receipt_number'] ?? ''),
                'table_number' => (string)($r['table_number'] ?? ''),
                'waiter_name' => (string)($r['waiter_name'] ?? ''),
                'items' => []
            ];
        }
        $groups[$txId]['items'][] = $r;
    }

    $existing = $db->query(
        "SELECT transaction_id, message_id, last_text_hash
         FROM {$tgThreads}
         WHERE transaction_date = ?",
        [$today]
    )->fetchAll();
    if (!is_array($existing)) $existing = [];
    $existingByTx = [];
    foreach ($existing as $e) {
        $tx = (int)($e['transaction_id'] ?? 0);
        if ($tx <= 0) continue;
        $existingByTx[$tx] = $e;
    }

    $nowTs = time();
    $nowDt = date('Y-m-d H:i:s', $nowTs);

    foreach ($existingByTx as $txId => $e) {
        if (isset($groups[$txId])) continue;
        $msgId = (int)($e['message_id'] ?? 0);
        if ($msgId > 0) {
            $deleted = $bot->deleteMessage($msgId);
            if (!$deleted) continue;
        }
        $db->query("DELETE FROM {$tgThreads} WHERE transaction_date = ? AND transaction_id = ?", [$today, $txId]);
    }

    foreach ($groups as $txId => $g) {
        $receipt = trim((string)$g['receipt_number']);
        $table = trim((string)$g['table_number']);
        $waiter = trim((string)$g['waiter_name']);
        if ($receipt === '') $receipt = (string)$txId;
        if ($table === '') $table = '—';
        if ($waiter === '') $waiter = '—';

        $lines = [];
        $keyboard = [];
        $btnRow = [];

        foreach ($g['items'] as $it) {
            $dish = trim((string)($it['dish_name'] ?? ''));
            if ($dish === '') $dish = '—';

            $sentAt = (string)($it['ticket_sent_at'] ?? '');
            $sentTs = $sentAt !== '' ? strtotime($sentAt) : 0;
            $diffSec = $sentTs > 0 ? max(0, $nowTs - $sentTs) : 0;
            $mm = (int)floor($diffSec / 60);
            $ss = (int)($diffSec % 60);
            $elapsed = str_pad((string)$mm, 2, '0', STR_PAD_LEFT) . ':' . str_pad((string)$ss, 2, '0', STR_PAD_LEFT);

            $lines[] = htmlspecialchars($dish) . ' — <b>' . $elapsed . '</b>';
            $btnRow[] = [
                'text' => 'Принято',
                'callback_data' => 'ack_alert:' . (int)($it['id'] ?? 0)
            ];
            if (count($btnRow) >= 2) {
                $keyboard[] = $btnRow;
                $btnRow = [];
            }
        }
        if (count($btnRow) > 0) $keyboard[] = $btnRow;

        $text = '<b>Чек:' . htmlspecialchars($receipt) . '|Стол ' . htmlspecialchars($table) . "</b>\n";
        $text .= htmlspecialchars($waiter) . "\n";
        $text .= implode("\n", array_map(fn($l) => '• ' . $l, $lines));

        $textHash = sha1($text . '|' . json_encode($keyboard, JSON_UNESCAPED_UNICODE));

        $prev = $existingByTx[$txId] ?? null;
        $prevMsgId = $prev ? (int)($prev['message_id'] ?? 0) : 0;
        $prevHash = $prev ? (string)($prev['last_text_hash'] ?? '') : '';

        if ($prevMsgId > 0 && $prevHash === $textHash) {
            $db->query(
                "UPDATE {$tgThreads}
                 SET last_seen_at = ?, receipt_number = ?, table_number = ?, waiter_name = ?
                 WHERE transaction_date = ? AND transaction_id = ?",
                [$nowDt, $receipt, $table, $waiter, $today, $txId]
            );
            continue;
        }

        if ($prevMsgId > 0) {
            $edited = $bot->editMessageText($prevMsgId, $text, $keyboard);
            if ($edited) {
                $db->query(
                    "UPDATE {$tgThreads}
                     SET last_text_hash = ?, last_seen_at = ?, last_edited_at = ?, receipt_number = ?, table_number = ?, waiter_name = ?
                     WHERE transaction_date = ? AND transaction_id = ?",
                    [$textHash, $nowDt, $nowDt, $receipt, $table, $waiter, $today, $txId]
                );
                continue;
            }
        }

        if ($prevMsgId > 0) {
            $bot->deleteMessage($prevMsgId);
        }
        $newMsgId = $bot->sendMessageGetIdWithKeyboard($text, $keyboard, $tgThreadId);
        if ($newMsgId) {
            $db->query(
                "INSERT INTO {$tgThreads} (transaction_date, transaction_id, message_id, receipt_number, table_number, waiter_name, last_text_hash, last_edited_at, last_seen_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    message_id = VALUES(message_id),
                    receipt_number = VALUES(receipt_number),
                    table_number = VALUES(table_number),
                    waiter_name = VALUES(waiter_name),
                    last_text_hash = VALUES(last_text_hash),
                    last_edited_at = VALUES(last_edited_at),
                    last_seen_at = VALUES(last_seen_at),
                    updated_at = CURRENT_TIMESTAMP",
                [$today, $txId, (int)$newMsgId, $receipt, $table, $waiter, $textHash, $nowDt, $nowDt]
            );
        }
    }

    $durationMs = (int)round((microtime(true) - $startedAt) * 1000);
    $setMeta('telegram_last_run_at', date('Y-m-d H:i:s'));
    $setMeta('telegram_last_run_result', 'duration_ms=' . $durationMs . '; open=' . $openChecksDisplay . '; wait=' . $waitLimit);
    $setMeta('telegram_last_run_error', '');
} catch (\Throwable $e) {
    try {
        if (isset($db) && $db instanceof \App\Classes\Database) {
            $metaTable = $db->t('system_meta');
            $db->query(
                "INSERT INTO {$metaTable} (meta_key, meta_value) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value), updated_at = CURRENT_TIMESTAMP",
                ['telegram_last_run_at', date('Y-m-d H:i:s')]
            );
            $db->query(
                "INSERT INTO {$metaTable} (meta_key, meta_value) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value), updated_at = CURRENT_TIMESTAMP",
                ['telegram_last_run_error', $e->getMessage()]
            );
        }
    } catch (\Throwable $e2) {
    }
    echo "[" . date('Y-m-d H:i:s') . "] ERROR: " . $e->getMessage() . "\n";
}
