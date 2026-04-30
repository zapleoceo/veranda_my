<?php
require_once __DIR__ . '/../src/classes/ReservationTelegram.php';

$row = $db->query("SELECT * FROM {$resTable} WHERE id = ? LIMIT 1", [$id])->fetch();
if (!$row) {
    $postJson('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => 'Бронь не найдена в БД', 'show_alert' => true]);
    exit;
}

if (!empty($row['deleted_at'])) {
    $postJson('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => 'Бронь уже отказана', 'show_alert' => false]);
    exit;
}

$username = strtolower(trim((string)($from['username'] ?? '')));
$username = ltrim($username, '@');
$who = $username !== '' ? ('@' . $username) : $ackBy;
$at = date('Y-m-d H:i');

$db->query("UPDATE {$resTable} SET deleted_at = NOW(), deleted_by = ? WHERE id = ? LIMIT 1", [
    $who,
    $id
]);

$baseText = \App\Classes\ReservationTelegram::buildManagerText($row);
$newText = trim($baseText) . "\n\n❌ <b>Бронь отказана</b> менеджером " . htmlspecialchars($who) . ' · ' . htmlspecialchars($at);

$postJson('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => 'Отказано', 'show_alert' => false]);
$postJson('editMessageText', [
    'chat_id' => $chatId,
    'message_id' => $messageId,
    'text' => trim($newText),
    'parse_mode' => 'HTML',
    'reply_markup' => ['inline_keyboard' => \App\Classes\ReservationTelegram::keyboardDeclined($id)],
]);
exit;

