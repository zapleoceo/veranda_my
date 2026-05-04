<?php
require_once __DIR__ . '/../src/classes/ReservationTelegram.php';
$row = $db->query("SELECT * FROM {$resTable} WHERE id = ? LIMIT 1", [$id])->fetch();
if (!$row) {
    $postJson('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => 'Бронь не найдена в БД', 'show_alert' => true]);
    exit;
}

$isDeclined = !empty($row['deleted_at']);
if ($isDeclined) {
    $postJson('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => 'Бронь отказана. Сначала восстановите.', 'show_alert' => true]);
    exit;
}

$pushedState = (int)($row['is_poster_pushed'] ?? 0);
if ($pushedState === 2) {
    $postJson('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => 'Бронь уже отправляется в Poster', 'show_alert' => false]);
    exit;
}
if ($pushedState === 1) {
    $postJson('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => 'Бронь уже отправлена в Poster', 'show_alert' => false]);
    exit;
}

if (empty($_ENV['POSTER_API_TOKEN'])) {
    $postJson('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => 'Poster API не настроен', 'show_alert' => true]);
    exit;
}

$spotTzName = trim((string)($_ENV['POSTER_SPOT_TIMEZONE'] ?? ''));
if ($spotTzName === '' || !in_array($spotTzName, timezone_identifiers_list(), true)) {
    $spotTzName = 'Asia/Ho_Chi_Minh';
}
$spotTz = new DateTimeZone($spotTzName);

$startRaw = (string)($row['start_time'] ?? '');
$startDt = null;
if ($startRaw !== '') {
    $startDt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $startRaw, $spotTz) ?: null;
}
if (!$startDt) {
    try { $startDt = new DateTimeImmutable($startRaw, $spotTz); } catch (\Throwable $e) {}
}
if ($startDt) {
    $now = new DateTimeImmutable('now', $spotTz);
    if ($startDt->getTimestamp() <= $now->getTimestamp()) {
        $postJson('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => 'Бронь устарела', 'show_alert' => false]);
        $baseText = \App\Classes\ReservationTelegram::buildManagerText($row);
        $newText = trim($baseText) . "\n\n⏰ <b>Время начала брони уже прошло.</b>\nМожно обновить бронь так, чтобы она начиналась с текущего времени, а время окончания осталось прежним.";
        $postJson('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => trim($newText),
            'parse_mode' => 'HTML',
            'reply_markup' => ['inline_keyboard' => \App\Classes\ReservationTelegram::keyboardStale($id)],
        ]);
        exit;
    }
}

$postJson('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => 'Отправляю в Poster…', 'show_alert' => false]);

require_once __DIR__ . '/../src/classes/PosterReservationHelper.php';
$spotId = (string)($_ENV['POSTER_SPOT_ID'] ?? '1');
$res = \App\Classes\PosterReservationHelper::pushToPoster($db, $_ENV['POSTER_API_TOKEN'], $id, $spotId, $ackBy);

if (!$res['ok']) {
    $baseText = \App\Classes\ReservationTelegram::buildManagerText($row);
    $newText = trim($baseText) . "\n\n❌ Poster: " . htmlspecialchars((string)$res['error']);
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
    $baseText = \App\Classes\ReservationTelegram::buildManagerText($row);
    $baseText = preg_replace('/\n?\s*@Ollushka90\s+@ce_akh1\s+свяжитесь\s+с\s+гостем\s*\n?/u', "\n", $baseText);
    $newText = trim($baseText) . "\n\n🚀 <b>Уже была в Poster</b> (дубль предотвращен)";
} else {
    $baseText = \App\Classes\ReservationTelegram::buildManagerText($row);
    $baseText = preg_replace('/\n?\s*@Ollushka90\s+@ce_akh1\s+свяжитесь\s+с\s+гостем\s*\n?/u', "\n", $baseText);
    $newText = trim($baseText) . "\n\n🚀 <b>Отправлено в Poster</b> (" . htmlspecialchars($ackBy) . ")";
}

$postJson('editMessageText', [
    'chat_id' => $chatId,
    'message_id' => $messageId,
    'text' => trim($newText),
    'parse_mode' => 'HTML',
    'reply_markup' => ['inline_keyboard' => []] // Remove buttons
]);
echo 'ok';
exit;
