<?php
date_default_timezone_set('Asia/Ho_Chi_Minh');

require_once __DIR__ . '/src/classes/Database.php';
require_once __DIR__ . '/src/classes/PosterAPI.php';
require_once __DIR__ . '/src/classes/KitchenAnalytics.php';
require_once __DIR__ . '/src/classes/TelegramBot.php';
require_once __DIR__ . '/src/classes/CodemealAPI.php';
require_once __DIR__ . '/src/classes/ChefAssistantSync.php';

// Load .env
if (file_exists(__DIR__ . '/.env')) {
    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($name, $value) = explode('=', $line, 2);
        $_ENV[$name] = trim($value);
    }
}

if (php_sapi_name() !== 'cli') {
    http_response_code(404);
    exit;
}

$dbHost = $_ENV['DB_HOST'] ?? 'localhost';
$dbName = $_ENV['DB_NAME'] ?? 'veranda_my';
$dbUser = $_ENV['DB_USER'] ?? 'veranda_my';
$dbPass = $_ENV['DB_PASS'] ?? '';
$token = $_ENV['POSTER_API_TOKEN'] ?? '';
$tgToken = $_ENV['TELEGRAM_BOT_TOKEN'] ?? '';
$tgChatId = $_ENV['TELEGRAM_ALERT_CHAT_ID'] ?? ($_ENV['TELEGRAM_CHAT_ID'] ?? '');
if ($tgChatId === '') {
    $tgChatId = '-1003397075474';
}
$tgThreadId = isset($_ENV['TELEGRAM_ALERT_THREAD_ID']) && $_ENV['TELEGRAM_ALERT_THREAD_ID'] !== ''
    ? (int)$_ENV['TELEGRAM_ALERT_THREAD_ID']
    : (isset($_ENV['TELEGRAM_THREAD_ID']) && $_ENV['TELEGRAM_THREAD_ID'] !== '' ? (int)$_ENV['TELEGRAM_THREAD_ID'] : null);

if (empty($tgToken) || empty($tgChatId)) {
    echo "[" . date('Y-m-d H:i:s') . "] Telegram alerts skipped: TELEGRAM_BOT_TOKEN/TELEGRAM_CHAT_ID not configured.\n";
    $scriptFile = realpath($_SERVER['SCRIPT_FILENAME'] ?? '') ?: '';
    if ($scriptFile !== '' && realpath(__FILE__) !== false && realpath(__FILE__) !== $scriptFile) {
        return;
    }
    exit(0);
}

$lockPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'veranda_telegram_alerts.lock';
$lockFp = @fopen($lockPath, 'c');
if (!$lockFp || !@flock($lockFp, LOCK_EX | LOCK_NB)) {
    exit(0);
}
register_shutdown_function(function () use ($lockFp) {
    if (is_resource($lockFp)) {
        @flock($lockFp, LOCK_UN);
        @fclose($lockFp);
    }
});

