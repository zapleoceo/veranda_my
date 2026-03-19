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
    $whitelistRow = $db->query("SELECT meta_value FROM system_meta WHERE meta_key = ? LIMIT 1", ['telegram_ack_whitelist'])->fetch();
    $whitelist = json_decode((string)($whitelistRow['meta_value'] ?? '{}'), true);
    if (!is_array($whitelist)) {
        $whitelist = [];
    }
    $isAllowed = $username !== '' && array_key_exists($username, $whitelist);
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
        "SELECT transaction_date, transaction_id, dish_id, station
         FROM kitchen_stats
         WHERE id = ?
         LIMIT 1",
        [$itemId]
    )->fetch();

    if (!empty($row['transaction_id']) && !empty($row['dish_id']) && !empty($row['transaction_date']) && !empty($row['station'])) {
        $db->query(
            "UPDATE kitchen_stats
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
            "UPDATE kitchen_stats
             SET tg_acknowledged = 1,
                 tg_acknowledged_at = ?,
                 tg_acknowledged_by = ?
             WHERE id = ?",
            [$ackAt, $ackBy, $itemId]
        );
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
    $postJson('editMessageReplyMarkup', [
        'chat_id' => $chatId,
        'message_id' => $messageId,
        'reply_markup' => ['inline_keyboard' => []]
    ]);
}

if ($callbackId !== '') {
    $postJson('answerCallbackQuery', [
        'callback_query_id' => $callbackId,
        'text' => 'Принято.',
        'show_alert' => false
    ]);
}

echo 'ok';
