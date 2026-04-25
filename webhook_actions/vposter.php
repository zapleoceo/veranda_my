<?php
$row = $db->query("SELECT * FROM {$resTable} WHERE id = ? LIMIT 1", [$id])->fetch();
if (!$row) {
    $postJson('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => 'Бронь не найдена в БД', 'show_alert' => true]);
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

$postJson('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => 'Отправляю в Poster…', 'show_alert' => false]);

require_once __DIR__ . '/../src/classes/PosterReservationHelper.php';
$spotId = (string)($_ENV['POSTER_SPOT_ID'] ?? '1');
$res = \App\Classes\PosterReservationHelper::pushToPoster($db, $_ENV['POSTER_API_TOKEN'], $id, $spotId, $ackBy);

if (!$res['ok']) {
    $rawText = (string)($message['text'] ?? '');
    // Remove previous error messages before adding a new one (looking for raw text from Telegram)
    $rawText = preg_replace('/\n\n❌ Poster:.*$/s', '', $rawText);
    $newText = $rawText . "\n\n❌ Poster: " . htmlspecialchars((string)$res['error']);
    $payload = [
        'chat_id' => $chatId,
        'message_id' => $messageId,
        'text' => trim($newText),
        'parse_mode' => 'HTML',
    ];
    $rm = $message['reply_markup'] ?? null;
    if (is_array($rm)) {
        $payload['reply_markup'] = $rm;
    }
    $postJson('editMessageText', $payload);
    exit;
}


if (!empty($res['duplicate'])) {
    $baseText = (string)($message['text'] ?? '');
    // Remove error messages and specific mentions
    $baseText = preg_replace('/\n\n❌ Poster:.*$/s', '', $baseText);
    $baseText = preg_replace('/\n?\s*@Ollushka90\s+@ce_akh1\s+свяжитесь\s+с\s+гостем\s*\n?/u', "\n", $baseText);
    $newText = trim($baseText) . "\n\n🚀 <b>Уже была в Poster</b> (дубль предотвращен)";
} else {
    $baseText = (string)($message['text'] ?? '');
    // Remove error messages and specific mentions
    $baseText = preg_replace('/\n\n❌ Poster:.*$/s', '', $baseText);
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