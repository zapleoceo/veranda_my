<?php

declare(strict_types=1);

namespace Tests\Unit\AiBot\Fakes;

use App\AiBot\OfficeTaskRepositoryInterface;

/** tg_office_tasks / tg_office_outbox в памяти с теми же UNIQUE. */
final class InMemoryOfficeRepository implements OfficeTaskRepositoryInterface
{
    /** @var array<string,array<string,mixed>> по task_key */
    public array $tasks = [];
    /** @var array<string,array<string,mixed>> по idempotency_key */
    public array $outbox = [];
    private int $nextId = 1;
    public int $calls = 0;
    public bool $failLookup = false;
    public bool $swallowInsert = false;

    public function transactional(callable $fn): mixed
    {
        return $fn();
    }

    public function findTaskByKey(string $taskKey): ?array
    {
        $this->calls++;
        return $this->tasks[$taskKey] ?? null;
    }

    public function findTaskByOrigin(string $chatId, int $originMessageId): ?array
    {
        $this->calls++;
        foreach ($this->tasks as $t) {
            if ($t['chat_id'] === $chatId && (int) $t['origin_message_id'] === $originMessageId) {
                return $t;
            }
        }
        return null;
    }

    public function findWaitingByQuestionMsg(string $chatId, int $questionMsgId): ?array
    {
        $this->calls++;
        if ($this->failLookup) {
            throw new \RuntimeException('db down');
        }
        foreach ($this->tasks as $t) {
            if ($t['chat_id'] === $chatId && $t['last_question_msg_id'] !== null
                && (int) $t['last_question_msg_id'] === $questionMsgId && $t['status'] === 'waiting_owner') {
                return $t;
            }
        }
        return null;
    }

    public function insertTask(array $row): int
    {
        if ($this->swallowInsert) {
            return 0;
        }
        $ex = $this->tasks[$row['task_key']] ?? $this->findTaskByOrigin((string) $row['chat_id'], (int) $row['origin_message_id']);
        foreach ($this->tasks as $t) {
            if ((int) $t['update_id'] === (int) $row['update_id']) {
                $ex = $t;
            }
        }
        if ($ex !== null) {
            return (int) $ex['id'];
        }
        $row += ['thread_id' => null, 'context_json' => null, 'media_json' => null, 'media_status' => 'none',
            'last_question_id' => null, 'last_question_msg_id' => null, 'result_delivered_at' => null,
            'office_task_id' => null, 'office_revision' => null, 'office_delivered_revision' => null, 'lease_live' => null];
        $row['id'] = $this->nextId++;
        $this->tasks[$row['task_key']] = $row;
        return $row['id'];
    }

    public function updateTask(string $taskKey, array $fields): void
    {
        $this->tasks[$taskKey] = $fields + $this->tasks[$taskKey];
    }

    public function findOutboxByKey(string $idempotencyKey): ?array
    {
        return $this->outbox[$idempotencyKey] ?? null;
    }

    public int $ensureCalls = 0;
    /** @var array<string,string> */
    public array $state = [];

    public function ensureOfficeColumns(): void
    {
        $this->ensureCalls++;
    }

    public function dueOutbox(int $limit, string $now): array
    {
        $out = [];
        foreach ($this->outbox as $r) {
            if (($r['status'] ?? 'pending') === 'pending' && (($r['next_attempt_at'] ?? null) === null || $r['next_attempt_at'] <= $now)) {
                $out[] = $r;
            }
        }
        usort($out, static fn($a, $b) => $a['id'] <=> $b['id']);
        return array_slice($out, 0, $limit);
    }

    public function updateOutbox(string $idempotencyKey, array $fields): void
    {
        $this->outbox[$idempotencyKey] = $fields + $this->outbox[$idempotencyKey];
    }

    public function findTaskByOfficeId(string $officeTaskId): ?array
    {
        foreach ($this->tasks as $t) {
            if (($t['office_task_id'] ?? null) === $officeTaskId) {
                return $t;
            }
        }
        return null;
    }

    public function getState(string $key): ?string
    {
        return $this->state[$key] ?? null;
    }

    public function setState(string $key, string $value): void
    {
        $this->state[$key] = $value;
    }

    public function insertOutbox(array $row): int
    {
        $k = (string) $row['idempotency_key'];
        if (isset($this->outbox[$k])) {
            return (int) $this->outbox[$k]['id'];
        }
        $row['id'] = count($this->outbox) + 1;
        $this->outbox[$k] = $row;
        return (int) $row['id'];
    }
}
