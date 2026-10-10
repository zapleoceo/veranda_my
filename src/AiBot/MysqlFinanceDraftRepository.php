<?php

declare(strict_types=1);

namespace App\AiBot;

use App\Infrastructure\Database;

/**
 * tg_finance_drafts — черновики расходов из @Veranda_aibot. Таблица
 * создаётся при первом обращении (как payday_audit_log в AuditLogRepository).
 */
final class MysqlFinanceDraftRepository implements FinanceDraftRepositoryInterface
{
    private const COLUMNS = [
        'chat_id', 'source_msg_id', 'trigger_msg_id', 'card_msg_id', 'initiator_tg_id', 'intent',
        'rows_json', 'account_id', 'tx_date', 'split_mode', 'status', 'poster_tx_ids_json', 'error', 'heartbeat_at', 'confirm_nonce', 'confirm_nonce_at',
    ];

    private static bool $tableChecked = false;

    public function __construct(private readonly Database $db) {}

    public static function ddl(string $table): string
    {
        return "CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            chat_id VARCHAR(32) NOT NULL,
            source_msg_id BIGINT NOT NULL,
            trigger_msg_id BIGINT NOT NULL DEFAULT 0,
            card_msg_id BIGINT NULL,
            initiator_tg_id BIGINT NOT NULL,
            intent VARCHAR(32) NOT NULL,
            rows_json LONGTEXT NOT NULL,
            account_id INT NULL,
            tx_date DATE NULL,
            split_mode VARCHAR(16) NOT NULL DEFAULT 'person',
            status VARCHAR(16) NOT NULL DEFAULT 'draft',
            poster_tx_ids_json LONGTEXT NULL,
            error TEXT NULL,
            heartbeat_at INT UNSIGNED NULL,
            confirm_nonce VARCHAR(16) NULL,
            confirm_nonce_at INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_tfd_source (chat_id, source_msg_id, intent),
            KEY idx_tfd_status (status, updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    }

    public function findBySource(string $chatId, int $sourceMsgId, string $intent): ?array
    {
        $t = $this->table();
        $row = $this->db->query(
            "SELECT * FROM {$t} WHERE chat_id = ? AND source_msg_id = ? AND intent = ? LIMIT 1",
            [$chatId, $sourceMsgId, $intent]
        )->fetch();
        return is_array($row) ? $row : null;
    }

    public function get(int $id): ?array
    {
        $t = $this->table();
        $row = $this->db->query("SELECT * FROM {$t} WHERE id = ? LIMIT 1", [$id])->fetch();
        return is_array($row) ? $row : null;
    }

    public function insert(array $row): int
    {
        $t = $this->table();
        $row = array_intersect_key($row, array_flip(self::COLUMNS));
        $cols = array_keys($row);
        // ON DUPLICATE KEY с id=LAST_INSERT_ID(id): при конфликте UNIQUE
        // lastInsertId() вернёт id существующей строки — без гонки SELECT→INSERT.
        $this->db->query(
            "INSERT INTO {$t} (" . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)',
            array_values($row)
        );
        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, array $fields): void
    {
        $fields = array_intersect_key($fields, array_flip(self::COLUMNS));
        if ($fields === []) {
            return;
        }
        $t = $this->table();
        $set = implode(', ', array_map(static fn(string $c) => "{$c} = ?", array_keys($fields)));
        $this->db->query("UPDATE {$t} SET {$set} WHERE id = ?", [...array_values($fields), $id]);
    }

    private function table(): string
    {
        $t = $this->db->t('tg_finance_drafts');
        if (!self::$tableChecked) {
            $this->db->query(self::ddl($t));
            // Таблица могла быть создана прошлой версией — догоняем колонки подтверждения.
            $has = $this->db->query("SHOW COLUMNS FROM {$t} LIKE 'confirm_nonce'")->fetch();
            if (!$has) {
                $this->db->query("ALTER TABLE {$t} ADD COLUMN confirm_nonce VARCHAR(16) NULL, ADD COLUMN confirm_nonce_at INT UNSIGNED NULL");
            }
            self::$tableChecked = true;
        }
        return $t;
    }
}