$employeesById = [];
$historyByTxId = [];
$getTxHistory = function (\App\Classes\PosterAPI $api, int $transactionId, array &$historyByTxId): array {
    if (isset($historyByTxId[$transactionId])) {
        return $historyByTxId[$transactionId];
    }
    try {
        $history = $api->request('dash.getTransactionHistory', ['transaction_id' => $transactionId]);
        if (!is_array($history)) {
            $history = [];
        }
    } catch (\Exception $e) {
        $history = [];
    }
    $historyByTxId[$transactionId] = $history;
    return $history;
};
$isDishDeletedFromHistory = function (array $history, int $dishId): bool {
    $lastStateTime = 0;
    $isDeleted = false;
    foreach ($history as $event) {
        $type = $event['type_history'] ?? '';
        $value = (int)($event['value'] ?? 0);
        if ($value !== $dishId) {
            continue;
        }
        $t = (int)($event['time'] ?? 0);
        if ($type === 'changeitemcount') {
            $count = (int)($event['value2'] ?? 0);
            if ($t >= $lastStateTime) {
                $lastStateTime = $t;
                $isDeleted = $count <= 0;
            }
        } elseif ($type === 'deleteitem' || $type === 'delete') {
            if ($t >= $lastStateTime) {
                $lastStateTime = $t;
                $isDeleted = true;
            }
        }
    }
    return $isDeleted;
};
$resolveWaiterName = function (\App\Classes\PosterAPI $api, int $transactionId, array $tx, array &$employeesById): string {
    $name = trim((string)($tx['name'] ?? ''));
    if ($name !== '' && !is_numeric($name)) {
        return $name;
    }

    $empName = trim((string)($tx['employee_name'] ?? ''));
    if ($empName !== '') {
        return $empName;
    }

    $txUserId = isset($tx['user_id']) ? (int)$tx['user_id'] : 0;
    if ($txUserId > 0 && isset($employeesById[$txUserId])) {
        return $employeesById[$txUserId];
    }

    $historyUserId = 0;
    try {
        $history = $api->request('dash.getTransactionHistory', ['transaction_id' => $transactionId]);
        foreach ($history as $event) {
            $type = $event['type_history'] ?? '';
            if ($type === 'open' || $type === 'print') {
                $candidate = (int)($event['value'] ?? 0);
                if ($candidate > 0) {
                    $historyUserId = $candidate;
                    break;
                }
            }
        }
    } catch (\Exception $e) {
    }

    $targetUserId = $txUserId > 0 ? $txUserId : $historyUserId;
    if ($targetUserId > 0 && isset($employeesById[$targetUserId])) {
        return $employeesById[$targetUserId];
    }

    if (empty($employeesById)) {
        try {
            $employees = $api->request('access.getEmployees');
            foreach ($employees as $employee) {
                $id = (int)($employee['user_id'] ?? 0);
                $employeeName = trim((string)($employee['name'] ?? ''));
                if ($id > 0 && $employeeName !== '') {
                    $employeesById[$id] = $employeeName;
                }
            }
        } catch (\Exception $e) {
        }
    }

    if ($targetUserId > 0 && isset($employeesById[$targetUserId])) {
        return $employeesById[$targetUserId];
    }

    return '';
};

