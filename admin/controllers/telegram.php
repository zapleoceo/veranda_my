<?php
$usersTable = $db->t('users');
$metaTable = $db->t('system_meta');
// Settings logic
$settingKeys = [
    'alert_timing_low_load' => 20,
    'alert_load_threshold' => 25,
    'alert_timing_high_load' => 30,
    'alert_ack_snooze_minutes' => 15,
    'exclude_partners_from_load' => 0
];

if (isset($_POST['save_settings']) || array_key_exists('exclude_partners_from_load', $_POST)) {
    foreach ($settingKeys as $key => $default) {
        $val = $_POST[$key] ?? $default;
        if (is_numeric($default)) {
            $val = (int)$val;
        }
        $db->query("INSERT INTO {$metaTable} (meta_key, meta_value) VALUES (?, ?) 
                    ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)", [$key, $val]);
    }
    $message = "Настройки успешно сохранены.";
}

$settings = [];
foreach ($settingKeys as $key => $default) {
    $row = $db->query("SELECT meta_value FROM {$metaTable} WHERE meta_key = ? LIMIT 1", [$key])->fetch();
    $settings[$key] = $row ? $row['meta_value'] : $default;
    if (is_numeric($default)) {
        $settings[$key] = (int)$settings[$key];
    }
}



if (($_GET['ajax'] ?? '') === 'telegram_test') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $tgToken = trim((string)($_ENV['TELEGRAM_BOT_TOKEN'] ?? $_ENV['TG_BOT_TOKEN'] ?? ''));
        $tgChatId = trim((string)($_ENV['TELEGRAM_CHAT_ID'] ?? $_ENV['TG_CHAT_ID'] ?? ''));
        $tgThreadId = trim((string)($_ENV['TELEGRAM_THREAD_ID'] ?? $_ENV['TG_THREAD_ID'] ?? ''));
        $tgThreadNum = $tgThreadId !== '' ? (int)$tgThreadId : 0;
        if ($tgToken === '' || $tgChatId === '') {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Missing TELEGRAM_* in .env'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $text = (string)($_POST['text'] ?? $_GET['text'] ?? 'Тест: статус проверки');
        $bot = new \App\Classes\TelegramBot($tgToken, $tgChatId);
        $msgId = $bot->sendMessageGetId($text, $tgThreadNum > 0 ? $tgThreadNum : null);
        if ($msgId) {
            echo json_encode(['ok' => true, 'message_id' => (int)$msgId], JSON_UNESCAPED_UNICODE);
        } else {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Telegram send failed'], JSON_UNESCAPED_UNICODE);
        }
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if (($_GET['ajax'] ?? '') === 'telegram_status_ensure') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $tgToken = trim((string)($_ENV['TELEGRAM_BOT_TOKEN'] ?? $_ENV['TG_BOT_TOKEN'] ?? ''));
        $tgChatId = trim((string)($_ENV['TELEGRAM_CHAT_ID'] ?? $_ENV['TG_CHAT_ID'] ?? ''));
        $tgThreadId = trim((string)($_ENV['TELEGRAM_THREAD_ID'] ?? $_ENV['TG_THREAD_ID'] ?? ''));
        $tgThreadNum = $tgThreadId !== '' ? (int)$tgThreadId : 0;
        if ($tgToken === '' || $tgChatId === '') {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Missing TELEGRAM_* in .env'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $today = date('Y-m-d');
        $ks = $db->t('kitchen_stats');
        $metaTable = $db->t('system_meta');
        $metaRepo = new \App\Classes\MetaRepository($db);
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
        $settingKeys = [
            'alert_timing_low_load' => 20,
            'alert_load_threshold' => 25,
            'alert_timing_high_load' => 30,
            'exclude_partners_from_load' => 0
        ];
        $settingValues = $metaRepo->getMany(array_keys($settingKeys));
        $settings = [];
        foreach ($settingKeys as $key => $default) {
            $val = array_key_exists($key, $settingValues) ? $settingValues[$key] : $default;
            $settings[$key] = is_numeric($default) ? (int)$val : (string)$val;
        }
        $partnersCount = 0;
        $otherCount = 0;
        $openChecksDisplay = '0';
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
        $excludeSql = " AND COALESCE(was_deleted, 0) = 0 AND COALESCE(exclude_from_dashboard, 0) = 0
                        AND NOT (COALESCE(dish_category_id, 0) = 47 OR COALESCE(dish_sub_category_id, 0) = 47) ";
        $queueBar = 0; $queueKitchen = 0;
        $overdueBar = 0; $overdueKitchen = 0;
        $qRows = $db->query(
            "SELECT COALESCE(station, 1) as st, COUNT(*) as cnt
             FROM {$ks}
             WHERE ready_pressed_at IS NULL
               AND ticket_sent_at IS NOT NULL
               AND transaction_date = ?
               AND status = 1 {$excludeSql}
             GROUP BY COALESCE(station, 1)",
            [$today]
        )->fetchAll();
        foreach ($qRows as $r) {
            $st = (int)($r['st'] ?? 1);
            $cnt = (int)($r['cnt'] ?? 0);
            if ($st === 2) $queueBar += $cnt; else $queueKitchen += $cnt;
        }
        $oRows = $db->query(
            "SELECT COALESCE(station, 1) as st, COUNT(*) as cnt
             FROM {$ks}
             WHERE ready_pressed_at IS NULL
               AND ticket_sent_at IS NOT NULL
               AND transaction_date = ?
               AND status = 1 {$excludeSql}
               AND ticket_sent_at < ?
             GROUP BY COALESCE(station, 1)",
            [$today, $cutoffTime]
        )->fetchAll();
        foreach ($oRows as $r) {
            $st = (int)($r['st'] ?? 1);
            $cnt = (int)($r['cnt'] ?? 0);
            if ($st === 2) $overdueBar += $cnt; else $overdueKitchen += $cnt;
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
        $bot = new \App\Classes\TelegramBot($tgToken, $tgChatId);
        $messageId = null;
        if ($prevStatusId > 0) {
            $ok = $bot->editMessageText($prevStatusId, $statusText, null);
            if ($ok) {
                $setMeta('telegram_status_msg_hash', $statusHash);
                $messageId = $prevStatusId;
            } else {
                $newId = $bot->sendMessageGetId($statusText, $tgThreadNum > 0 ? $tgThreadNum : null);
                if ($newId) {
                    $setMeta('telegram_status_msg_id', (string)$newId);
                    $setMeta('telegram_status_msg_hash', $statusHash);
                    $messageId = (int)$newId;
                } else {
                    $setMeta('telegram_status_msg_id', '0');
                    $setMeta('telegram_status_msg_hash', '');
                }
            }
        } else {
            $newId = $bot->sendMessageGetId($statusText, $tgThreadNum > 0 ? $tgThreadNum : null);
            if ($newId) {
                $setMeta('telegram_status_msg_id', (string)$newId);
                $setMeta('telegram_status_msg_hash', $statusHash);
                $messageId = (int)$newId;
            } else {
                $setMeta('telegram_status_msg_id', '0');
                $setMeta('telegram_status_msg_hash', '');
            }
        }
        echo json_encode(['ok' => $messageId !== null, 'message_id' => $messageId, 'text' => $statusText], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

