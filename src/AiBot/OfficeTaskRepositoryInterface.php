<?php

declare(strict_types=1);

namespace App\AiBot;

/**
 * Хранилище задач в офис: tg_office_tasks + tg_office_outbox. Строка — массив колонок.
 * Идемпотентность держится на UNIQUE: task_key, update_id, (chat_id, origin_message_id),
 * outbox.idempotency_key. insert* при конфликте возвращает id уже существующей строки.
 */
interface OfficeTaskRepositoryInterface
{
    /** Атомарно выполнить $fn (задача + outbox — всё или ничего). */
    public function transactional(callable $fn): mixed;

    /** @return array<string,mixed>|null */
    public function findTaskByKey(string $taskKey): ?array;

    /** @return array<string,mixed>|null */
    public function findTaskByOrigin(string $chatId, int $originMessageId): ?array;

    /** Задача чата, ждущая ответа на вопрос бота с этим message_id. @return array<string,mixed>|null */
    public function findWaitingByQuestionMsg(string $chatId, int $questionMsgId): ?array;

    /** @param array<string,mixed> $row */
    public function insertTask(array $row): int;

    /** @param array<string,mixed> $fields */
    public function updateTask(string $taskKey, array $fields): void;

    /** @return array<string,mixed>|null */
    public function findOutboxByKey(string $idempotencyKey): ?array;

    /** @param array<string,mixed> $row */
    public function insertOutbox(array $row): int;
}
