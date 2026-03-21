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

$tgTokenMissing = empty($tgToken);
$raw = file_get_contents('php://input');
$update = json_decode($raw, true);

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

if (!preg_match('/^ack_alert:(\d+)$/', $data, $m)) {
    echo 'ok';
    exit;
}

$itemId = (int)$m[1];
$from = $callback['from'] ?? [];
$ackBy = trim(($from['first_name'] ?? '') . ' ' . ($from['last_name'] ?? ''));
if ($ackBy === '') {
    $ackBy = $from['username'] ?? 'unknown';
}

try {
    $db = new \App\Classes\Database($dbHost, $dbName, $dbUser, $dbPass);
    $ackAt = date('Y-m-d H:i:s');

    $username = strtolower(trim((string)($from['username'] ?? '')));
    $username = ltrim($username, '@');
    $isAllowed = false;
    if ($username !== '') {
        try {
            $row = $db->query("SELECT is_active, permissions_json FROM users WHERE telegram_username = ? LIMIT 1", [$username])->fetch();
            $isActive = (int)($row['is_active'] ?? 0) === 1;
            $rawPerms = (string)($row['permissions_json'] ?? '');
            $perms = $rawPerms !== '' ? json_decode($rawPerms, true) : null;
            $canIgnore = is_array($perms) && !empty($perms['exclude_toggle']);
            $isAllowed = $isActive && $canIgnore;
        } catch (\Exception $e) {
            $isAllowed = false;
        }
    }
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

    $row = $db->query(
        "SELECT transaction_date, transaction_id, dish_id, station, item_seq
         FROM kitchen_stats
         WHERE id = ?
         LIMIT 1",
        [$itemId]
    )->fetch();

    if (!empty($row['transaction_id']) && !empty($row['dish_id']) && !empty($row['transaction_date']) && !empty($row['station'])) {
        $itemSeq = (int)($row['item_seq'] ?? 1);
        if ($itemSeq <= 0) $itemSeq = 1;
        $db->query(
            "UPDATE kitchen_stats
             SET exclude_from_dashboard = 1,
                 exclude_auto = 0,
                 tg_message_id = NULL,
                 tg_acknowledged = 1,
                 tg_acknowledged_at = ?,
                 tg_acknowledged_by = ?
             WHERE transaction_date = ?
               AND transaction_id = ?
               AND dish_id = ?
               AND station = ?
               AND item_seq = ?",
            [$ackAt, $ackBy, $row['transaction_date'], $row['transaction_id'], $row['dish_id'], $row['station'], $itemSeq]
        );
        $db->query(
            "DELETE FROM tg_alert_messages WHERE transaction_date = ? AND transaction_id = ? AND dish_id = ? AND station = ? AND item_seq = ?",
            [$row['transaction_date'], $row['transaction_id'], $row['dish_id'], $row['station'], $itemSeq]
        );
    } else {
        $db->query(
            "UPDATE kitchen_stats
             SET exclude_from_dashboard = 1,
                 exclude_auto = 0,
                 tg_message_id = NULL,
                 tg_acknowledged = 1,
                 tg_acknowledged_at = ?,
                 tg_acknowledged_by = ?
             WHERE id = ?",
            [$ackAt, $ackBy, $itemId]
        );
        $db->query("DELETE FROM tg_alert_messages WHERE kitchen_stats_id = ?", [$itemId]);
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

if ($chatId !== '' && $messageId > 0) {
    $postJson('deleteMessage', [
        'chat_id' => $chatId,
        'message_id' => $messageId
    ]);
}

if ($callbackId !== '') {
    $postJson('answerCallbackQuery', [
        'callback_query_id' => $callbackId,
        'text' => 'Игнорировано.',
        'show_alert' => false
    ]);
}

echo 'ok';
