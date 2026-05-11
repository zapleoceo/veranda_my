<?php
if ($id <= 0) {
    $callbackText = 'Некорректный id';
    exit;
}

try {
    $txId = $id;
    $dRow = $db->query(
        "SELECT transaction_date
         FROM {$ks}
         WHERE transaction_id = ?
         ORDER BY transaction_date DESC
         LIMIT 1",
        [$txId]
    )->fetch();
    $txDate = (string)($dRow['transaction_date'] ?? '');
    if ($txDate !== '') {
        $db->query(
            "UPDATE {$ks}
             SET exclude_from_dashboard = 1,
                 exclude_auto = 0
             WHERE transaction_date = ?
               AND transaction_id = ?",
            [$txDate, $txId]
        );
    }
    $deleted = 0;
    if ($txDate !== '' && is_callable($postJson) && !empty($chatId)) {
        $tgItems = $db->t('tg_alert_items');
        $ids = [];
        try {
            $rows = $db->query(
                "SELECT DISTINCT message_id
                 FROM {$tgItems}
                 WHERE transaction_date = ?
                   AND transaction_id = ?
                   AND message_id IS NOT NULL",
                [$txDate, $txId]
            )->fetchAll();
            $rows = is_array($rows) ? $rows : [];
            foreach ($rows as $r) {
                $mid = (int)($r['message_id'] ?? 0);
                if ($mid > 0) $ids[$mid] = true;
            }
        } catch (\Throwable $e) {
        }
        if (!empty($messageId) && (int)$messageId > 0) $ids[(int)$messageId] = true;
        foreach (array_keys($ids) as $mid) {
            $r = $postJson('deleteMessage', ['chat_id' => (string)$chatId, 'message_id' => (int)$mid]);
            if (is_array($r) && array_key_exists('ok', $r) && !empty($r['ok'])) $deleted++;
        }
        try {
            $db->query("DELETE FROM {$tgItems} WHERE transaction_date = ? AND transaction_id = ?", [$txDate, $txId]);
        } catch (\Throwable $e) {
        }
    }
    $callbackText = $deleted > 0 ? ('Игнор чека установлен. Удалено сообщений: ' . $deleted) : 'Игнор чека установлен.';
} catch (\Throwable $e) {
    $callbackText = 'Ошибка';
}
