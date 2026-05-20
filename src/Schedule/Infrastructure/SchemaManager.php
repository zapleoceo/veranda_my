<?php

declare(strict_types=1);

namespace App\Schedule\Infrastructure;

use App\Infrastructure\Database;

/**
 * Idempotent schema bootstrapper for ALL schedule-related tables.
 *
 * Replaces the per-request `ensureTable()` calls that each repository
 * used to do in its constructor (4× CREATE TABLE round-trips on every
 * request — wasteful). Gated by a version stamp in `system_meta`
 * (key 'schedule_schema_v') so the DDL block runs at most once per
 * deploy + once-per-process (the static `$checkedThisRequest` short-
 * circuits after the first ensure() call).
 *
 * Adding a column / table:
 *   1. Update the relevant create*() method below.
 *   2. Bump VERSION.
 *   On the next request after deploy SchemaManager re-runs CREATE
 *   TABLE IF NOT EXISTS / ALTER TABLE / backfills, then writes the
 *   new version stamp. Subsequent requests skip the DDL entirely.
 */
final class SchemaManager
{
    private const VERSION = '2';   // bump when adding tables/columns/backfills

    private static bool $checkedThisRequest = false;

    public function __construct(private readonly Database $db) {}

    public function ensure(): void
    {
        if (self::$checkedThisRequest) return;
        self::$checkedThisRequest = true;

        // The whole bootstrap is best-effort. Any failure (e.g. missing
        // system_meta table, denied ALTER privilege, transient lock) is
        // swallowed — the page MUST render even when DDL can't run.
        // Tables we CREATE IF NOT EXISTS will just be retried on the
        // next request.
        try {
            try {
                if ($this->readVersion() === self::VERSION) return;
            } catch (\Throwable) {
                // system_meta missing on a brand-new install — proceed.
            }

            $this->createSnapshots();
            $this->createZones();
            $this->createStaffTags();
            $this->createEmployeeRates();
            $this->backfillShareCodes();

            try {
                $this->writeVersion();
            } catch (\Throwable) {
                // system_meta isn't writable — fine, just skip the stamp;
                // next request will re-run DDL (cheap because CREATE TABLE
                // IF NOT EXISTS is a metadata-only no-op when up to date).
            }
        } catch (\Throwable) {
            // Any other surprise — never let schema bootstrap take the
            // whole page down. Errors get logged elsewhere via the Slim
            // error middleware if they recur in business logic.
        }
    }

    // ─── persistence of the schema version ───────────────────────────
    private function readVersion(): string
    {
        $t   = $this->db->t('system_meta');
        $row = $this->db->query(
            "SELECT meta_value FROM {$t} WHERE meta_key = ? LIMIT 1",
            ['schedule_schema_v']
        )->fetch();
        return (string) ($row['meta_value'] ?? '');
    }
    private function writeVersion(): void
    {
        $t = $this->db->t('system_meta');
        $this->db->query(
            "REPLACE INTO {$t} (meta_key, meta_value) VALUES (?, ?)",
            ['schedule_schema_v', self::VERSION]
        );
    }

    // ─── DDL ─────────────────────────────────────────────────────────
    private function createSnapshots(): void
    {
        $t = $this->db->t('schedule_snapshots');
        $this->db->query("
            CREATE TABLE IF NOT EXISTS {$t} (
                id          INT AUTO_INCREMENT PRIMARY KEY,
                label       VARCHAR(100) NOT NULL DEFAULT '',
                json_data   MEDIUMTEXT NOT NULL,
                is_current  TINYINT(1) NOT NULL DEFAULT 0,
                created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                created_by  VARCHAR(255) NOT NULL DEFAULT '',
                share_code  VARCHAR(32) DEFAULT NULL,
                UNIQUE KEY uniq_share (share_code),
                KEY idx_current (is_current),
                KEY idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        // Legacy installs from before share_code existed — swallow if
        // already there. Runs at most once per VERSION bump.
        try { $this->db->query("ALTER TABLE {$t} ADD COLUMN share_code VARCHAR(32) DEFAULT NULL"); } catch (\Throwable) {}
        try { $this->db->query("ALTER TABLE {$t} ADD UNIQUE KEY uniq_share (share_code)"); } catch (\Throwable) {}
    }

    private function createZones(): void
    {
        $t = $this->db->t('schedule_zones');
        $this->db->query("
            CREATE TABLE IF NOT EXISTS {$t} (
                id          INT AUTO_INCREMENT PRIMARY KEY,
                name        VARCHAR(100) NOT NULL,
                icon        VARCHAR(10)  NOT NULL DEFAULT '🌿',
                sort_order  INT          NOT NULL DEFAULT 0,
                is_active   TINYINT(1)   NOT NULL DEFAULT 1
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    private function createStaffTags(): void
    {
        $t = $this->db->t('schedule_staff_tags');
        $this->db->query("
            CREATE TABLE IF NOT EXISTS {$t} (
                user_id          INT PRIMARY KEY,
                in_schedule      TINYINT(1)   NOT NULL DEFAULT 1,
                can_be_senior    TINYINT(1)   NOT NULL DEFAULT 0,
                only_in_blocks   VARCHAR(255) NOT NULL DEFAULT '',
                custom_tag       VARCHAR(50)  NOT NULL DEFAULT '',
                updated_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    private function createEmployeeRates(): void
    {
        $t = $this->db->t('employee_rates');
        $this->db->query("
            CREATE TABLE IF NOT EXISTS {$t} (
                user_id    INT NOT NULL,
                rate       BIGINT NOT NULL DEFAULT 0,
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                updated_by VARCHAR(255) NULL,
                PRIMARY KEY (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    /**
     * One-time backfill of `share_code` on legacy named-version rows.
     * Used to live in SnapshotRepository::listRecent (write-on-read
     * anti-pattern, ran on every list call). Now scoped to schema-
     * version bumps.
     */
    private function backfillShareCodes(): void
    {
        $t   = $this->db->t('schedule_snapshots');
        $rows = $this->db->query(
            "SELECT id FROM {$t} WHERE is_current = 0 AND share_code IS NULL"
        )->fetchAll();
        foreach ($rows ?: [] as $r) {
            $code = ShareCode::generateUnique($this->db, $t);
            $this->db->query("UPDATE {$t} SET share_code = ? WHERE id = ?", [$code, (int) $r['id']]);
        }
    }
}
