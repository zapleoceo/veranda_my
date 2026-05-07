<?php
require_once __DIR__ . '/../src/classes/ReservationTelegram.php';
require_once __DIR__ . '/../src/classes/PosterSpotHallsService.php';

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
