<?php
$root = dirname(__DIR__);
require_once $root . '/src/classes/Database.php';

if (file_exists($root . '/.env')) {
    $lines = file($root . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) {
            continue;
        }
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

$tgToken = $_ENV['TELEGRAM_BOT_TOKEN'] ?? '';
$dbHost = $_ENV['DB_HOST'] ?? 'localhost';
$dbName = $_ENV['DB_NAME'] ?? 'veranda_my';
$dbUser = $_ENV['DB_USER'] ?? 'veranda_my';
$dbPass = $_ENV['DB_PASS'] ?? '';
$tableSuffix = (string)($_ENV['DB_TABLE_SUFFIX'] ?? '');

$tgTokenMissing = empty($tgToken);
$raw = file_get_contents('php://input');
$update = json_decode($raw, true);

if ($tgTokenMissing) {
    error_log('telegram_webhook: TELEGRAM_BOT_TOKEN is not set');
    echo 'ok';
    exit;
}

$apiBase = "https://api.telegram.org/bot{$tgToken}";
$postJson = function (string $method, array $payload) use ($apiBase): ?array {
    $ch = curl_init("{$apiBase}/{$method}");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $resp = curl_exec($ch);
    curl_close($ch);
    if ($resp === false || $resp === null || $resp === '') return null;
    $data = json_decode($resp, true);
    return is_array($data) ? $data : null;
};

$logLine = function (string $msg): void {
    $root = dirname(__DIR__);
    $path = $root . '/telegram.log';
    try {
        @file_put_contents($path, '[' . date('Y-m-d H:i:s') . '] WEBHOOK ' . $msg . "\n", FILE_APPEND);
    } catch (\Throwable $e) {
    }
};

$waEvent = strtolower(trim((string)($_GET['wa_event'] ?? '')));
if ($waEvent !== '') {
    header('Content-Type: application/json; charset=utf-8');

    $secret = trim((string)($_ENV['WA_NODE_SECRET'] ?? ($_ENV['WA_BRIDGE_SECRET'] ?? '')));
    $provided = trim((string)($_SERVER['HTTP_X_WA_BRIDGE'] ?? ''));
    if ($provided === '') $provided = trim((string)($_GET['secret'] ?? $_POST['secret'] ?? ''));
    if ($secret === '' || $provided === '' || !hash_equals($secret, $provided)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $db = null;
    try {
        $db = new \App\Classes\Database($dbHost, $dbName, $dbUser, $dbPass, $tableSuffix);
    } catch (\Throwable $e) {
        $db = null;
    }

    $metaTable = ($db instanceof \App\Classes\Database) ? $db->t('system_meta') : '';
    $getMeta = function (string $key, string $default = '') use ($db, $metaTable): string {
        if (!($db instanceof \App\Classes\Database) || $metaTable === '') return $default;
        try {
            $row = $db->query("SELECT meta_value FROM {$metaTable} WHERE meta_key = ? LIMIT 1", [$key])->fetch();
            return is_array($row) && array_key_exists('meta_value', $row) ? (string)$row['meta_value'] : $default;
        } catch (\Throwable $e) {
            return $default;
        }
    };
    $setMeta = function (string $key, string $value) use ($db, $metaTable): void {
        if (!($db instanceof \App\Classes\Database) || $metaTable === '') return;
        try {
            $db->query(
                "INSERT INTO {$metaTable} (meta_key, meta_value) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value), updated_at = CURRENT_TIMESTAMP",
                [$key, $value]
            );
        } catch (\Throwable $e) {
        }
    };

    $defaultChatId = trim((string)($_ENV['TELEGRAM_CHAT_ID'] ?? $_ENV['TG_CHAT_ID'] ?? ''));
    $defaultThreadId = (int)trim((string)($_ENV['TELEGRAM_THREAD_ID'] ?? $_ENV['TG_THREAD_ID'] ?? '0'));
    $adminChatId = trim((string)($_ENV['WA_ADMIN_TG_CHAT_ID'] ?? $_ENV['TG_ADMIN_ID'] ?? '169510539'));

    if ($waEvent === 'qr') {
        $chatId = trim((string)($_POST['chat_id'] ?? $_GET['chat_id'] ?? $defaultChatId));
        $threadId = (int)trim((string)($_POST['thread_id'] ?? $_GET['thread_id'] ?? (string)$defaultThreadId));
        $incomingMsgId = (int)trim((string)($_POST['message_id'] ?? $_GET['message_id'] ?? '0'));
        $text = trim((string)($_POST['text'] ?? $_GET['text'] ?? ''));
        $photoUrl = trim((string)($_POST['photo_url'] ?? $_GET['photo_url'] ?? ''));
        $caption = trim((string)($_POST['caption'] ?? $_GET['caption'] ?? ''));

        $sentChatId = $chatId;
        $sentMsgId = $incomingMsgId;

        $remoteIp = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
        $ipSuffix = $remoteIp !== '' ? "\nIP: {$remoteIp}" : '';

        if ($sentMsgId <= 0 && $chatId !== '' && ($text !== '' || $photoUrl !== '')) {
            if ($photoUrl !== '') {
                $payload = [
                    'chat_id' => $chatId,
                    'photo' => $photoUrl,
                ];
                if ($caption !== '') $payload['caption'] = $caption . $ipSuffix;
                elseif ($ipSuffix !== '') $payload['caption'] = ltrim($ipSuffix);
                if ($threadId > 0) $payload['message_thread_id'] = $threadId;
                $resp = $postJson('sendPhoto', $payload);
                if (is_array($resp) && !empty($resp['ok']) && is_array($resp['result'] ?? null)) {
                    $sentChatId = $chatId;
                    $sentMsgId = (int)($resp['result']['message_id'] ?? 0);
                }
            } else {
                $payload = [
                    'chat_id' => $chatId,
                    'text' => $text . $ipSuffix,
                ];
                if ($threadId > 0) $payload['message_thread_id'] = $threadId;
                $resp = $postJson('sendMessage', $payload);
                if (is_array($resp) && !empty($resp['ok']) && is_array($resp['result'] ?? null)) {
                    $sentChatId = $chatId;
                    $sentMsgId = (int)($resp['result']['message_id'] ?? 0);
                }
            }
        }

        if ($sentChatId !== '' && $sentMsgId > 0) {
            $setMeta('wa_qr_tg_chat_id', $sentChatId);
            $setMeta('wa_qr_tg_message_id', (string)$sentMsgId);
            $setMeta('wa_qr_tg_saved_at', date('Y-m-d H:i:s'));
        }

        echo json_encode(['ok' => $sentMsgId > 0, 'chat_id' => $sentChatId, 'message_id' => $sentMsgId], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($waEvent === 'active') {
        $qrChatId = trim($getMeta('wa_qr_tg_chat_id', ''));
        $qrMsgId = (int)trim($getMeta('wa_qr_tg_message_id', '0'));
        if ($qrChatId === '') $qrChatId = $defaultChatId;

        $deleted = false;
        if ($qrChatId !== '' && $qrMsgId > 0) {
            $resp = $postJson('deleteMessage', [
                'chat_id' => $qrChatId,
                'message_id' => $qrMsgId,
            ]);
            $deleted = is_array($resp) && !empty($resp['ok']);
        }

        $setMeta('wa_qr_tg_chat_id', '');
        $setMeta('wa_qr_tg_message_id', '0');
        $setMeta('wa_qr_tg_saved_at', '');

        $sentActive = false;
        if ($adminChatId !== '') {
            $remoteIp = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
            $resp = $postJson('sendMessage', [
                'chat_id' => $adminChatId,
                'text' => 'WA: активен ✅' . ($remoteIp !== '' ? "\nIP: {$remoteIp}" : ''),
            ]);
            $sentActive = is_array($resp) && !empty($resp['ok']);
        }

        echo json_encode(['ok' => true, 'qr_deleted' => $deleted, 'wa_active_sent' => $sentActive], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Unknown wa_event'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!empty($update['message'])) {
    $msg = $update['message'];
    $chat = is_array($msg['chat'] ?? null) ? $msg['chat'] : [];
    $chatId = isset($chat['id']) ? (string)$chat['id'] : '';
    $chatType = (string)($chat['type'] ?? '');
    $text = trim((string)($msg['text'] ?? ''));
    $cmd = strtolower(preg_replace('/\s+.*/', '', $text));
    $startCode = '';
    if (preg_match('/^\/start(?:@\w+)?\s+([a-f0-9]{8,40})$/i', $text, $m)) {
        $startCode = strtolower((string)($m[1] ?? ''));
    }
    if ($cmd === '/start' && $startCode !== '' && $chatId !== '' && $chatType === 'private') {
        try {
            $db = new \App\Classes\Database($dbHost, $dbName, $dbUser, $dbPass, $tableSuffix);
            $t = $db->t('table_reservation_tg_states');
            try {
                $pdo = $db->getPdo();
                $pdo->exec("CREATE TABLE IF NOT EXISTS {$t} (
                    code VARCHAR(40) PRIMARY KEY,
                    payload_json TEXT NOT NULL,
                    created_at DATETIME NOT NULL,
                    expires_at DATETIME NOT NULL,
                    used_at DATETIME NULL,
                    return_sent_at DATETIME NULL,
                    return_msg_id BIGINT NULL,
                    reminder_sent_at DATETIME NULL,
                    reminder_msg_id BIGINT NULL,
                    tg_user_id BIGINT NULL,
                    tg_username VARCHAR(64) NULL,
                    tg_name VARCHAR(128) NULL,
                    KEY idx_expires_at (expires_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
                try { $pdo->exec("ALTER TABLE {$t} ADD COLUMN tg_user_id BIGINT NULL"); } catch (\Throwable $e) {}
                try { $pdo->exec("ALTER TABLE {$t} ADD COLUMN tg_username VARCHAR(64) NULL"); } catch (\Throwable $e) {}
                try { $pdo->exec("ALTER TABLE {$t} ADD COLUMN tg_name VARCHAR(128) NULL"); } catch (\Throwable $e) {}
                try { $pdo->exec("ALTER TABLE {$t} ADD COLUMN return_sent_at DATETIME NULL"); } catch (\Throwable $e) {}
                try { $pdo->exec("ALTER TABLE {$t} ADD COLUMN return_msg_id BIGINT NULL"); } catch (\Throwable $e) {}
                try { $pdo->exec("ALTER TABLE {$t} ADD COLUMN reminder_sent_at DATETIME NULL"); } catch (\Throwable $e) {}
                try { $pdo->exec("ALTER TABLE {$t} ADD COLUMN reminder_msg_id BIGINT NULL"); } catch (\Throwable $e) {}
            } catch (\Throwable $e) {
            }
            $row = $db->query(
                "SELECT code, payload_json
                 FROM {$t}
                 WHERE code = ?
                   AND used_at IS NULL
                   AND expires_at > NOW()
                 LIMIT 1",
                [$startCode]
            )->fetch();
            if (is_array($row) && !empty($row['code'])) {
                $from = is_array($msg['from'] ?? null) ? $msg['from'] : [];
                $tgUserId = isset($from['id']) ? (int)$from['id'] : 0;
                $tgUsername = strtolower(trim((string)($from['username'] ?? '')));
                $tgFirst = trim((string)($from['first_name'] ?? ''));
                $tgLast = trim((string)($from['last_name'] ?? ''));
                $tgName = trim($tgFirst . ' ' . $tgLast);
                if ($tgUserId > 0 || $tgUsername !== '' || $tgName !== '') {
                    $db->query(
                        "UPDATE {$t}
                         SET tg_user_id = NULLIF(?, 0),
                             tg_username = NULLIF(?, ''),
                             tg_name = NULLIF(?, '')
                         WHERE code = ?",
                        [$tgUserId, ltrim($tgUsername, '@'), $tgName, $startCode]
                    );
                }
                $payloadJsonStr = $row['payload_json'] ?? '{}';
                $payloadData = json_decode($payloadJsonStr, true);
                $sourcePage = (is_array($payloadData) && !empty($payloadData['source_page'])) ? $payloadData['source_page'] : 'Tr2.php';

                $returnUrl = 'https://veranda.my/' . ltrim($sourcePage, '/') . '?tg_state=' . rawurlencode($startCode);
                $resp = $postJson('sendMessage', [
                    'chat_id' => $chatId,
                    'text' => "Аккаунт подтвержден.\nНажми кнопку ниже, чтобы завершить бронирование:",
                    'reply_markup' => [
                        'inline_keyboard' => [
                            [
                                [
                                    'text' => 'Завершить бронирование',
                                    'url' => $returnUrl,
                                ],
                            ],
                        ],
                    ],
                ]);
                $msgId = 0;
                if (is_array($resp) && !empty($resp['ok']) && is_array($resp['result'] ?? null)) {
                    $msgId = (int)($resp['result']['message_id'] ?? 0);
                }
                try {
                    $db->query(
                        "UPDATE {$t}
                         SET return_sent_at = ?,
                             return_msg_id = NULLIF(?, 0)
                         WHERE code = ?",
                        [date('Y-m-d H:i:s'), $msgId, $startCode]
                    );
                } catch (\Throwable $e) {}
                echo 'ok';
                exit;
            }
        } catch (\Throwable $e) {
        }
    }
    if ($cmd === '/start' || $cmd === '/menu') {
        if ($chatId !== '') {
            if ($chatType !== 'private') {
                $postJson('sendMessage', [
                    'chat_id' => $chatId,
                    'text' => 'Напиши мне в личку: @VerandamyBot',
                ]);
            } else {
                $postJson('sendMessage', [
                    'chat_id' => $chatId,
                    'text' => "Выбери действие:",
                    'reply_markup' => [
                        'inline_keyboard' => [
                            [
                                [
                                    'text' => 'Посмотреть меню',
                                    'web_app' => ['url' => 'https://veranda.my/links/menu.php'],
                                ],
                            ],
                            [
                                [
                                    'text' => 'Как добраться',
                                    'url' => 'https://maps.app.goo.gl/wM9MMAGJjxUppDgR9',
                                ],
                            ],
                        ],
                    ],
                ]);
            }
        }
    }
    echo 'ok';
    exit;
}

if (empty($update['callback_query'])) {
    echo 'ok';
    exit;
}

$callback = $update['callback_query'];
$callbackId = $callback['id'] ?? '';
$data = $callback['data'] ?? '';
$message = $callback['message'] ?? [];
$messageId = isset($message['message_id']) ? (int)$message['message_id'] : 0;
$chatId = isset($message['chat']['id']) ? (string)$message['chat']['id'] : '';
$from = $callback['from'] ?? [];
$fromUser = strtolower(trim((string)($from['username'] ?? '')));
$fromUser = ltrim($fromUser, '@');
$logLine('CALLBACK data=' . (string)$data . ' chat=' . (string)$chatId . ' msg=' . (int)$messageId . ' from=@' . (string)$fromUser);

if (!preg_match('/^(ignore_item|ignore_tx|vposter|vdecline|vrestore|vposter_fix|vposter_cancel):(\d+)$/', $data, $m)) {
    $logLine('CALLBACK_SKIP data=' . (string)$data);
    echo 'ok';
    exit;
}

$action = (string)($m[1] ?? '');
$id = (int)($m[2] ?? 0);
$from = $from;
$ackBy = trim(($from['first_name'] ?? '') . ' ' . ($from['last_name'] ?? ''));
if ($ackBy === '') {
    $ackBy = $from['username'] ?? 'unknown';
}

try {
    $db = new \App\Classes\Database($dbHost, $dbName, $dbUser, $dbPass, $tableSuffix);
    $usersTable = $db->t('users');
    $ks = $db->t('kitchen_stats');
    $resTable = $db->t('reservations');
    $ackAt = date('Y-m-d H:i:s');

    $username = strtolower(trim((string)($from['username'] ?? '')));
    $username = ltrim($username, '@');
    $isAllowed = false;
    $userPermissions = [];
    if ($username !== '') {
        $uRow = $db->query(
            "SELECT permissions_json
             FROM {$usersTable}
             WHERE telegram_username = ?
             LIMIT 1",
            [$username]
        )->fetch();
        $decoded = json_decode((string)($uRow['permissions_json'] ?? '{}'), true);
        $userPermissions = is_array($decoded) ? $decoded : [];
    }

    $isAdmin = !empty($userPermissions['admin']);
    $canIgnore = $isAdmin || !empty($userPermissions['telegram_ack']) || !empty($userPermissions['exclude_toggle']);
    $canPoster = $isAdmin || !empty($userPermissions['vposter_button']);
    $isAllowed = $isAdmin || $canIgnore || $canPoster || !empty($userPermissions['exclude_toggle']);

    if (in_array($action, ['ignore_tx', 'ignore_item'], true) && !$canIgnore) {
        $isAllowed = false;
    }
    if (in_array($action, ['vposter', 'vdecline', 'vrestore', 'vposter_fix', 'vposter_cancel'], true) && !$canPoster) {
        $isAllowed = false;
    }

    if (!$isAllowed) {
        $deny = 'DENY action=' . $action . ' id=' . $id . ' user=@' . $username;
        $logLine($deny);
        if ($callbackId !== '') {
            $postJson('answerCallbackQuery', [
                'callback_query_id' => $callbackId,
                'text' => ($username !== '' ? ('Нет доступа для @' . $username) : 'Нет доступа: у вас нет username в Telegram') . '. Попросите доступ "✅ Принято (Telegram)".',
                'show_alert' => true
            ]);
        }
        echo 'ok';
        exit;
    }

    if (in_array($action, ['vposter', 'vdecline', 'vrestore', 'vposter_fix', 'vposter_cancel', 'ignore_tx', 'ignore_item'], true) && $id > 0) {
        $actionFile = __DIR__ . '/' . $action . '.php';
        if (file_exists($actionFile)) {
            $logLine('ALLOW action=' . $action . ' id=' . $id . ' user=@' . $username);
            require_once $actionFile;
        }
    }
} catch (\Throwable $e) {
    $logLine('ERROR action=' . $action . ' id=' . $id . ' msg=' . preg_replace('/\s+/', ' ', $e->getMessage()));
    $callbackText = 'Ошибка: ' . $e->getMessage();
}

if ($callbackId !== '') {
    $postJson('answerCallbackQuery', [
        'callback_query_id' => $callbackId,
        'text' => $callbackText ?? 'OK',
        'show_alert' => false
    ]);
}

echo 'ok';
