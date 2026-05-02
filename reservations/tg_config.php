<?php

function reservations_tg_token(): string {
    return trim((string)($_ENV['TELEGRAM_BOT_TOKEN'] ?? $_ENV['TG_BOT_TOKEN'] ?? ''));
}

function reservations_tg_chat_id(): string {
    return trim((string)($_ENV['TELEGRAM_CHAT_ID'] ?? $_ENV['TG_CHAT_ID'] ?? ''));
}

function reservations_tg_thread_id(): int {
    $raw = trim((string)($_ENV['TABLE_RESERVATION_THREAD_ID'] ?? ''));
    return ($raw !== '' && is_numeric($raw)) ? (int)$raw : 0;
}

