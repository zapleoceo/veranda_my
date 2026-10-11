<?php

declare(strict_types=1);

namespace App\AiBot;

use App\Infrastructure\Database;

/** tg_office_tasks / tg_office_outbox; таблицы создаются при первом обращении. */
final class MysqlOfficeTaskRepository implements OfficeTaskRepositoryInterface
{
    private const TASK_COLUMNS = [
        'task_key', 'chat_id', 'thread_id', 'origin_message_id', 'origin_user_id', 'update_id', 'text',
        'context_json', 'media_json', 'media_status', 'status', 'revision', 'last_question_id', 'last_question_msg_id', 'result_delivered_at',
        'office_task_id', 'office_revision', 'office_delivered_revision', 'lease_live',
    ];
    private const OUTBOX_COLUMNS = [
        'idempotency_key', 'task_key', 'kind', 'payload_json', 'status', 'attempts', 'next_attempt_at', 'last_error',
    ];

    private static bool $tablesChecked = false;
    private static bool $officeColumnsChecked = false;

    public function __construct(private readonly Database $db) {}

    // CREATE TABLE IF NOT EXISTS НЕ добавляет колонки в уже существующую таблицу:
    // любое изменение схемы потребует отдельного ALTER TABLE (как confirm_nonce в MysqlFinanceDraftRepository).
    public static function tasksDdl(string $table): string
    {
        return "CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            task_key VARCHAR(64) NOT NULL,
            chat_id VARCHAR(32) NOT NULL,
            thread_id BIGINT NULL,
            origin_message_id BIGINT NOT NULL,
            origin_user_id BIGINT NOT NULL,
            update_id BIGINT NOT NULL,
            text TEXT NOT NULL,
            context_json LONGTEXT NULL,
            media_json LONGTEXT NULL,
            media_status VARCHAR(16) NOT NULL DEFAULT 'none',
            status VARCHAR(24) NOT NULL DEFAULT 'queued_local',
            revision INT NOT NULL DEFAULT 1,
            last_question_id VARCHAR(64) NULL,
            last_question_msg_id BIGINT NULL,
            result_delivered_at DATETIME NULL,
            office_task_id VARCHAR(128) NULL,
            office_revision BIGINT NULL,
            office_delivered_revision BIGINT NULL,
            lease_live TINYINT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_tot_key (task_key),
            UNIQUE KEY uq_tot_update (update_id),
            UNIQUE KEY uq_tot_origin (chat_id, origin_message_id),
            UNIQUE KEY uq_tot_office (office_task_id),
            KEY idx_tot_question (chat_id, last_question_msg_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    }

    public static function outboxDdl(string $table): string
    {
        return "CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            idempotency_key VARCHAR(96) NOT NULL,
            task_key VARCHAR(64) NOT NULL,
            kind VARCHAR(16) NOT NULL,
            payload_json LONGTEXT NOT NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'pending',
            attempts INT NOT NULL DEFAULT 0,
            next_attempt_at DATETIME NULL,
            last_error TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_too_key (idempotency_key),
            KEY idx_too_status (status, next_attempt_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    }

    public function transactional(callable $fn): mixed
    {
        $this->ensureTables(); // DDL делает неявный commit - только до транзакции
        return $this->db->transaction($fn);
    }

    public function findTaskByKey(string $taskKey): ?array
    {
        return $this->one("SELECT * FROM {$this->tasks()} WHERE task_key = ? LIMIT 1", [$taskKey]);
    }

    public function findTaskByOrigin(string $chatId, int $originMessageId): ?array
    {
        return $this->one("SELECT * FROM {$this->tasks()} WHERE chat_id = ? AND origin_message_id = ? LIMIT 1", [$chatId, $originMessageId]);
    }

    public function findWaitingByQuestionMsg(string $chatId, int $questionMsgId): ?array
    {
        return $this->one(
            "SELECT * FROM {$this->tasks()} WHERE chat_id = ? AND last_question_msg_id = ? AND status = 'waiting_owner' LIMIT 1",
            [$chatId, $questionMsgId]
        );
    }

    public function insertTask(array $row): int
    {
        return $this->insert($this->tasks(), array_intersect_key($row, array_flip(self::TASK_COLUMNS)));
    }

    public function updateTask(string $taskKey, array $fields): void
    {
        $fields = array_intersect_key($fields, array_flip(self::TASK_COLUMNS));
        if ($fields === []) {
            return;
        }
        $set = implode(', ', array_map(static fn(string $c) => "{$c} = ?", array_keys($fields)));
        $this->db->query("UPDATE {$this->tasks()} SET {$set} WHERE task_key = ?", [...array_values($fields), $taskKey]);
    }

    public function findOutboxByKey(string $idempotencyKey): ?array
    {
        return $this->one("SELECT * FROM {$this->outbox()} WHERE idempotency_key = ? LIMIT 1", [$idempotencyKey]);
    }

    public function insertOutbox(array $row): int
    {
        return $this->insert($this->outbox(), array_intersect_key($row, array_flip(self::OUTBOX_COLUMNS)));
    }

    public function ensureOfficeColumns(): void
    {
        if (self::$officeColumnsChecked) {
            return;
        }
        $this->ensureTables();
        $table = $this->tasks();
        $want = ['office_task_id' => 'VARCHAR(128) NULL', 'office_revision' => 'BIGINT NULL',
            'office_delivered_revision' => 'BIGINT NULL', 'lease_live' => 'TINYINT NULL'];
        foreach ($want as $col => $def) {
            if ($this->db->query("SHOW COLUMNS FROM {$table} LIKE '{$col}'")->fetch() === false) {
                $this->tolerant("ALTER TABLE {$table} ADD COLUMN {$col} {$def}"); // гонка: 1060 duplicate column
            }
        }
        if ($this->db->query("SHOW INDEX FROM {$table} WHERE Key_name = 'uq_tot_office'")->fetch() === false) {
            $this->tolerant("ALTER TABLE {$table} ADD UNIQUE KEY uq_tot_office (office_task_id)"); // 1061 duplicate key
        }
        $this->db->query("CREATE TABLE IF NOT EXISTS {$this->db->t('tg_office_state')} (
            k VARCHAR(64) NOT NULL PRIMARY KEY,
            v TEXT NOT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        self::$officeColumnsChecked = true;
    }

    public function dueOutbox(int $limit, string $now): array
    {
        $stmt = $this->db->query(
            "SELECT * FROM {$this->outbox()} WHERE status = 'pending' AND (next_attempt_at IS NULL OR next_attempt_at <= ?)
             ORDER BY id LIMIT " . max(1, $limit),
            [$now]
        );
        return array_values($stmt->fetchAll());
    }

    public function updateOutbox(string $idempotencyKey, array $fields): void
    {
        $fields = array_intersect_key($fields, array_flip(['status', 'attempts', 'next_attempt_at', 'last_error']));
        if ($fields === []) {
            return;
        }
        $set = implode(', ', array_map(static fn(string $c) => "{$c} = ?", array_keys($fields)));
        $this->db->query("UPDATE {$this->outbox()} SET {$set} WHERE idempotency_key = ?", [...array_values($fields), $idempotencyKey]);
    }

    public function findTaskByOfficeId(string $officeTaskId): ?array
    {
        return $this->one("SELECT * FROM {$this->tasks()} WHERE office_task_id = ? LIMIT 1", [$officeTaskId]);
    }

    public function getState(string $key): ?string
    {
        $row = $this->one("SELECT v FROM {$this->db->t('tg_office_state')} WHERE k = ? LIMIT 1", [$key]);
        return $row === null ? null : (string) $row['v'];
    }

    public function setState(string $key, string $value): void
    {
        $this->db->query(
            "INSERT INTO {$this->db->t('tg_office_state')} (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)",
            [$key, $value]
        );
    }

    private function tolerant(string $ddl): void
    {
        try {
            $this->db->query($ddl);
        } catch (\PDOException $e) {
            // Параллельный процесс успел первым: дубликат колонки/ключа не ошибка.
            if (!in_array((int) ($e->errorInfo[1] ?? 0), [1060, 1061], true)) {
                throw $e;
            }
        }
    }

    /** @param array<string,mixed> $row */
    private function insert(string $table, array $row): int
    {
        $cols = array_keys($row);
        // При конфликте UNIQUE lastInsertId() вернёт id существующей строки.
        $this->db->query(
            "INSERT INTO {$table} (" . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)',
            array_values($row)
        );
        return (int) $this->db->lastInsertId();
    }

    /**
     * @param list<mixed> $params
     * @return array<string,mixed>|null
     */
    private function one(string $sql, array $params): ?array
    {
        $row = $this->db->query($sql, $params)->fetch();
        return is_array($row) ? $row : null;
    }

    private function tasks(): string
    {
        $this->ensureTables();
        return $this->db->t('tg_office_tasks');
    }

    private function outbox(): string
    {
        $this->ensureTables();
        return $this->db->t('tg_office_outbox');
    }

    private function ensureTables(): void
    {
        if (self::$tablesChecked) {
            return;
        }
        $this->db->query(self::tasksDdl($this->db->t('tg_office_tasks')));
        $this->db->query(self::outboxDdl($this->db->t('tg_office_outbox')));
        self::$tablesChecked = true;
    }
}
