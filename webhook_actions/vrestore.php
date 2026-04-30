<?php
require_once __DIR__ . '/../src/classes/ReservationTelegram.php';

$row = $db->query("SELECT * FROM {$resTable} WHERE id = ? LIMIT 1", [$id])->fetch();
if (!$row) {
    $postJson('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => 'Бронь не найдена в БД', 'show_alert' => true]);
    exit;
}

$db->query("UPDATE {$resTable} SET deleted_at = NULL, deleted_by = NULL WHERE id = ? LIMIT 1", [$id]);

$row = $db->query("SELECT * FROM {$resTable} WHERE id = ? LIMIT 1", [$id])->fetch();
$baseText = \App\Classes\ReservationTelegram::buildManagerText($row ?: []);

$postJson('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => 'Восстановлено', 'show_alert' => false]);
$postJson('editMessageText', [
    'chat_id' => $chatId,
    'message_id' => $messageId,
    'text' => trim($baseText),
    'parse_mode' => 'HTML',
    'reply_markup' => ['inline_keyboard' => \App\Classes\ReservationTelegram::keyboardActive($id)],
]);
exit;

