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
        $postJson('deleteMessage', ['chat_id' => (string)$chatId, 'message_id' => (int)$messageId]);
        $callbackText = 'Игнор блюда установлен. Сообщение удалено.';
    }
} catch (\Throwable $e) {
    $callbackText = 'Ошибка';
}
