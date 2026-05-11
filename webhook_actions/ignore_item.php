<?php
if ($id <= 0) {
    $callbackText = 'Некорректный id';
    exit;
}

try {
    $db->query(
        "UPDATE {$ks}
         SET exclude_from_dashboard = 1,
             exclude_auto = 0
         WHERE id = ?",
        [$id]
    );
    $callbackText = 'Игнор блюда установлен.';
    if (!empty($chatId) && !empty($messageId) && is_callable($postJson)) {
        $r = $postJson('deleteMessage', ['chat_id' => (string)$chatId, 'message_id' => (int)$messageId]);
        if (is_array($r) && !empty($r['ok'])) {
            $callbackText = 'Игнор блюда установлен. Сообщение удалено.';
        } else {
            $postJson('editMessageReplyMarkup', [
                'chat_id' => (string)$chatId,
                'message_id' => (int)$messageId,
                'reply_markup' => ['inline_keyboard' => []],
            ]);
            $callbackText = 'Игнор блюда установлен. Кнопки убраны.';
        }
    }
} catch (\Throwable $e) {
    $callbackText = 'Ошибка';
}
