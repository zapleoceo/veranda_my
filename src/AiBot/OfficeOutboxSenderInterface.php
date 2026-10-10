<?php

declare(strict_types=1);

namespace App\AiBot;

/**
 * Доставка строки tg_office_outbox в офис.
 *
 * TODO: реальная реализация (HTTP-клиент, cron-потребитель, статусы sent/failed,
 * attempts/next_attempt_at) — отдельной задачей; здесь граница намеренно оборвана.
 */
interface OfficeOutboxSenderInterface
{
    /**
     * @param array<string,mixed> $outboxRow
     * @return bool true — доставлено, false — оставить в очереди
     */
    public function send(array $outboxRow): bool;
}
