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

$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0777, true);
}
file_put_contents($logDir . '/webhook_debug.txt', date('Y-m-d H:i:s') . " RAW: " . $raw . "\n", FILE_APPEND);

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

if ($tgTokenMissing) {
    error_log('telegram_webhook: TELEGRAM_BOT_TOKEN is not set');
    echo 'ok';
    exit;
}

if (!preg_match('/^(ack_alert|ack_tx|ignore_item|ignore_tx):(\d+)$/', $data, $m)) {
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
    $ackAt = date('Y-m-d H:i:s');

    $username = strtolower(trim((string)($from['username'] ?? '')));
    $username = ltrim($username, '@');
    
    $isAllowed = false; 

    if ($username !== '') {
        $uRow = $db->query(
            "SELECT permissions_json
             FROM {$usersTable}
             WHERE telegram_username = ?
             LIMIT 1",
            [$username]
        )->fetch();
        if ($uRow) {
            $perms = json_decode((string)($uRow['permissions_json'] ?? '{}'), true);
            // DEBUG: bypass
            if (is_array($perms) && (!empty($perms['telegram_ack']) || !empty($perms['admin']))) {
                $isAllowed = true;
            }
        }
    }
    // TEMPORARY: allow you to test while we debug
    if ($username === 'zapleosoft') {
        $isAllowed = true;
    }
    
    file_put_contents($logDir . '/webhook_debug.txt', date('Y-m-d H:i:s') . " Action: $action, ID: $id, User: $username, Allowed: " . ($isAllowed ? 'yes' : 'no') . "\n", FILE_APPEND);

    if (!$isAllowed) {
        if ($callbackId !== '') {
            $apiBase = "https://api.telegram.org/bot{$tgToken}";
            $postJson = function (string $method, array $payload) use ($apiBase): void {
                $ch = curl_init("{$apiBase}/{$method}");
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                curl_exec($ch);
                curl_close($ch);
            };
            $postJson('answerCallbackQuery', [
                'callback_query_id' => $callbackId,
                'text' => 'Эта кнопка только для уважаемых людей',
                'show_alert' => true
            ]);
        }
        echo 'ok';
        exit;
    }

    if ($action === 'ignore_tx' && $id > 0) {
        $txId = $id;
        $dRow = $db->query(
            "SELECT transaction_date
             FROM {$ks}
             WHERE transaction_id = ?
             ORDER BY transaction_date DESC
             LIMIT 1",
            [$txId]
        )->fetch();
        $txDate = (string)($dRow['transaction_date'] ?? '');
        if ($txDate !== '') {
            $db->query(
                "UPDATE {$ks}
                 SET exclude_from_dashboard = 1,
                     exclude_auto = 0
                 WHERE transaction_date = ?
                   AND transaction_id = ?",
                [$txDate, $txId]
            );
            try {
                $itemsTable = $db->t('tg_alert_items');
                $rows = $db->query(
                    "SELECT message_id
                     FROM {$itemsTable}
                     WHERE transaction_date = ?
                       AND transaction_id = ?",
                    [$txDate, $txId]
                )->fetchAll();
                $apiBase = "https://api.telegram.org/bot{$tgToken}";
                $postJson = function (string $method, array $payload) use ($apiBase): void {
                    $ch = curl_init("{$apiBase}/{$method}");
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                    curl_exec($ch);
                    curl_close($ch);
                };
                foreach ($rows as $r) {
                    $mid = (int)($r['message_id'] ?? 0);
                    if ($mid > 0 && $chatId !== '') {
                        $postJson('deleteMessage', [
                            'chat_id' => $chatId,
                            'message_id' => $mid
                        ]);
                    }
                }
                $db->query("DELETE FROM {$itemsTable} WHERE transaction_id = ?", [$txId]);
            } catch (\Throwable $eDel) {
                file_put_contents($logDir . '/webhook_debug.txt', date('Y-m-d H:i:s') . " DEL ERROR: " . $eDel->getMessage() . "\n", FILE_APPEND);
            }
        }
        $callbackText = 'Игнор чека установлен.';
    } elseif ($action === 'ignore_item' && $id > 0) {
        $itemId = $id;
        $db->query(
            "UPDATE {$ks}
             SET exclude_from_dashboard = 1,
                 exclude_auto = 0
             WHERE id = ?",
            [$itemId]
        );
        try {
            $itemsTable = $db->t('tg_alert_items');
            $row = $db->query(
                "SELECT message_id, transaction_date
                 FROM {$itemsTable}
                 WHERE kitchen_stats_id = ?
                 LIMIT 1",
                [$itemId]
            )->fetch();
            $mid = (int)($row['message_id'] ?? 0);
            if ($mid > 0 && $chatId !== '') {
                $apiBase = "https://api.telegram.org/bot{$tgToken}";
                $ch = curl_init("{$apiBase}/deleteMessage");
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['chat_id' => $chatId, 'message_id' => $mid], JSON_UNESCAPED_UNICODE));
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                curl_exec($ch);
                curl_close($ch);
            }
            $db->query("DELETE FROM {$itemsTable} WHERE kitchen_stats_id = ?", [$itemId]);
        } catch (\Throwable $eDel) {
            file_put_contents($logDir . '/webhook_debug.txt', date('Y-m-d H:i:s') . " DEL ERROR: " . $eDel->getMessage() . "\n", FILE_APPEND);
        }
        $callbackText = 'Игнор блюда установлен.';
    } elseif ($action === 'ack_tx' && $id > 0) {
        $txId = $id;
        $dRow = $db->query(
            "SELECT transaction_date
             FROM {$ks}
             WHERE transaction_id = ?
             ORDER BY transaction_date DESC
             LIMIT 1",
            [$txId]
        )->fetch();
        $txDate = (string)($dRow['transaction_date'] ?? '');
        if ($txDate !== '') {
            $db->query(
                "UPDATE {$ks}
                 SET tg_acknowledged = 1,
                     tg_acknowledged_at = ?,
                     tg_acknowledged_by = ?
                 WHERE transaction_date = ?
                   AND transaction_id = ?",
                [$ackAt, $ackBy, $txDate, $txId]
            );
        }
        $callbackText = 'Принято.';
    } elseif ($action === 'ack_alert' && $id > 0) {
        $itemId = $id;
        $row = $db->query(
            "SELECT transaction_date, transaction_id, dish_id, station
             FROM {$ks}
             WHERE id = ?
             LIMIT 1",
            [$itemId]
        )->fetch();

        if (!empty($row['transaction_id']) && !empty($row['dish_id']) && !empty($row['transaction_date']) && !empty($row['station'])) {
            $db->query(
                "UPDATE {$ks}
                 SET tg_acknowledged = 1,
                     tg_acknowledged_at = ?,
                     tg_acknowledged_by = ?
                 WHERE transaction_date = ?
                   AND transaction_id = ?
                   AND dish_id = ?
                   AND station = ?",
                [$ackAt, $ackBy, $row['transaction_date'], $row['transaction_id'], $row['dish_id'], $row['station']]
            );
        } else {
            $db->query(
                "UPDATE {$ks}
                 SET tg_acknowledged = 1,
                     tg_acknowledged_at = ?,
                     tg_acknowledged_by = ?
                 WHERE id = ?",
                [$ackAt, $ackBy, $itemId]
            );
        }
        $callbackText = 'Принято.';
    }
} catch (\Exception $e) {
}

$apiBase = "https://api.telegram.org/bot{$tgToken}";

$postJson = function (string $method, array $payload) use ($apiBase): void {
    $ch = curl_init("{$apiBase}/{$method}");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_exec($ch);
    curl_close($ch);
};

if ($callbackId !== '') {
    $postJson('answerCallbackQuery', [
        'callback_query_id' => $callbackId,
        'text' => $callbackText ?? 'OK',
        'show_alert' => false
    ]);
}

echo 'ok';
