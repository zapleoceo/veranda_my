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
    $callbackText = 'Игнор чека установлен.';
} catch (\Throwable $e) {
    $callbackText = 'Ошибка';
}

