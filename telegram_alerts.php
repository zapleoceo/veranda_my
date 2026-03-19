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

$dbHost = $_ENV['DB_HOST'] ?? 'localhost';
$dbName = $_ENV['DB_NAME'] ?? 'veranda_my';
$dbUser = $_ENV['DB_USER'] ?? 'veranda_my';
$dbPass = $_ENV['DB_PASS'] ?? '';
$token = $_ENV['POSTER_API_TOKEN'] ?? '';
$tgToken = $_ENV['TELEGRAM_BOT_TOKEN'] ?? '';
$tgChatId = $_ENV['TELEGRAM_CHAT_ID'] ?? '';
$tgThreadId = isset($_ENV['TELEGRAM_THREAD_ID']) && $_ENV['TELEGRAM_THREAD_ID'] !== '' ? (int)$_ENV['TELEGRAM_THREAD_ID'] : null;

if (empty($tgToken)) {
    die("Error: TELEGRAM_BOT_TOKEN is not set in .env\n");
}
if (empty($tgChatId)) {
    die("Error: TELEGRAM_CHAT_ID is not set in .env\n");
}

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
    $db = new \App\Classes\Database($dbHost, $dbName, $dbUser, $dbPass);
    $api = new \App\Classes\PosterAPI($token);
    $bot = new \App\Classes\TelegramBot($tgToken, $tgChatId);
    $columnExists = function (\App\Classes\Database $db, string $dbName, string $table, string $column): bool {
        $row = $db->query(
            "SELECT COUNT(*) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?",
            [$dbName, $table, $column]
        )->fetch();
        return (int)($row['c'] ?? 0) > 0;
    };
    if (!$columnExists($db, $dbName, 'kitchen_stats', 'waiter_name')) {
        $db->query("ALTER TABLE kitchen_stats ADD COLUMN waiter_name VARCHAR(255) NULL AFTER table_number");
    }
    if (!$columnExists($db, $dbName, 'kitchen_stats', 'tg_acknowledged')) {
        $db->query("ALTER TABLE kitchen_stats ADD COLUMN tg_acknowledged TINYINT(1) NOT NULL DEFAULT 0 AFTER tg_message_id");
    }
    if (!$columnExists($db, $dbName, 'kitchen_stats', 'tg_acknowledged_at')) {
        $db->query("ALTER TABLE kitchen_stats ADD COLUMN tg_acknowledged_at DATETIME NULL AFTER tg_acknowledged");
    }
    if (!$columnExists($db, $dbName, 'kitchen_stats', 'tg_acknowledged_by')) {
        $db->query("ALTER TABLE kitchen_stats ADD COLUMN tg_acknowledged_by VARCHAR(255) NULL AFTER tg_acknowledged_at");
    }
    if (!$columnExists($db, $dbName, 'kitchen_stats', 'ready_chass_at')) {
        $db->query("ALTER TABLE kitchen_stats ADD COLUMN ready_chass_at DATETIME NULL AFTER ready_pressed_at");
    }
    if (!$columnExists($db, $dbName, 'kitchen_stats', 'prob_close_at')) {
        $db->query("ALTER TABLE kitchen_stats ADD COLUMN prob_close_at DATETIME NULL AFTER ready_chass_at");
    }

    $db->query("CREATE TABLE IF NOT EXISTS system_meta (
        meta_key VARCHAR(100) PRIMARY KEY,
        meta_value VARCHAR(255) NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $getMeta = function (string $key) use ($db): string {
        $row = $db->query("SELECT meta_value FROM system_meta WHERE meta_key = ? LIMIT 1", [$key])->fetch();
        return $row ? (string)$row['meta_value'] : '';
    };

    $setMeta = function (string $key, string $value) use ($db): void {
        $db->query(
            "INSERT INTO system_meta (meta_key, meta_value) VALUES (?, ?)
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

    $lastSync = $getMeta('chefassistant_runtime_sync_at');
    $shouldSync = true;
    if ($lastSync !== '') {
        $ts = strtotime($lastSync);
        if ($ts !== false && $ts > time() - 120) {
            $shouldSync = false;
        }
    }

    if ($shouldSync) {
        $codemealAuth = $getMeta('codemeal_auth');
        $codemealClient = $getMeta('codemeal_client_number');
        $codemealLocale = $getMeta('codemeal_locale');
        if ($codemealLocale === '') $codemealLocale = 'en';
        $codemealTz = $getMeta('codemeal_timezone');
        if ($codemealTz === '') $codemealTz = 'Asia/Ho_Chi_Minh';

        if ($codemealAuth !== '' && $codemealClient !== '') {
            try {
                $from = date('Y/m/d 00:00:00', strtotime('-1 day'));
                $apiCh = new \App\Classes\CodemealAPI('https://codemeal.pro', $codemealAuth, $codemealClient, $codemealLocale, $codemealTz);
                $chass = new \App\Classes\ChefAssistantSync($db, $apiCh, $codemealTz);
                $res = $chass->syncOrders($from, null, 10);
                if (!empty($res['ok'])) {
                    $updated = $chass->updateKitchenStatsReadyChAss(date('Y-m-d', strtotime('-1 day')), date('Y-m-d'), $res['order_ids'] ?? []);
                    $setMeta('chefassistant_runtime_sync_at', date('Y-m-d H:i:s'));
                    $setMeta('chefassistant_runtime_sync_info', 'saved=' . (int)($res['saved'] ?? 0) . ', pages=' . (int)($res['pages'] ?? 0) . ', updated=' . $updated);
                } else {
                    $setMeta('chefassistant_runtime_sync_error', (string)($res['error'] ?? 'ChefAssistant error'));
                    if (!empty($res['auth_error'])) {
                        $maybeSendAuthAlert();
                    }
                }
            } catch (\Exception $e) {
                $setMeta('chefassistant_runtime_sync_error', $e->getMessage());
                $m = mb_strtolower($e->getMessage());
                if (str_contains($m, '401') || str_contains($m, '403') || str_contains($m, 'unauthor') || str_contains($m, 'forbidden')) {
                    $maybeSendAuthAlert();
                }
            }
        }
    }

    // 1. Сначала находим все записи, у которых есть tg_message_id (т.е. уведомление было отправлено)
    $today = date('Y-m-d');
    $computeProbCloseAt = function (string $date) use ($db): void {
        $readyRows = $db->query(
            "SELECT receipt_number, station, ready_pressed_at, ready_chass_at
             FROM kitchen_stats
             WHERE transaction_date = ?
               AND receipt_number REGEXP '^[0-9]+$'
               AND COALESCE(was_deleted, 0) = 0
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
            "SELECT id, receipt_number, station, prob_close_at
             FROM kitchen_stats
             WHERE transaction_date = ?
               AND status = 1
               AND receipt_number REGEXP '^[0-9]+$'
               AND COALESCE(was_deleted, 0) = 0
               AND ticket_sent_at IS NOT NULL
               AND ready_pressed_at IS NULL
               AND ready_chass_at IS NULL",
            [$date]
        )->fetchAll();

        $upd = $db->getPdo()->prepare("UPDATE kitchen_stats SET prob_close_at = ? WHERE id = ?");
        foreach ($targets as $t) {
            $id = (int)($t['id'] ?? 0);
            $receipt = (int)($t['receipt_number'] ?? 0);
            $station = (string)($t['station'] ?? '');
            if ($id <= 0 || $receipt <= 0 || $station === '') continue;

            $candidate = null;
            for ($d = 1; $d <= 3; $d++) {
                $next = $receipt + $d;
                if (isset($byReceiptStation[$next][$station])) {
                    $candidate = $byReceiptStation[$next][$station];
                    break;
                }
            }

            $current = $t['prob_close_at'] ?? null;
            if (($current === null || $current === '') && $candidate === null) continue;
            if ($candidate !== null && (string)$candidate === (string)$current) continue;

            $upd->execute([$candidate, $id]);
        }
    };
    $computeProbCloseAt($today);
    $cleanupFrom = date('Y-m-d', strtotime('-7 days'));
    $activeAlerts = $db->query(
        "SELECT * FROM kitchen_stats
         WHERE tg_message_id IS NOT NULL
           AND transaction_date >= ?",
        [$cleanupFrom]
    )->fetchAll();

    foreach ($activeAlerts as $item) {
        $shouldDelete = false;
        
        // Проверяем, готово ли уже блюдо или закрыт ли чек
        if ((int)($item['tg_acknowledged'] ?? 0) === 1 || (int)($item['was_deleted'] ?? 0) === 1 || $item['ready_pressed_at'] !== null || !empty($item['ready_chass_at']) || !empty($item['prob_close_at']) || (int)$item['status'] > 1) {
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
                    $db->query("UPDATE kitchen_stats SET was_deleted = 1 WHERE id = ?", [$item['id']]);
                } elseif ($currentCount <= 0) {
                    $shouldDelete = true;
                    $db->query("UPDATE kitchen_stats SET was_deleted = 1 WHERE id = ?", [$item['id']]);
                } elseif ($readyTime !== null) {
                    $shouldDelete = true;
                    $db->query("UPDATE kitchen_stats SET ready_pressed_at = ? WHERE id = ?", [$readyTime, $item['id']]);
                } elseif ($apiStatus > 1 || $apiReason !== null) {
                    $shouldDelete = true;
                    if ($apiStatus <= 1) {
                        $apiStatus = 2;
                    }
                    $db->query("UPDATE kitchen_stats SET status = ?, pay_type = ?, close_reason = ? WHERE transaction_id = ?", [$apiStatus, $apiPayType, $apiReason, $item['transaction_id']]);
                }
            } catch (\Exception $e) {
                error_log("API Check failed for TX {$item['transaction_id']}: " . $e->getMessage());
            }
        }

        // Если блюдо перестало быть "зависшим", удаляем старое сообщение
        if ($shouldDelete) {
            $deleted = $bot->deleteMessage((int)$item['tg_message_id']);
            if ($deleted) {
                $db->query("UPDATE kitchen_stats SET tg_message_id = NULL WHERE id = ?", [$item['id']]);
                echo "[" . date('Y-m-d H:i:s') . "] Removed outdated alert for: {$item['dish_name']} (Table: {$item['table_number']})\n";
            } else {
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
        $row = $db->query("SELECT meta_value FROM system_meta WHERE meta_key = ? LIMIT 1", [$key])->fetch();
    $settings[$key] = $row ? $row['meta_value'] : $default;
    if (is_numeric($default)) {
        $settings[$key] = (int)$settings[$key];
    }
    }

    // Считаем количество открытых чеков
    $partnersCount = 0;
    $otherCount = 0;
    if (!empty($settings['exclude_partners_from_load'])) {
        $partnersCountRow = $db->query("SELECT COUNT(DISTINCT transaction_id) as c FROM kitchen_stats WHERE status = 1 AND transaction_date = ? AND table_number = 'Partners'", [$today])->fetch();
        $partnersCount = (int)($partnersCountRow['c'] ?? 0);
        $otherCountRow = $db->query("SELECT COUNT(DISTINCT transaction_id) as c FROM kitchen_stats WHERE status = 1 AND transaction_date = ? AND table_number != 'Partners'", [$today])->fetch();
        $otherCount = (int)($otherCountRow['c'] ?? 0);
        $loadCalculationCount = $otherCount;
        $openChecksDisplay = "{$otherCount}+{$partnersCount}";
    } else {
        $openCountRow = $db->query("SELECT COUNT(DISTINCT transaction_id) as c FROM kitchen_stats WHERE status = 1 AND transaction_date = ?", [$today])->fetch();
        $openCount = (int)($openCountRow['c'] ?? 0);
        $loadCalculationCount = $openCount;
        $openChecksDisplay = $openCount;
    }

    // Определяем тайминг на основе нагрузки
    $waitLimit = ($loadCalculationCount < $settings['alert_load_threshold']) 
        ? $settings['alert_timing_low_load'] 
        : $settings['alert_timing_high_load'];

    echo "[" . date('Y-m-d H:i:s') . "] Open checks: $openChecksDisplay. Using wait limit: $waitLimit min.\n";

    // 3. Теперь ищем актуальные задержки
    $cutoffTime = date('Y-m-d H:i:s', strtotime("-$waitLimit minutes"));
    $ackSnooze = (int)($settings['alert_ack_snooze_minutes'] ?? 0);
    $ackCutoffTime = $ackSnooze > 0 ? date('Y-m-d H:i:s', strtotime("-$ackSnooze minutes")) : null;
    $query = "SELECT * FROM kitchen_stats 
              WHERE ready_pressed_at IS NULL 
              AND ready_chass_at IS NULL
              AND prob_close_at IS NULL
              AND ticket_sent_at IS NOT NULL 
              AND transaction_date = ?
              AND status = 1
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
                
                // Проверяем историю на предмет готовности
                $history = $getTxHistory($api, (int)$item['transaction_id'], $historyByTxId);
                $dishId = (int)$item['dish_id'];
                $readyTime = null;
                $sentCount = null;
                $isDeletedInTx = $isDishDeletedFromHistory($history, $dishId);
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
                        $readyTime = date('Y-m-d H:i:s', ($event['time'] ?? 0) / 1000);
                    }
                }

                if ($isDeletedInTx) {
                    $db->query("UPDATE kitchen_stats SET was_deleted = 1 WHERE id = ?", [$item['id']]);
                    if (!empty($item['tg_message_id'])) {
                        $bot->deleteMessage((int)$item['tg_message_id']);
                        $db->query("UPDATE kitchen_stats SET tg_message_id = NULL WHERE id = ?", [$item['id']]);
                    }
                    continue;
                }
                if ($readyTime !== null) {
                    $db->query("UPDATE kitchen_stats SET ready_pressed_at = ? WHERE id = ?", [$readyTime, $item['id']]);
                    continue; // Блюдо готово, пропускаем алерт
                }
                if ((int)($item['was_deleted'] ?? 0) === 1 && $sentCount !== null && $sentCount > 0) {
                    $db->query("UPDATE kitchen_stats SET was_deleted = 0 WHERE id = ?", [$item['id']]);
                    $item['was_deleted'] = 0;
                }
                if ($sentCount === null && !empty($item['ticket_sent_at'])) {
                    $sentCount = 1;
                }
                if ($sentCount !== null && $sentCount <= 0) {
                    $db->query("UPDATE kitchen_stats SET was_deleted = 1 WHERE id = ?", [$item['id']]);
                    continue;
                }

                $waiterName = $resolveWaiterName($api, (int)$item['transaction_id'], $tx, $employeesById);
                if ($apiStatus === 1 && $apiReason === null) {
                    $canSendAlert = true;
                    if ($waiterName !== '') {
                        $db->query("UPDATE kitchen_stats SET waiter_name = ? WHERE transaction_id = ?", [$waiterName, $item['transaction_id']]);
                        $item['waiter_name'] = $waiterName; // Обновляем локально для текущего сообщения
                    }
                } else {
                    if ($apiStatus <= 1) {
                        $apiStatus = 2;
                    }
                    $db->query("UPDATE kitchen_stats SET status = ?, pay_type = ?, close_reason = ?, tg_message_id = NULL WHERE transaction_id = ?", [$apiStatus, $apiPayType, $apiReason, $item['transaction_id']]);
                    continue;
                }
            } catch (\Exception $e) {
                error_log("API Check failed for delayed TX {$item['transaction_id']}: " . $e->getMessage());
                continue;
            }

            if (!$canSendAlert) {
                continue;
            }

            // Если уведомление уже есть, удаляем старое, чтобы прислать новое с актуальным временем
            if ($item['tg_message_id']) {
                $bot->deleteMessage((int)$item['tg_message_id']);
            }

            $sentTime = strtotime($item['ticket_sent_at']);
            $waitMinutes = round((time() - $sentTime) / 60);
            $table = $item['table_number'] ?: ($item['receipt_number'] ?: "Чек #" . $item['transaction_id']);
            $waiter = trim((string)($item['waiter_name'] ?? '')) ?: 'Не указан';
            $txId = (int)$item['transaction_id'];

            $message = "⚠️ <b>Открытых чеков {$openChecksDisplay} Лимит ожидания {$waitLimit} мин</b>\n\n";
            $message .= "📍 <b>Стол:</b> {$table}\n";
            $message .= "🧾 <b>Чек:</b> {$txId}\n";
            $message .= "👤 <b>Официант:</b> {$waiter}\n";
            $message .= "🍽 <b>Блюдо:</b> {$item['dish_name']}\n";
            $message .= "🏢 <b>Станция:</b> {$item['station']}\n";
            $message .= "🕒 <b>Время заказа:</b> " . date('H:i', $sentTime) . "\n";
            $message .= "⏳ <b>Ожидает уже:</b> {$waitMinutes} мин\n";
            $ackButton = [[
                [
                    'text' => '✅ Принято',
                    'callback_data' => 'ack_alert:' . (int)$item['id']
                ]
            ]];
            $newMessageId = $bot->sendMessageGetIdWithKeyboard($message, $ackButton, $tgThreadId);
            
            if ($newMessageId) {
                $db->query(
                    "UPDATE kitchen_stats
                     SET tg_message_id = ?,
                         tg_acknowledged = 0,
                         tg_acknowledged_at = NULL,
                         tg_acknowledged_by = NULL
                     WHERE id = ?",
                    [$newMessageId, $item['id']]
                );
                echo "[" . date('Y-m-d H:i:s') . "] Updated alert for: {$item['dish_name']} (Wait: {$waitMinutes} min)\n";
            }
        }
    }

} catch (\Exception $e) {
    echo "[" . date('Y-m-d H:i:s') . "] ERROR: " . $e->getMessage() . "\n";
}
