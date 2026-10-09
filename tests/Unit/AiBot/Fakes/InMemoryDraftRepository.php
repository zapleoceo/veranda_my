<?php

declare(strict_types=1);

namespace Tests\Unit\AiBot\Fakes;

use App\AiBot\FinanceDraftRepositoryInterface;

/** tg_finance_drafts в памяти, с тем же UNIQUE(chat_id, source_msg_id, intent). */
final class InMemoryDraftRepository implements FinanceDraftRepositoryInterface
{
    /** @var array<int,array<string,mixed>> */
    public array $rows = [];

    public function findBySource(string $chatId, int $sourceMsgId, string $intent): ?array
    {
        foreach ($this->rows as $r) {
            if ($r['chat_id'] === $chatId && (int) $r['source_msg_id'] === $sourceMsgId && $r['intent'] === $intent) {
                return $r;
            }
        }
        return null;
    }

    public function get(int $id): ?array
    {
        return $this->rows[$id] ?? null;
    }

    public function insert(array $row): int
    {
        $ex = $this->findBySource((string) $row['chat_id'], (int) $row['source_msg_id'], (string) $row['intent']);
        if ($ex !== null) {
            return (int) $ex['id'];
        }
        $id = count($this->rows) + 1;
        $this->rows[$id] = $row + ['id' => $id, 'card_msg_id' => null, 'account_id' => null, 'tx_date' => null,
            'split_mode' => 'person', 'status' => 'draft', 'poster_tx_ids_json' => null, 'error' => null];
        $this->rows[$id]['id'] = $id;
        return $id;
    }

    public function update(int $id, array $fields): void
    {
        $this->rows[$id] = array_merge($this->rows[$id], $fields);
    }
}
