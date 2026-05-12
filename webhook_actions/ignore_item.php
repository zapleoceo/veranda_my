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
} catch (\Throwable $e) {
    $callbackText = 'Ошибка';
}

