<?php
require_once __DIR__ . '/../src/classes/ReservationTelegram.php';
require_once __DIR__ . '/../src/classes/PosterSpotHallsService.php';

$row = $db->query("SELECT * FROM {$resTable} WHERE id = ? LIMIT 1", [$id])->fetch();
if (!$row) {
    $postJson('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => 'Бронь не найдена в БД', 'show_alert' => true]);
    exit;
}

$db->query("UPDATE {$resTable} SET deleted_at = NULL, deleted_by = NULL WHERE id = ? LIMIT 1", [$id]);

$row = $db->query("SELECT * FROM {$resTable} WHERE id = ? LIMIT 1", [$id])->fetch();
$row = is_array($row) ? $row : [];
$spotIdRow = (int)($row['spot_id'] ?? 0);
if ($spotIdRow <= 0) $spotIdRow = (int)($_ENV['POSTER_SPOT_ID'] ?? 1);
if ($spotIdRow <= 0) $spotIdRow = 1;
$hallIdRow = (int)($row['hall_id'] ?? 0);
if ($hallIdRow > 0) {
    $hallName = \App\Classes\PosterSpotHallsService::getHallName($db, trim((string)($_ENV['POSTER_API_TOKEN'] ?? '')), $spotIdRow, $hallIdRow);
    if ($hallName === '') $hallName = 'hall_id=' . (string)$hallIdRow;
    $row['hall_name'] = $hallName;
}
$baseText = \App\Classes\ReservationTelegram::buildManagerText($row);

$postJson('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => 'Восстановлено', 'show_alert' => false]);
$postJson('editMessageText', [
    'chat_id' => $chatId,
    'message_id' => $messageId,
    'text' => trim($baseText),
    'parse_mode' => 'HTML',
    'reply_markup' => ['inline_keyboard' => \App\Classes\ReservationTelegram::keyboardActive($id)],
]);
exit;
