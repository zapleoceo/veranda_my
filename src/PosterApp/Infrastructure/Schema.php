<?php

declare(strict_types=1);

namespace App\PosterApp\Infrastructure;

use App\Infrastructure\Database;

/**
 * Idempotent DDL bootstrap for PosterApp tables. Same pattern as
 * Schedule\SchemaManager: gated by a version stamp in `system_meta`,
 * short-circuits per process via a static flag, swallows any failure
 * so a transient DDL error doesn't take the whole page down.
 *
 * Tables:
 *   neworder_employee_pin   — hashed PIN per Poster user_id
 *   neworder_work_shift     — open/close timesheet windows
 */
final class Schema
{
    private const VERSION = '1';                  // bump when adding columns
    private const META_KEY = 'poster_app_schema_v';

    private static bool $checkedThisRequest = false;

    public function __construct(private readonly Database $db) {}

    public function ensure(): void
    {
        if (self::$checkedThisRequest) return;
        self::$checkedThisRequest = true;

        try {
            try {
                if ($this->readVersion() === self::VERSION) return;
            } catch (\Throwable) {
                // system_meta missing on a fresh install — proceed.
            }

            $this->createEmployeePins();
            $this->createWorkShifts();

            try { $this->writeVersion(); }
            catch (\Throwable) { /* meta table not writable — next request retries */ }
        } catch (\Throwable) {
            // Never break a request because schema bootstrap hiccuped.
        }
    }

    private function readVersion(): string
    {
        $t   = $this->db->t('system_meta');
        $row = $this->db->query(
            "SELECT meta_value FROM {$t} WHERE meta_key = ? LIMIT 1",
            [self::META_KEY],
        )->fetch();
        return (string)($row['meta_value'] ?? '');
    }

    private function writeVersion(): void
    {
        $t = $this->db->t('system_meta');
        $this->db->query(
            "REPLACE INTO {$t} (meta_key, meta_value) VALUES (?, ?)",
            [self::META_KEY, self::VERSION],
        );
    }

    private function createEmployeePins(): void
    {
        $t = $this->db->t('neworder_employee_pin');
        $this->db->query("
            CREATE TABLE IF NOT EXISTS {$t} (
                poster_user_id  INT          NOT NULL PRIMARY KEY,
                pin_hash        CHAR(60)     NOT NULL,
                display_name    VARCHAR(255) NOT NULL DEFAULT '',
                is_admin        TINYINT(1)   NOT NULL DEFAULT 0,
                last_seen_at    DATETIME     NULL,
                updated_at      TIMESTAMP    NOT NULL
                                DEFAULT CURRENT_TIMESTAMP
                                ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    private function createWorkShifts(): void
    {
        $t = $this->db->t('neworder_work_shift');
        $this->db->query("
            CREATE TABLE IF NOT EXISTS {$t} (
                id              INT AUTO_INCREMENT PRIMARY KEY,
                poster_user_id  INT       NOT NULL,
                poster_shift_id INT       NULL,
                started_at      DATETIME  NOT NULL,
                ended_at        DATETIME  NULL,
                source          VARCHAR(40) NOT NULL DEFAULT 'pos_widget',
                KEY idx_user_started (poster_user_id, started_at),
                KEY idx_ended (ended_at),
                KEY idx_poster_shift (poster_shift_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
}
