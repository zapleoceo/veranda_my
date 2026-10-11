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

    /** Добавить office_*-колонки в уже существующую таблицу. Зовёт ТОЛЬКО реальный отправитель/поллер. */
    public function ensureOfficeColumns(): void;

    /** pending-строки outbox, у которых пришло время (next_attempt_at пуст или <= $now, 'Y-m-d H:i:s'). @return list<array<string,mixed>> */
    public function dueOutbox(int $limit, string $now): array;

    /** @param array<string,mixed> $fields status, attempts, next_attempt_at, last_error */
    public function updateOutbox(string $idempotencyKey, array $fields): void;

    /** @return array<string,mixed>|null */
    public function findTaskByOfficeId(string $officeTaskId): ?array;

    public function getState(string $key): ?string;

    public function setState(string $key, string $value): void;

    public function deleteState(string $key): void;
}
