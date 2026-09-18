<?php

declare(strict_types=1);

namespace App\Payday3\Repositories;

use App\Infrastructure\Database;
use App\Payday3\Contracts\AuditLogInterface;

/**
 * payday_audit_log — append-only. The table is also declared in
 * Database::createPaydayTables(); self-created here on first use because
 * that bootstrap only runs from the SePay webhook.
 */
final class AuditLogRepository implements AuditLogInterface
{
    private static bool $tableChecked = false;

    public function __construct(private readonly Database $db) {}

    public function record(string $userEmail, string $action, array $payload, ?string $fingerprint = null): void
    {
        $t    = $this->table();
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        $this->db->query(
            "INSERT INTO {$t} (user_email, action, payload_json, fingerprint) VALUES (?, ?, ?, ?)",
            [mb_substr($userEmail, 0, 190), mb_substr($action, 0, 64), $json === false ? '{}' : $json, $fingerprint]
        );
    }

    public function existsRecent(string $fingerprint, int $seconds): bool
    {
        $t = $this->table();
        $row = $this->db->query(
            "SELECT 1 FROM {$t}
             WHERE fingerprint = ? AND created_at >= (NOW() - INTERVAL ? SECOND)
             LIMIT 1",
            [$fingerprint, max(1, $seconds)]
        )->fetch();
        return $row !== false && $row !== null;
    }

    /** DDL shared with Database::createPaydayTables(). */
    public static function ddl(string $table): string
    {
        return "CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            user_email VARCHAR(190) NOT NULL DEFAULT '',
            action VARCHAR(64) NOT NULL,
            payload_json LONGTEXT NOT NULL,
            fingerprint CHAR(64) NULL,
            KEY idx_audit_created (created_at),
            KEY idx_audit_action (action, created_at),
            KEY idx_audit_fingerprint (fingerprint, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    }

    private function table(): string
    {
        $t = $this->db->t('payday_audit_log');
        if (!self::$tableChecked) {
            $this->db->query(self::ddl($t));
            self::$tableChecked = true;
        }
        return $t;
    }
}
