<?php

require_once __DIR__ . '/../src/classes/TelegramBot.php';
require_once __DIR__ . '/../src/classes/ReservationTelegram.php';
require_once __DIR__ . '/tg_config.php';

function reservations_send_manager_booking(\App\Classes\Database $db, string $resTable, int $resId, array $reservationPayload, array $keyboard): int {
    $tgToken = reservations_tg_token();
    $tgChatId = reservations_tg_chat_id();
    $tgThreadNum = reservations_tg_thread_id();
    if ($tgToken === '' || $tgChatId === '') {
        throw new \Exception('Telegram not configured');
    }

    $text = \App\Classes\ReservationTelegram::buildManagerText($reservationPayload);
    $bot = new \App\Classes\TelegramBot($tgToken, $tgChatId);
    if (!empty($keyboard)) {
        $msgId = (int)($bot->sendMessageGetIdWithKeyboard($text, $keyboard, $tgThreadNum > 0 ? $tgThreadNum : null) ?? 0);
    } else {
        $msgId = (int)($bot->sendMessageGetId($text, $tgThreadNum > 0 ? $tgThreadNum : null) ?? 0);
    }

    if ($msgId > 1) {
        $db->query("UPDATE {$resTable} SET tg_message_id = ? WHERE id = ?", [$msgId, $resId]);
    }
    return $msgId;
}
