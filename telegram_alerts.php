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
    $tgItems = $db->t('tg_alert_items');
    $logFile = __DIR__ . '/telegram.log';
    $useLogicalClose = true;
    try {
        $row = $db->query("SELECT meta_value FROM {$metaTable} WHERE meta_key='ko_use_logical_close' LIMIT 1")->fetch();
        $useLogicalClose = !isset($row['meta_value']) || (string)$row['meta_value'] !== '0';
    } catch (\Throwable $e) {}

    try {
        $db->query("ALTER TABLE {$ks} ADD COLUMN transaction_comment TEXT NULL");
    } catch (\Throwable $e) {
    }

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
    $db->query(
        "CREATE TABLE IF NOT EXISTS {$tgItems} (
            transaction_date DATE NOT NULL,
            kitchen_stats_id BIGINT NOT NULL,
            transaction_id BIGINT NOT NULL,
            message_id BIGINT NULL,
            last_text_hash CHAR(40) NULL,
            last_seen_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (transaction_date, kitchen_stats_id),
            KEY idx_tg_items_tx (transaction_date, transaction_id),
            KEY idx_tg_items_msg (message_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
    );
    try {
        $db->query("ALTER TABLE {$tgItems} ADD COLUMN last_text_hash CHAR(40) NULL");
    } catch (\Throwable $e) {
    }

    $logger = new \App\Classes\EventLogger($db, 'telegram_alerts');
    $metaRepo = new \App\Classes\MetaRepository($db);
    $logLine = function (string $msg) use ($logFile): void {
        try {
            @file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
        } catch (\Throwable $e) {
        }
    };

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

    try {
        $webhookInfo = $bot->getWebhookInfo();
        $currentUrl = is_array($webhookInfo) ? (string)($webhookInfo['url'] ?? '') : '';
        $allowed = is_array($webhookInfo) && isset($webhookInfo['allowed_updates']) && is_array($webhookInfo['allowed_updates'])
            ? array_map('strval', $webhookInfo['allowed_updates'])
            : [];
        $needMessage = !in_array('message', $allowed, true);
        $targetUrl = 'https://veranda.my/telegram_webhook.php';
        if ($currentUrl !== $targetUrl || $needMessage) {
            $bot->setWebhook($targetUrl);
        }
    } catch (\Throwable $e) {
    }

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
    $logLine('START date=' . $today);

    try {
        $lastSyncAt = $getMeta('poster_last_sync_at', '');
        $last = $lastSyncAt !== '' ? strtotime($lastSyncAt) : 0;
        if ($last <= 0 || (time() - $last) >= 10) {
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

    $excludeSql = $useLogicalClose
        ? " AND COALESCE(exclude_from_dashboard, 0) = 0 "
        : " AND NOT (COALESCE(exclude_from_dashboard, 0) = 1 AND COALESCE(exclude_auto, 0) = 0) ";

    $overdueAll = 0;
    $overdueBar = 0;
    $overdueKitchen = 0;
    $queueAll = 0;
    $queueBar = 0;
    $queueKitchen = 0;
    try {
        $queueRows = $db->query(
            "SELECT station as st, COUNT(*) as cnt
             FROM {$ks}
             WHERE ready_pressed_at IS NULL
               AND ticket_sent_at IS NOT NULL
               AND transaction_date = ?
               AND status = 1
               AND COALESCE(was_deleted, 0) = 0
               {$excludeSql}
               AND NOT (COALESCE(dish_category_id, 0) = 47 OR COALESCE(dish_sub_category_id, 0) = 47)
             GROUP BY station",
            [$today]
        )->fetchAll();
        
        foreach ($queueRows as $r) {
            $st = (string)($r['st'] ?? '');
            $c = (int)$r['cnt'];
            $queueAll += $c;
            $isBar = ($st === '3' || $st === 'Bar Veranda');
            $isKitchen = ($st === '2' || $st === 'Kitchen' || $st === 'Main');
            if ($isBar) $queueBar += $c;
            else $queueKitchen += $c;
        }
        
        $overdueRows = $db->query(
            "SELECT station as st, COUNT(*) as cnt
             FROM {$ks}
             WHERE ready_pressed_at IS NULL
               AND ticket_sent_at IS NOT NULL
               AND transaction_date = ?
               AND status = 1
               AND COALESCE(was_deleted, 0) = 0
               {$excludeSql}
               AND NOT (COALESCE(dish_category_id, 0) = 47 OR COALESCE(dish_sub_category_id, 0) = 47)
               AND ticket_sent_at < ?
             GROUP BY station",
            [$today, $cutoffTime]
        )->fetchAll();
        
        foreach ($overdueRows as $r) {
            $st = (string)($r['st'] ?? '');
            $c = (int)$r['cnt'];
            $overdueAll += $c;
            $isBar = ($st === '3' || $st === 'Bar Veranda');
            $isKitchen = ($st === '2' || $st === 'Kitchen' || $st === 'Main');
            if ($isBar) $overdueBar += $c;
            else $overdueKitchen += $c;
        }
    } catch (\Throwable $e) {
    }

    try {
        $lockRow = $db->query("SELECT GET_LOCK('tg_status_msg', 0) AS l")->fetch();
        $locked = (int)($lockRow['l'] ?? 0) === 1;
        if (!$locked) {
            throw new \Exception('LOCK_BUSY');
        }

        $lastPosterSync = $getMeta('poster_last_sync_at', '');
        $statusText = 'Открыто чеков: ' . htmlspecialchars($openChecksDisplay) . "\n";
        $statusText .= 'Лимит времени: ' . (int)$waitLimit . " мин\n";
        $statusText .= 'В очереди: 🍸' . $queueBar . ' / 🍔' . $queueKitchen . "\n";
        $statusText .= 'Долгих блюд: 🍸' . $overdueBar . ' / 🍔' . $overdueKitchen . "\n";
        $statusText .= 'Время обновления: ' . ($lastPosterSync !== '' ? $lastPosterSync : date('Y-m-d H:i:s'));
        $statusHash = sha1($statusText);
        $prevStatusId = (int)$getMeta('telegram_status_msg_id', '0');
        $prevStatusHash = (string)$getMeta('telegram_status_msg_hash', '');
        $prevIdsRaw = (string)$getMeta('telegram_status_msg_ids_json', '[]');
        $prevIds = json_decode($prevIdsRaw, true);
        if (!is_array($prevIds)) $prevIds = [];
        $prevIds = array_values(array_filter($prevIds, fn($v) => is_numeric($v) && (int)$v > 0));

        // Always ensure status message exists: try to edit; if edit fails or message missing, send new
        if ($prevStatusId > 0) {
            $ok = $bot->editMessageText($prevStatusId, $statusText, null);
            if ($ok) {
                $setMeta('telegram_status_msg_hash', $statusHash);
            } else {
                $newId = $bot->sendMessageGetId($statusText, $tgThreadId);
                if ($newId) {
                    $setMeta('telegram_status_msg_id', (string)$newId);
                    $setMeta('telegram_status_msg_hash', $statusHash);
                    $bot->deleteMessage($prevStatusId);
                } else {
                    // If both edit and send failed, clear meta to force retry next run
                    $setMeta('telegram_status_msg_id', '0');
                    $setMeta('telegram_status_msg_hash', '');
                }
            }
        } else {
            $newId = $bot->sendMessageGetId($statusText, $tgThreadId);
            if ($newId) {
                $setMeta('telegram_status_msg_id', (string)$newId);
                $setMeta('telegram_status_msg_hash', $statusHash);
            } else {
                // Force retry on next run
                $setMeta('telegram_status_msg_id', '0');
                $setMeta('telegram_status_msg_hash', '');
            }
        }

        $currentId = (int)$getMeta('telegram_status_msg_id', '0');
        if ($currentId > 0) {
            $prevIds[] = $currentId;
        }
        $prevIds = array_values(array_unique(array_map(fn($v) => (int)$v, $prevIds)));
        $prevIds = array_slice($prevIds, -10);
        $setMeta('telegram_status_msg_ids_json', json_encode($prevIds, JSON_UNESCAPED_UNICODE));
        foreach ($prevIds as $id) {
            if ($currentId > 0 && (int)$id === $currentId) continue;
            $bot->deleteMessage((int)$id);
        }
        $db->query("SELECT RELEASE_LOCK('tg_status_msg')");
    } catch (\Throwable $e) {
        if ($e->getMessage() !== 'LOCK_BUSY') {
            $logLine('STATUS_FAIL ' . $e->getMessage());
        }
    }

    try {
        $logLine('OVERDUE total=' . $overdueAll . ' cutoff=' . $cutoffTime);
    } catch (\Throwable $e) {
    }

    $rows = $db->query(
        "SELECT id, transaction_id, receipt_number, table_number, waiter_name, transaction_comment, dish_name, ticket_sent_at
         FROM {$ks}
         WHERE ready_pressed_at IS NULL
           AND ticket_sent_at IS NOT NULL
           AND transaction_date = ?
           AND status = 1
           AND COALESCE(was_deleted, 0) = 0
           {$excludeSql}
           AND NOT (COALESCE(dish_category_id, 0) = 47 OR COALESCE(dish_sub_category_id, 0) = 47)
           AND ticket_sent_at < ?
         ORDER BY transaction_id ASC, ticket_sent_at ASC, id ASC",
        [$today, $cutoffTime]
    )->fetchAll();
    if (!is_array($rows)) $rows = [];
    $logLine('CANDIDATES rows=' . count($rows));

    $nowTs = time();
    $nowDt = date('Y-m-d H:i:s', $nowTs);
    $sentCount = 0;
    $editedCount = 0;
    $deletedCount = 0;
    $unchangedCount = 0;

    try {
        $oldThreads = $db->query(
            "SELECT transaction_id, message_id
             FROM {$tgThreads}
             WHERE transaction_date = ?",
            [$today]
        )->fetchAll();
        if (!is_array($oldThreads)) $oldThreads = [];
        foreach ($oldThreads as $t) {
            $msgId = (int)($t['message_id'] ?? 0);
            if ($msgId > 0) {
                $bot->deleteMessage($msgId);
                $deletedCount++;
                $logLine('DELETE_OLD tx=' . (int)($t['transaction_id'] ?? 0) . ' msg=' . $msgId);
            }
        }
        $db->query("DELETE FROM {$tgThreads} WHERE transaction_date = ?", [$today]);
    } catch (\Throwable $e) {
    }

    $existingItems = $db->query(
        "SELECT kitchen_stats_id, transaction_id, message_id, last_text_hash
         FROM {$tgItems}
         WHERE transaction_date = ?",
        [$today]
    )->fetchAll();
    if (!is_array($existingItems)) {
        $existingItems = [];
    }
    $existingByItem = [];
    foreach ($existingItems as $e) {
        $kid = (int)($e['kitchen_stats_id'] ?? 0);
        if ($kid <= 0) continue;
        $existingByItem[$kid] = $e;
    }

    $candidateIds = [];
    foreach ($rows as $r) {
        $id = (int)($r['id'] ?? 0);
        if ($id > 0) $candidateIds[$id] = true;
    }

    foreach ($existingByItem as $kid => $e) {
        if (isset($candidateIds[$kid])) continue;
        $msgId = (int)($e['message_id'] ?? 0);
        if ($msgId > 0) {
            $deleted = $bot->deleteMessage($msgId);
            if ($deleted) {
                $deletedCount++;
                $logLine('DELETE item=' . $kid . ' msg=' . $msgId);
            }
        }
        $db->query("DELETE FROM {$tgItems} WHERE transaction_date = ? AND kitchen_stats_id = ?", [$today, $kid]);
    }

    foreach ($rows as $r) {
        $kid = (int)($r['id'] ?? 0);
        $txId = (int)($r['transaction_id'] ?? 0);
        if ($kid <= 0 || $txId <= 0) continue;

        $receipt = trim((string)($r['receipt_number'] ?? ''));
        $table = trim((string)($r['table_number'] ?? ''));
        $waiter = trim((string)($r['waiter_name'] ?? ''));
        $comment = trim((string)($r['transaction_comment'] ?? ''));
        $dish = trim((string)($r['dish_name'] ?? ''));
        $sentAt = trim((string)($r['ticket_sent_at'] ?? ''));

        if ($receipt === '') $receipt = (string)$txId;
        if ($table === '') $table = '—';
        if ($waiter === '') $waiter = '—';
        if ($dish === '') $dish = '—';

        $sentTs = $sentAt !== '' ? strtotime($sentAt) : 0;
        $diffSec = $sentTs > 0 ? max(0, $nowTs - $sentTs) : 0;
        $hh = (int)floor($diffSec / 3600);
        $mm = (int)floor(($diffSec % 3600) / 60);
        $ss = (int)($diffSec % 60);
        $elapsed = ($hh > 0 ? (string)$hh . ':' . str_pad((string)$mm, 2, '0', STR_PAD_LEFT) : str_pad((string)$mm, 2, '0', STR_PAD_LEFT))
            . ':' . str_pad((string)$ss, 2, '0', STR_PAD_LEFT);
        $start = $sentTs > 0 ? date('H:i:s', $sentTs) : '—';

        $keyboard = [[
            ['text' => 'Игнор❗️', 'callback_data' => 'ignore_item:' . $kid],
            ['text' => 'Игнор Чек‼️', 'callback_data' => 'ignore_tx:' . $txId],
        ]];

        $text = '<b>Чек: ' . htmlspecialchars($receipt) . ' | Стол ' . htmlspecialchars($table) . "</b>\n";
        $text .= 'Офик: ' . htmlspecialchars($waiter);
        if ($comment !== '') {
            $text .= ' <i>' . htmlspecialchars($comment) . '</i>';
        }
        $text .= "\n";
        $text .= 'Блюдо: ' . htmlspecialchars($dish) . "\n";
        $text .= 'Старт: <b>' . htmlspecialchars($start) . '</b> Ждет: <b>' . $elapsed . '</b>';

        $textHash = sha1($text . '|' . json_encode($keyboard, JSON_UNESCAPED_UNICODE));
        $prev = $existingByItem[$kid] ?? null;
        $prevMsgId = $prev ? (int)($prev['message_id'] ?? 0) : 0;
        $prevHash = $prev ? (string)($prev['last_text_hash'] ?? '') : '';
        
        // Logic to update message only once per minute to save Telegram API limits
        $lastSeen = $prev ? (string)($prev['last_seen_at'] ?? '') : '';
        $skipEdit = false;
        if ($prevMsgId > 0 && $prevHash !== $textHash && $lastSeen !== '') {
            $lastSeenTime = strtotime($lastSeen);
            $nowTime = time();
            if (($nowTime - $lastSeenTime) < 60) {
                // If less than 60 seconds have passed since last edit, skip this edit to save limits
                $skipEdit = true;
                $textHash = $prevHash; // pretend it didn't change
            }
        }

        $currentMsgId = $prevMsgId;
        if ($prevMsgId > 0 && $prevHash === $textHash) {
            // Only update last_seen_at if we didn't artificially skip the edit
            if (!$skipEdit) {
                $db->query(
                    "UPDATE {$tgItems}
                     SET last_seen_at = ?, updated_at = CURRENT_TIMESTAMP
                     WHERE transaction_date = ? AND kitchen_stats_id = ?",
                    [$nowDt, $today, $kid]
                );
            }
            $unchangedCount++;
        } else {
            $editedOk = false;
            if ($prevMsgId > 0) {
                $editedOk = $bot->editMessageText($prevMsgId, $text, $keyboard);
                if ($editedOk) {
                    $editedCount++;
                    $logLine('EDIT item=' . $kid . ' tx=' . $txId . ' msg=' . $prevMsgId . ' receipt=' . $receipt);
                    try {
                        $db->query(
                            "UPDATE {$ks}
                             SET tg_message_id = ?,
                                 tg_sent_at = COALESCE(tg_sent_at, ?),
                                 tg_last_edit_at = ?
                             WHERE id = ?",
                            [$prevMsgId, $nowDt, $nowDt, $kid]
                        );
                    } catch (\Throwable $e) {
                    }
                    $db->query(
                        "UPDATE {$tgItems}
                         SET transaction_id = ?, last_text_hash = ?, last_seen_at = ?, updated_at = CURRENT_TIMESTAMP
                         WHERE transaction_date = ? AND kitchen_stats_id = ?",
                        [$txId, $textHash, $nowDt, $today, $kid]
                    );
                    $currentMsgId = $prevMsgId;
                } else {
                    $logLine('EDIT_FAIL item=' . $kid . ' tx=' . $txId . ' msg=' . $prevMsgId . ' receipt=' . $receipt);
                }
            }
            if (!$editedOk) {
                if ($prevMsgId > 0) {
                    $bot->deleteMessage($prevMsgId);
                }
                $newMsgId = $bot->sendMessageGetIdWithKeyboard($text, $keyboard, $tgThreadId);
                if ($newMsgId) {
                    $sentCount++;
                    $currentMsgId = (int)$newMsgId;
                    $logLine('SEND item=' . $kid . ' tx=' . $txId . ' msg=' . $newMsgId . ' receipt=' . $receipt);
                    try {
                        $db->query(
                            "UPDATE {$ks}
                             SET tg_message_id = ?,
                                 tg_sent_at = COALESCE(tg_sent_at, ?),
                                 tg_last_edit_at = ?
                             WHERE id = ?",
                            [(int)$newMsgId, $nowDt, $nowDt, $kid]
                        );
                    } catch (\Throwable $e) {
                    }
                    $db->query(
                        "INSERT INTO {$tgItems} (transaction_date, kitchen_stats_id, transaction_id, message_id, last_text_hash, last_seen_at)
                         VALUES (?, ?, ?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE
                            transaction_id = VALUES(transaction_id),
                            message_id = VALUES(message_id),
                            last_text_hash = VALUES(last_text_hash),
                            last_seen_at = VALUES(last_seen_at),
                            updated_at = CURRENT_TIMESTAMP",
                        [$today, $kid, $txId, $currentMsgId, $textHash, $nowDt]
                    );
                } else {
                    $logLine('SEND_FAIL item=' . $kid . ' tx=' . $txId . ' receipt=' . $receipt);
                }
            }
        }

        if ($currentMsgId > 0 && (!isset($existingByItem[$kid]) || (int)($existingByItem[$kid]['message_id'] ?? 0) !== $currentMsgId)) {
            $existingByItem[$kid] = [
                'kitchen_stats_id' => $kid,
                'transaction_id' => $txId,
                'message_id' => $currentMsgId,
                'last_text_hash' => $textHash
            ];
        }
    }

    $durationMs = (int)round((microtime(true) - $startedAt) * 1000);
    $setMeta('telegram_last_run_at', date('Y-m-d H:i:s'));
    $setMeta('telegram_last_run_result', 'duration_ms=' . $durationMs . '; open=' . $openChecksDisplay . '; wait=' . $waitLimit . '; sent=' . $sentCount . '; edited=' . $editedCount . '; deleted=' . $deletedCount . '; unchanged=' . $unchangedCount);
    $setMeta('telegram_last_run_error', '');
    $logLine('DONE duration_ms=' . $durationMs . ' open=' . $openChecksDisplay . ' wait=' . $waitLimit . ' sent=' . $sentCount . ' edited=' . $editedCount . ' deleted=' . $deletedCount . ' unchanged=' . $unchangedCount);
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
    try {
        @file_put_contents(__DIR__ . '/telegram.log', '[' . date('Y-m-d H:i:s') . '] ERROR ' . $e->getMessage() . "\n", FILE_APPEND);
    } catch (\Throwable $e3) {
    }
    echo "[" . date('Y-m-d H:i:s') . "] ERROR: " . $e->getMessage() . "\n";
}
