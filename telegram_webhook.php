<?php
date_default_timezone_set('Asia/Ho_Chi_Minh');

require_once __DIR__ . '/src/classes/Database.php';

if (file_exists(__DIR__ . '/.env')) {
    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) {
            continue;
        }
        [$name, $value] = explode('=', $line, 2);
        $_ENV[$name] = trim($value);
    }
}

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
                    tg_user_id BIGINT NULL,
                    tg_username VARCHAR(64) NULL,
                    tg_name VARCHAR(128) NULL,
                    KEY idx_expires_at (expires_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
                try { $pdo->exec("ALTER TABLE {$t} ADD COLUMN tg_user_id BIGINT NULL"); } catch (\Throwable $e) {}
                try { $pdo->exec("ALTER TABLE {$t} ADD COLUMN tg_username VARCHAR(64) NULL"); } catch (\Throwable $e) {}
                try { $pdo->exec("ALTER TABLE {$t} ADD COLUMN tg_name VARCHAR(128) NULL"); } catch (\Throwable $e) {}
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
                $postJson('sendMessage', [
                    'chat_id' => $chatId,
                    'text' => "Готово.\nНажми кнопку ниже, чтобы вернуться к заявке:",
                    'reply_markup' => [
                        'inline_keyboard' => [
                            [
                                [
                                    'text' => 'Вернуться на сайт',
                                    'url' => $returnUrl,
                                ],
                            ],
                        ],
                    ],
                ]);
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
                                    'web_app' => ['url' => 'https://veranda.my/links/menu-beta.php'],
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

if (!preg_match('/^(ack_alert|ack_tx|ignore_item|ignore_tx|vposter|vdecline|vrestore|vposter_fix|vposter_cancel):(\d+)$/', $data, $m)) {
    echo 'ok';
    exit;
}

$action = (string)($m[1] ?? '');
$id = (int)($m[2] ?? 0);
$from = $callback['from'] ?? [];
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
        $userPermissions = json_decode((string)($uRow['permissions_json'] ?? '{}'), true);
        if (is_array($userPermissions) && (!empty($userPermissions['admin']) || !empty($userPermissions['exclude_toggle']) || !empty($userPermissions['telegram_ack']) || !empty($userPermissions['vposter_button']))) {
            $isAllowed = true;
        }
    }
    
    if (in_array($action, ['vposter', 'vdecline', 'vrestore', 'vposter_fix', 'vposter_cancel'], true) && empty($userPermissions['vposter_button']) && empty($userPermissions['admin'])) {
        $isAllowed = false;
    }

    if (!$isAllowed) {
        if ($callbackId !== '') {
            $postJson('answerCallbackQuery', [
                'callback_query_id' => $callbackId,
                'text' => 'Эта кнопка только для уважаемых людей',
                'show_alert' => true
            ]);
        }
        echo 'ok';
        exit;
    }

    if (in_array($action, ['vposter', 'vdecline', 'vrestore', 'vposter_fix', 'vposter_cancel', 'ignore_tx', 'ignore_item', 'ack_tx', 'ack_alert'], true) && $id > 0) {
        $actionFile = __DIR__ . '/webhook_actions/' . $action . '.php';
        if (file_exists($actionFile)) {
            require_once $actionFile;
        }
    }
} catch (\Exception $e) {
}

if ($callbackId !== '') {
    $postJson('answerCallbackQuery', [
        'callback_query_id' => $callbackId,
        'text' => $callbackText ?? 'OK',
        'show_alert' => false
    ]);
}

echo 'ok';
