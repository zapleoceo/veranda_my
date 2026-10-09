<?php

declare(strict_types=1);

namespace App\AiBot;

/**
 * Хранилище черновиков tg_finance_drafts. Строка — массив колонок таблицы;
 * *_json-поля хранятся строками, (де)кодирует их FinanceDraftService.
 *
 * UNIQUE(chat_id, source_msg_id, intent): повторный триггер на тот же
 * источник возвращает существующий черновик, а не создаёт второй.
 */
interface FinanceDraftRepositoryInterface
{
    /** @return array<string,mixed>|null */
    public function findBySource(string $chatId, int $sourceMsgId, string $intent): ?array;

    /** @return array<string,mixed>|null */
    public function get(int $id): ?array;

    /**
     * Вставить черновик. При конфликте UNIQUE — вернуть id уже существующего.
     *
     * @param array<string,mixed> $row
     */
    public function insert(array $row): int;

    /** @param array<string,mixed> $fields */
    public function update(int $id, array $fields): void;
}