try {
    $tableSuffix = (string)($_ENV['DB_TABLE_SUFFIX'] ?? '');
    $db = new \App\Classes\Database($dbHost, $dbName, $dbUser, $dbPass, $tableSuffix);
    $ks = $db->t('kitchen_stats');
    $metaTable = $db->t('system_meta');
    $tgm = $db->t('tg_alert_messages');
    $api = new \App\Classes\PosterAPI($token);
    $bot = new \App\Classes\TelegramBot($tgToken, $tgChatId);
    $columnExists = function (\App\Classes\Database $db, string $dbName, string $table, string $column): bool {
        $row = $db->query(
            "SELECT COUNT(*) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?",
            [$dbName, $table, $column]
        )->fetch();
        return (int)($row['c'] ?? 0) > 0;
    };
    if (!$columnExists($db, $dbName, $ks, 'waiter_name')) {
        $db->query("ALTER TABLE {$ks} ADD COLUMN waiter_name VARCHAR(255) NULL AFTER table_number");
    }
    if (!$columnExists($db, $dbName, $ks, 'tg_message_id')) {
        $db->query("ALTER TABLE {$ks} ADD COLUMN tg_message_id BIGINT NULL AFTER prob_close_at");
    }
    if (!$columnExists($db, $dbName, $ks, 'tg_acknowledged')) {
        $db->query("ALTER TABLE {$ks} ADD COLUMN tg_acknowledged TINYINT(1) NOT NULL DEFAULT 0 AFTER tg_message_id");
    }
    if (!$columnExists($db, $dbName, $ks, 'tg_acknowledged_at')) {
        $db->query("ALTER TABLE {$ks} ADD COLUMN tg_acknowledged_at DATETIME NULL AFTER tg_acknowledged");
    }
    if (!$columnExists($db, $dbName, $ks, 'tg_acknowledged_by')) {
        $db->query("ALTER TABLE {$ks} ADD COLUMN tg_acknowledged_by VARCHAR(255) NULL AFTER tg_acknowledged_at");
    }
    if (!$columnExists($db, $dbName, $ks, 'ready_chass_at')) {
        $db->query("ALTER TABLE {$ks} ADD COLUMN ready_chass_at DATETIME NULL AFTER ready_pressed_at");
    }
    if (!$columnExists($db, $dbName, $ks, 'prob_close_at')) {
        $db->query("ALTER TABLE {$ks} ADD COLUMN prob_close_at DATETIME NULL AFTER ready_chass_at");
    }
    if (!$columnExists($db, $dbName, $ks, 'dish_category_id')) {
        $db->query("ALTER TABLE {$ks} ADD COLUMN dish_category_id BIGINT NULL AFTER dish_id");
    }
    if (!$columnExists($db, $dbName, $ks, 'dish_sub_category_id')) {
        $db->query("ALTER TABLE {$ks} ADD COLUMN dish_sub_category_id BIGINT NULL AFTER dish_category_id");
    }
    if (!$columnExists($db, $dbName, $ks, 'item_seq')) {
        $db->query("ALTER TABLE {$ks} ADD COLUMN item_seq INT NOT NULL DEFAULT 1 AFTER dish_id");
    }

    $db->query("CREATE TABLE IF NOT EXISTS {$metaTable} (
        meta_key VARCHAR(100) PRIMARY KEY,
        meta_value VARCHAR(255) NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
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

    if (!$columnExists($db, $dbName, $tgm, 'last_edited_at')) {
        $db->query("ALTER TABLE {$tgm} ADD COLUMN last_edited_at DATETIME NULL AFTER last_text_hash");
    }
    $db->query("UPDATE {$tgm} SET last_edited_at = created_at WHERE last_edited_at IS NULL");

    $getMeta = function (string $key) use ($db): string {
        $row = $db->query("SELECT meta_value FROM {$metaTable} WHERE meta_key = ? LIMIT 1", [$key])->fetch();
        return $row ? (string)$row['meta_value'] : '';
    };

    $setMeta = function (string $key, string $value) use ($db): void {
        $db->query(
            "INSERT INTO {$metaTable} (meta_key, meta_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value), updated_at = CURRENT_TIMESTAMP",
            [$key, $value]
        );
    };

    $maybeSendAuthAlert = function () use ($getMeta, $setMeta, $bot, $tgThreadId): void {
        $last = $getMeta('chefassistant_auth_notified_at');
        if ($last !== '') {
            $ts = strtotime($last);
            if ($ts !== false && $ts > time() - 1800) {
                return;
            }
        }
        $bot->sendMessage("@zapleosoft we have problem with authorisation", $tgThreadId);
        $setMeta('chefassistant_auth_notified_at', date('Y-m-d H:i:s'));
    };

    $process = function (int $cycle) use ($db, $api, $bot, &$employeesById, &$historyByTxId, $getTxHistory, $isDishDeletedFromHistory, $resolveWaiterName, $tgThreadId, $maybeSendAuthAlert): void {
    $ks = $db->t('kitchen_stats');
    $metaTable = $db->t('system_meta');
    $tgm = $db->t('tg_alert_messages');
    // 1. Сначала находим все записи, у которых есть tg_message_id (т.е. уведомление было отправлено)
    $today = date('Y-m-d');
    $computeProbCloseAt = function (string $date) use ($db): void {
        $ks = $db->t('kitchen_stats');
        $readyRows = $db->query(
            "SELECT receipt_number, station, ready_pressed_at, ready_chass_at
             FROM {$ks}
             WHERE transaction_date = ?
               AND receipt_number REGEXP '^[0-9]+$'
               AND COALESCE(was_deleted, 0) = 0
               AND NOT (COALESCE(dish_category_id, 0) = 47 OR COALESCE(dish_sub_category_id, 0) = 47)
               AND (ready_pressed_at IS NOT NULL OR ready_chass_at IS NOT NULL)",
            [$date]
        )->fetchAll();

        $byReceiptStation = [];
        foreach ($readyRows as $r) {
            $receipt = (int)($r['receipt_number'] ?? 0);
            $station = (string)($r['station'] ?? '');
            if ($receipt <= 0 || $station === '') continue;

            $t1 = $r['ready_pressed_at'] ?? null;
            $t2 = $r['ready_chass_at'] ?? null;
            $end = null;
            if ($t1 && $t2) {
                $end = strtotime($t1) <= strtotime($t2) ? $t1 : $t2;
            } elseif ($t1) {
                $end = $t1;
            } elseif ($t2) {
                $end = $t2;
            }
            if ($end === null) continue;

            if (!isset($byReceiptStation[$receipt][$station])) {
                $byReceiptStation[$receipt][$station] = $end;
            } else {
                if (strtotime($end) < strtotime($byReceiptStation[$receipt][$station])) {
                    $byReceiptStation[$receipt][$station] = $end;
                }
            }
        }

        $targets = $db->query(
            "SELECT id, receipt_number, station, prob_close_at, ticket_sent_at
             FROM {$ks}
             WHERE transaction_date = ?
               AND status = 1
               AND receipt_number REGEXP '^[0-9]+$'
               AND COALESCE(was_deleted, 0) = 0
               AND NOT (COALESCE(dish_category_id, 0) = 47 OR COALESCE(dish_sub_category_id, 0) = 47)
               AND ticket_sent_at IS NOT NULL
               AND ready_pressed_at IS NULL
               AND ready_chass_at IS NULL",
            [$date]
        )->fetchAll();

        $upd = $db->getPdo()->prepare("UPDATE {$ks} SET prob_close_at = ? WHERE id = ?");
        foreach ($targets as $t) {
            $id = (int)($t['id'] ?? 0);
            $receipt = (int)($t['receipt_number'] ?? 0);
            $station = (string)($t['station'] ?? '');
            if ($id <= 0 || $receipt <= 0 || $station === '') continue;
            $sentAt = (string)($t['ticket_sent_at'] ?? '');
            $sentTs = $sentAt !== '' ? strtotime($sentAt) : false;
            if ($sentTs === false || $sentTs <= 0) continue;

            $candidate = null;
            for ($d = 1; $d <= 3; $d++) {
                $next = $receipt + $d;
                if (isset($byReceiptStation[$next][$station])) {
                    $candidate = $byReceiptStation[$next][$station];
                    break;
                }
            }
            if ($candidate !== null) {
                $candTs = strtotime($candidate);
                if ($candTs === false || $candTs < $sentTs) {
                    $candidate = null;
                }
            }

            $current = $t['prob_close_at'] ?? null;
            if (($current === null || $current === '') && $candidate === null) continue;
            if ($candidate !== null && (string)$candidate === (string)$current) continue;

            $upd->execute([$candidate, $id]);
        }
    };
    if ($cycle === 0) {
        $computeProbCloseAt($today);
    }
    $cleanupFrom = date('Y-m-d H:i:s', time() - 2 * 60 * 60);
    $activeAlerts = $db->query(
        "SELECT * FROM {$ks}
         WHERE tg_message_id IS NOT NULL
           AND ticket_sent_at IS NOT NULL
           AND ticket_sent_at >= ?",
        [$cleanupFrom]
    )->fetchAll();

    foreach ($activeAlerts as $item) {
        $shouldDelete = false;
        
        // Проверяем, готово ли уже блюдо или закрыт ли чек
        $sentTs = !empty($item['ticket_sent_at']) ? strtotime($item['ticket_sent_at']) : false;
        if (
            (int)($item['tg_acknowledged'] ?? 0) === 1
            || (int)($item['was_deleted'] ?? 0) === 1
            || (int)($item['exclude_from_dashboard'] ?? 0) === 1
            || $item['ready_pressed_at'] !== null
            || !empty($item['ready_chass_at'])
            || (int)$item['status'] > 1
        ) {
            $shouldDelete = true;
        } else {
            // Дополнительная проверка через API для надежности
            try {
                $res = $api->request('dash.getTransaction', ['transaction_id' => $item['transaction_id']]);
                $tx = $res[0] ?? $res;
                $apiStatus = (int)($tx['status'] ?? 1);
                $apiReason = isset($tx['reason']) && $tx['reason'] !== '' ? (int)$tx['reason'] : null;
                $apiPayType = isset($tx['pay_type']) ? (int)$tx['pay_type'] : null;
                
                // Проверяем историю на предмет готовности и удаления конкретного блюда
                $history = $getTxHistory($api, (int)$item['transaction_id'], $historyByTxId);
                $dishId = (int)$item['dish_id'];
                $readyTime = null;
                $isDeletedInTx = $isDishDeletedFromHistory($history, $dishId);
                $currentCount = 0;
                foreach ($history as $event) {
                    $type = $event['type_history'] ?? '';
                    if ($type === 'sendtokitchen') {
                        $valText = $event['value_text'] ?? null;
                        if (is_string($valText)) {
                            $decoded = json_decode($valText, true);
                            $valText = is_array($decoded) ? $decoded : [];
                        }
                        if (!is_array($valText)) {
                            $valText = [];
                        }
                        foreach ($valText as $v) {
                            if ((int)($v['product_id'] ?? 0) === $dishId) {
                                $currentCount += (int)($v['count'] ?? 0);
                            }
                        }
                    }
                    if ($type === 'finishedcooking' && (int)($event['value'] ?? 0) === $dishId) {
                        $readyTime = date('Y-m-d H:i:s', ($event['time'] ?? 0) / 1000);
                    }
                }

                if ($isDeletedInTx) {
                    $shouldDelete = true;
                    $db->query("UPDATE {$ks} SET was_deleted = 1 WHERE id = ?", [$item['id']]);
                } elseif ($currentCount <= 0) {
                    $shouldDelete = true;
                    $db->query("UPDATE {$ks} SET was_deleted = 1 WHERE id = ?", [$item['id']]);
                } elseif ($readyTime !== null) {
                    $shouldDelete = true;
                    $db->query("UPDATE {$ks} SET ready_pressed_at = ? WHERE id = ?", [$readyTime, $item['id']]);
                } elseif ($apiStatus > 1 || $apiReason !== null) {
                    $shouldDelete = true;
                    if ($apiStatus <= 1) {
                        $apiStatus = 2;
                    }
                    $db->query("UPDATE {$ks} SET status = ?, pay_type = ?, close_reason = ? WHERE transaction_id = ?", [$apiStatus, $apiPayType, $apiReason, $item['transaction_id']]);
                }
            } catch (\Exception $e) {
                error_log("API Check failed for TX {$item['transaction_id']}: " . $e->getMessage());
            }
        }

        // Если блюдо перестало быть "зависшим", удаляем старое сообщение
        if ($shouldDelete) {
            $deleted = $bot->deleteMessage((int)$item['tg_message_id']);
            if ($deleted) {
                $db->query("UPDATE {$ks} SET tg_message_id = NULL WHERE id = ?", [$item['id']]);
                $db->query("DELETE FROM {$tgm} WHERE kitchen_stats_id = ?", [(int)$item['id']]);
                echo "[" . date('Y-m-d H:i:s') . "] Removed outdated alert for: {$item['dish_name']} (Table: {$item['table_number']})\n";
            } else {
                $db->query("UPDATE {$ks} SET tg_message_id = NULL WHERE id = ?", [$item['id']]);
                $db->query("DELETE FROM {$tgm} WHERE kitchen_stats_id = ?", [(int)$item['id']]);
                echo "[" . date('Y-m-d H:i:s') . "] Failed to remove outdated alert for: {$item['dish_name']} (Table: {$item['table_number']})\n";
            }
        }
    }

    // 2. Получаем настройки тайминга
    $settingKeys = [
        'alert_timing_low_load' => 20,
        'alert_load_threshold' => 25,
        'alert_timing_high_load' => 30,
        'alert_ack_snooze_minutes' => 15,
    'exclude_partners_from_load' => 0
    ];
    $settings = [];
    foreach ($settingKeys as $key => $default) {
        $row = $db->query("SELECT meta_value FROM {$metaTable} WHERE meta_key = ? LIMIT 1", [$key])->fetch();
    $settings[$key] = $row ? $row['meta_value'] : $default;
    if (is_numeric($default)) {
        $settings[$key] = (int)$settings[$key];
    }
    }

    // Считаем количество открытых чеков
    $partnersCount = 0;
    $otherCount = 0;
    if (!empty($settings['exclude_partners_from_load'])) {
        $partnersCountRow = $db->query("SELECT COUNT(DISTINCT transaction_id) as c FROM {$ks} WHERE status = 1 AND transaction_date = ? AND table_number = 'Partners'", [$today])->fetch();
        $partnersCount = (int)($partnersCountRow['c'] ?? 0);
        $otherCountRow = $db->query("SELECT COUNT(DISTINCT transaction_id) as c FROM {$ks} WHERE status = 1 AND transaction_date = ? AND table_number != 'Partners'", [$today])->fetch();
        $otherCount = (int)($otherCountRow['c'] ?? 0);
        $loadCalculationCount = $otherCount;
        $openChecksDisplay = "{$otherCount}+{$partnersCount}";
    } else {
        $openCountRow = $db->query("SELECT COUNT(DISTINCT transaction_id) as c FROM {$ks} WHERE status = 1 AND transaction_date = ?", [$today])->fetch();
        $openCount = (int)($openCountRow['c'] ?? 0);
        $loadCalculationCount = $openCount;
        $openChecksDisplay = $openCount;
    }

    // Определяем тайминг на основе нагрузки
    $waitLimit = ($loadCalculationCount < $settings['alert_load_threshold']) 
        ? $settings['alert_timing_low_load'] 
        : $settings['alert_timing_high_load'];

    echo "[" . date('Y-m-d H:i:s') . "] Open checks: $openChecksDisplay. Using wait limit: $waitLimit min.\n";

    $hookahAlerts = $db->query(
        "SELECT id, tg_message_id
         FROM {$ks}
         WHERE transaction_date = ?
           AND tg_message_id IS NOT NULL
           AND (COALESCE(dish_category_id, 0) = 47 OR COALESCE(dish_sub_category_id, 0) = 47)",
        [$today]
    )->fetchAll();
    foreach ($hookahAlerts as $item) {
        if (!empty($item['tg_message_id'])) {
            $bot->deleteMessage((int)$item['tg_message_id']);
        }
        $db->query("UPDATE {$ks} SET tg_message_id = NULL WHERE id = ?", [(int)$item['id']]);
        $db->query("DELETE FROM {$tgm} WHERE kitchen_stats_id = ?", [(int)$item['id']]);
    }

    // 3. Теперь ищем актуальные задержки
    $cutoffTime = date('Y-m-d H:i:s', strtotime("-$waitLimit minutes"));
    $ackSnooze = (int)($settings['alert_ack_snooze_minutes'] ?? 0);
    $ackCutoffTime = $ackSnooze > 0 ? date('Y-m-d H:i:s', strtotime("-$ackSnooze minutes")) : null;
    $query = "SELECT * FROM {$ks} 
              WHERE ready_pressed_at IS NULL 
              AND ready_chass_at IS NULL
              AND ticket_sent_at IS NOT NULL 
              AND transaction_date = ?
              AND status = 1
              AND COALESCE(was_deleted, 0) = 0
              AND COALESCE(exclude_from_dashboard, 0) = 0
              AND NOT (COALESCE(dish_category_id, 0) = 47 OR COALESCE(dish_sub_category_id, 0) = 47)
              AND (
                    COALESCE(tg_acknowledged, 0) = 0
                 OR (tg_acknowledged_at IS NOT NULL AND tg_acknowledged_at < ?)
              )
              AND ticket_sent_at < ?
              ORDER BY ticket_sent_at ASC";
    
    if ($ackCutoffTime === null) {
        $ackCutoffTime = '1970-01-01 00:00:00';
    }
    $delayedItems = $db->query($query, [$today, $ackCutoffTime, $cutoffTime])->fetchAll();

    if (!empty($delayedItems)) {
        foreach ($delayedItems as $item) {
            $canSendAlert = false;

            // Перед отправкой проверяем в API, что чек все еще открыт и в работе
            try {
                $res = $api->request('dash.getTransaction', ['transaction_id' => $item['transaction_id']]);
                $tx = $res[0] ?? $res;
                $apiStatus = (int)($tx['status'] ?? 1);
                $apiReason = isset($tx['reason']) && $tx['reason'] !== '' ? (int)$tx['reason'] : null;
                $apiPayType = isset($tx['pay_type']) ? (int)$tx['pay_type'] : null;
                
                // Проверяем историю на предмет готовности/удаления и количества (с учетом экземпляров)
                $history = $getTxHistory($api, (int)$item['transaction_id'], $historyByTxId);
                $dishId = (int)$item['dish_id'];
                $itemSeq = (int)($item['item_seq'] ?? 1);
                if ($itemSeq <= 0) $itemSeq = 1;
                $readyTimes = [];
                $sentCount = null;
                $isDeletedInTx = $isDishDeletedFromHistory($history, $dishId);
                $lastCountAt = 0;
                $currentCount = null;
                foreach ($history as $event) {
                    if (($event['type_history'] ?? '') === 'sendtokitchen') {
                        $valText = $event['value_text'] ?? null;
                        if (is_string($valText)) {
                            $decoded = json_decode($valText, true);
                            $valText = is_array($decoded) ? $decoded : [];
                        }
                        if (!is_array($valText)) {
                            $valText = [];
                        }
                        $tmpCount = 0;
                        foreach ($valText as $v) {
                            if ((int)($v['product_id'] ?? 0) === $dishId) {
                                $tmpCount += (int)($v['count'] ?? 0);
                            }
                        }
                        if ($tmpCount > 0) {
                            $sentCount = ($sentCount ?? 0) + $tmpCount;
                        }
                    }
                    if (($event['type_history'] ?? '') === 'finishedcooking' && (int)($event['value'] ?? 0) === $dishId) {
                        $readyTimes[] = date('Y-m-d H:i:s', ((int)($event['time'] ?? 0)) / 1000);
                    }
                    if (($event['type_history'] ?? '') === 'changeitemcount' && (int)($event['value'] ?? 0) === $dishId) {
                        $t = (int)($event['time'] ?? 0);
                        if ($t >= $lastCountAt) {
                            $lastCountAt = $t;
                            $currentCount = (int)($event['value2'] ?? 0);
                        }
                    }
                    if ((($event['type_history'] ?? '') === 'deleteitem' || ($event['type_history'] ?? '') === 'delete') && (int)($event['value'] ?? 0) === $dishId) {
                        $t = (int)($event['time'] ?? 0);
                        if ($t >= $lastCountAt) {
                            $lastCountAt = $t;
                            $currentCount = 0;
                        }
                    }
                }

                if ($isDeletedInTx) {
                    $db->query("UPDATE {$ks} SET was_deleted = 1 WHERE id = ?", [$item['id']]);
                    if (!empty($item['tg_message_id'])) {
                        $bot->deleteMessage((int)$item['tg_message_id']);
                        $db->query("UPDATE {$ks} SET tg_message_id = NULL WHERE id = ?", [$item['id']]);
                        $db->query("DELETE FROM {$tgm} WHERE kitchen_stats_id = ?", [(int)$item['id']]);
                    }
                    continue;
                }

                if ($currentCount !== null && $currentCount > 0 && $itemSeq > $currentCount) {
                    $db->query("UPDATE {$ks} SET was_deleted = 1 WHERE id = ?", [$item['id']]);
                    if (!empty($item['tg_message_id'])) {
                        $bot->deleteMessage((int)$item['tg_message_id']);
                        $db->query("UPDATE {$ks} SET tg_message_id = NULL WHERE id = ?", [$item['id']]);
                        $db->query("DELETE FROM {$tgm} WHERE kitchen_stats_id = ?", [(int)$item['id']]);
                    }
                    continue;
                }

                sort($readyTimes);
                $instanceReadyTime = $readyTimes[$itemSeq - 1] ?? null;
                if ($instanceReadyTime !== null) {
                    $db->query("UPDATE {$ks} SET ready_pressed_at = ? WHERE id = ?", [$instanceReadyTime, $item['id']]);
                    continue;
                }
                if ((int)($item['was_deleted'] ?? 0) === 1 && $sentCount !== null && $sentCount > 0) {
                    $db->query("UPDATE {$ks} SET was_deleted = 0 WHERE id = ?", [$item['id']]);
                    $item['was_deleted'] = 0;
                }
                if ($sentCount === null && !empty($item['ticket_sent_at'])) {
                    $sentCount = 1;
                }
                if ($sentCount !== null && ($sentCount <= 0 || $itemSeq > $sentCount)) {
                    $db->query("UPDATE {$ks} SET was_deleted = 1 WHERE id = ?", [$item['id']]);
                    continue;
                }

                $waiterName = $resolveWaiterName($api, (int)$item['transaction_id'], $tx, $employeesById);
                if ($apiStatus === 1 && $apiReason === null) {
                    $canSendAlert = true;
                    if ($waiterName !== '') {
                        $db->query("UPDATE {$ks} SET waiter_name = ? WHERE transaction_id = ?", [$waiterName, $item['transaction_id']]);
                        $item['waiter_name'] = $waiterName; // Обновляем локально для текущего сообщения
                    }
                } else {
                    if ($apiStatus <= 1) {
                        $apiStatus = 2;
                    }
                    $db->query("UPDATE {$ks} SET status = ?, pay_type = ?, close_reason = ?, tg_message_id = NULL WHERE transaction_id = ?", [$apiStatus, $apiPayType, $apiReason, $item['transaction_id']]);
                    $db->query("DELETE FROM {$tgm} WHERE transaction_date = ? AND transaction_id = ?", [$today, (int)$item['transaction_id']]);
                    continue;
                }
            } catch (\Exception $e) {
                error_log("API Check failed for delayed TX {$item['transaction_id']}: " . $e->getMessage());
                continue;
            }

            if (!$canSendAlert) {
                continue;
            }

            $sentTime = strtotime((string)$item['ticket_sent_at']);
            if ($sentTime === false) {
                continue;
            }

            $now = time();
            $processSec = max(0, $now - $sentTime);
            $processLabel = floor($processSec / 60) . ':' . str_pad((string)($processSec % 60), 2, '0', STR_PAD_LEFT);
            $startLabel = date('H:i:s', $sentTime);

            $receipt = trim((string)($item['receipt_number'] ?? ''));
            $receiptLabel = $receipt !== '' ? $receipt : (string)((int)$item['transaction_id']);
            $waiter = trim((string)($item['waiter_name'] ?? ''));
            if ($waiter === '') $waiter = '—';

            $message = "<b>Открытых чеков {$openChecksDisplay} Лимит {$waitLimit} мин</b>\n";
            $message .= "Чек <b>" . htmlspecialchars($receiptLabel, ENT_QUOTES) . "</b>\n";
            $message .= "Официант <b>" . htmlspecialchars($waiter, ENT_QUOTES) . "</b>\n";
            $message .= "Блюдо <b>" . htmlspecialchars((string)$item['dish_name'], ENT_QUOTES) . "</b>\n";
            $message .= "Старт <b>{$startLabel}</b>\n";
            $message .= "Процесс <b>{$processLabel}</b>\n";

            $ackButton = [[[
                'text' => 'ПРИНЯТО',
                'callback_data' => 'ack_alert:' . (int)$item['id']
            ]]];

            $textHash = sha1($message);
            $existing = $db->query("SELECT message_id, last_text_hash FROM {$tgm} WHERE kitchen_stats_id = ? LIMIT 1", [(int)$item['id']])->fetch();
            $existingMsgId = $existing ? (int)($existing['message_id'] ?? 0) : 0;
            $existingHash = $existing ? (string)($existing['last_text_hash'] ?? '') : '';
            $nowDt = date('Y-m-d H:i:s');

            $currentMsgId = !empty($item['tg_message_id']) ? (int)$item['tg_message_id'] : 0;
            if ($currentMsgId > 0 && $existingMsgId > 0 && $existingMsgId !== $currentMsgId) {
                $existingMsgId = $currentMsgId;
            }

            $edited = false;
            if ($existingMsgId > 0) {
                if ($existingHash === $textHash) {
                    $db->query("UPDATE {$tgm} SET last_seen_at = ? WHERE kitchen_stats_id = ?", [$nowDt, (int)$item['id']]);
                    $edited = true;
                } else {
                    $edited = $bot->editMessageText($existingMsgId, $message, $ackButton);
                    if ($edited) {
                        $db->query(
                            "UPDATE {$tgm} SET last_text_hash = ?, last_seen_at = ?, last_edited_at = ? WHERE kitchen_stats_id = ?",
                            [$textHash, $nowDt, $nowDt, (int)$item['id']]
                        );
                    }
                }
            }

            if (!$edited) {
                if ($existingMsgId > 0) {
                    $bot->deleteMessage($existingMsgId);
                }
                $newMessageId = $bot->sendMessageGetIdWithKeyboard($message, $ackButton, $tgThreadId);
                if ($newMessageId) {
                    $db->query(
                        "UPDATE {$ks}
                         SET tg_message_id = ?,
                             tg_acknowledged = 0,
                             tg_acknowledged_at = NULL,
                             tg_acknowledged_by = NULL
                         WHERE id = ?",
                        [$newMessageId, $item['id']]
                    );
                    $db->query(
                        "INSERT INTO {$tgm} (kitchen_stats_id, transaction_date, transaction_id, dish_id, item_seq, message_id, last_text_hash, last_edited_at, last_seen_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE message_id = VALUES(message_id), last_text_hash = VALUES(last_text_hash), last_edited_at = VALUES(last_edited_at), last_seen_at = VALUES(last_seen_at), updated_at = CURRENT_TIMESTAMP",
                        [
                            (int)$item['id'],
                            $today,
                            (int)$item['transaction_id'],
                            (int)$item['dish_id'],
                            (int)($item['item_seq'] ?? 1),
                            (int)$newMessageId,
                            $textHash,
                            $nowDt,
                            $nowDt
                        ]
                    );
                }
            }
        }
    }
    };

    $runs = 6;
    for ($cycle = 0; $cycle < $runs; $cycle++) {
        $process($cycle);
        if ($cycle < $runs - 1) {
            sleep(10);
        }
    }

} catch (\Exception $e) {
    echo "[" . date('Y-m-d H:i:s') . "] ERROR: " . $e->getMessage() . "\n";
}
