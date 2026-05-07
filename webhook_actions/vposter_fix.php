<?php
require_once __DIR__ . '/../src/classes/ReservationTelegram.php';
require_once __DIR__ . '/../src/classes/PosterReservationHelper.php';
require_once __DIR__ . '/../src/classes/PosterSpotHallsService.php';

$row = $db->query("SELECT * FROM {$resTable} WHERE id = ? LIMIT 1", [$id])->fetch();
if (!$row) {
    $postJson('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => 'Бронь не найдена в БД', 'show_alert' => true]);
    exit;
}

if (!empty($row['deleted_at'])) {
    $postJson('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => 'Бронь отказана. Сначала восстановите.', 'show_alert' => true]);
    exit;
}

if (empty($_ENV['POSTER_API_TOKEN'])) {
    $postJson('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => 'Poster API не настроен', 'show_alert' => true]);
    exit;
}

$spotIdRow = (int)($row['spot_id'] ?? 0);
if ($spotIdRow <= 0) $spotIdRow = (int)($_ENV['POSTER_SPOT_ID'] ?? 1);
if ($spotIdRow <= 0) $spotIdRow = 1;
$hallIdRow = (int)($row['hall_id'] ?? 0);
if ($hallIdRow > 0) {
    $hallName = \App\Classes\PosterSpotHallsService::getHallName($db, trim((string)$_ENV['POSTER_API_TOKEN']), $spotIdRow, $hallIdRow);
    if ($hallName === '') $hallName = 'hall_id=' . (string)$hallIdRow;
    $row['hall_name'] = $hallName;
}

$spotTzName = trim((string)($_ENV['POSTER_SPOT_TIMEZONE'] ?? ''));
if ($spotTzName === '' || !in_array($spotTzName, timezone_identifiers_list(), true)) {
    $spotTzName = 'Asia/Ho_Chi_Minh';
}
$spotTz = new DateTimeZone($spotTzName);

$pushedState = (int)($row['is_poster_pushed'] ?? 0);
if ($pushedState === 2) {
    $postJson('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => 'Бронь уже отправляется в Poster', 'show_alert' => false]);
    exit;
}
if ($pushedState === 1) {
    $postJson('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => 'Бронь уже отправлена в Poster', 'show_alert' => false]);
    exit;
}

$startRaw = (string)($row['start_time'] ?? '');
$startDt = null;
if ($startRaw !== '') {
    $startDt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $startRaw, $spotTz) ?: null;
}
if (!$startDt) {
    try { $startDt = new DateTimeImmutable($startRaw, $spotTz); } catch (\Throwable $e) {}
}
if (!$startDt) $startDt = new DateTimeImmutable('now', $spotTz);
$duration = (int)($row['duration'] ?? 0);
if ($duration <= 0) $duration = 120;
$oldEnd = $startDt->modify('+' . $duration . ' minutes');

$now = new DateTimeImmutable('now', $spotTz);
$newStart = $now->setTime((int)$now->format('H'), (int)$now->format('i'), 0);
if ($oldEnd->getTimestamp() <= $newStart->getTimestamp()) {
    $postJson('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => 'Бронь уже полностью прошла', 'show_alert' => true]);
    $baseText = \App\Classes\ReservationTelegram::buildManagerText($row);
    $newText = trim($baseText) . "\n\n⏰ <b>Время брони уже прошло полностью.</b>";
    $postJson('editMessageText', [
        'chat_id' => $chatId,
        'message_id' => $messageId,
        'text' => trim($newText),
        'parse_mode' => 'HTML',
        'reply_markup' => ['inline_keyboard' => \App\Classes\ReservationTelegram::keyboardStale($id)],
    ]);
    exit;
}

$diffSec = $oldEnd->getTimestamp() - $newStart->getTimestamp();
$newDuration = (int)ceil($diffSec / 60);
if ($newDuration < 1) $newDuration = 1;

$db->query("UPDATE {$resTable} SET start_time = ?, duration = ? WHERE id = ? LIMIT 1", [
    $newStart->format('Y-m-d H:i:s'),
    $newDuration,
    $id
]);

$postJson('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => 'Обновляю и отправляю…', 'show_alert' => false]);

$spotId = (string)($_ENV['POSTER_SPOT_ID'] ?? '1');
$res = \App\Classes\PosterReservationHelper::pushToPoster($db, $_ENV['POSTER_API_TOKEN'], $id, $spotId, $ackBy);

$row2 = $db->query("SELECT * FROM {$resTable} WHERE id = ? LIMIT 1", [$id])->fetch();
$row2 = is_array($row2) ? $row2 : [];
if (!empty($row['hall_name'])) $row2['hall_name'] = $row['hall_name'];
$baseText2 = \App\Classes\ReservationTelegram::buildManagerText($row2);
$baseText2 = preg_replace('/\n?\s*@Ollushka90\s+@ce_akh1\s+свяжитесь\s+с\s+гостем\s*\n?/u', "\n", $baseText2);

if (!$res['ok']) {
    $newText = trim($baseText2) . "\n\n❌ Poster: " . htmlspecialchars((string)$res['error']);
    $postJson('editMessageText', [
        'chat_id' => $chatId,
        'message_id' => $messageId,
        'text' => trim($newText),
        'parse_mode' => 'HTML',
        'reply_markup' => ['inline_keyboard' => \App\Classes\ReservationTelegram::keyboardActive($id)],
    ]);
    exit;
}

if (!empty($res['duplicate'])) {
    $newText = trim($baseText2) . "\n\n🚀 <b>Уже была в Poster</b> (дубль предотвращен)";
} else {
    $newText = trim($baseText2) . "\n\n⏱ <b>Время старта обновлено</b>: " . htmlspecialchars($newStart->format('H:i')) . "\n🚀 <b>Отправлено в Poster</b> (" . htmlspecialchars($ackBy) . ")";
}

$postJson('editMessageText', [
    'chat_id' => $chatId,
    'message_id' => $messageId,
    'text' => trim($newText),
    'parse_mode' => 'HTML',
    'reply_markup' => ['inline_keyboard' => []]
]);
exit;
