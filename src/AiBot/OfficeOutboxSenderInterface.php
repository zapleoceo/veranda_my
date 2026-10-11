<?php

declare(strict_types=1);

namespace App\AiBot;

/**
 * Доставка строки tg_office_outbox в офис.
 *
 * Реализации: NullOfficeOutboxSender (не настроено) и HttpOfficeOutboxSender (OFFICE_API_URL + токен).
 */
interface OfficeOutboxSenderInterface
{
    /**
     * @param array<string,mixed> $outboxRow
     * @return bool true — доставлено, false — оставить в очереди
     */
    public function send(array $outboxRow): bool;

    /** true — сервер отверг учётные данные (401/403): остальной пакет отправлять бессмысленно. */
    public function isHalted(): bool;
}
