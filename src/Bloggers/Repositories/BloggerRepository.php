<?php

declare(strict_types=1);

namespace App\Bloggers\Repositories;

use App\Bloggers\Contracts\BloggerRepositoryInterface;
use App\Infrastructure\Database;

/**
 * Local `bloggers` table: cashback %, gmail↔client link, active flag.
 * Self-creates its schema on first use (same idempotent pattern as the
 * Schedule / PosterApp modules), so there is no separate migration step.
 */
final class BloggerRepository implements BloggerRepositoryInterface
{
    private static bool $schemaReady = false;

    public function __construct(private readonly Database $db)
    {
        $this->ensureSchema();
    }

    public function allByClientId(): array
    {
        $rows = $this->db->query(
            "SELECT poster_client_id, gmail, cashback_pct, is_active, created_by FROM {$this->db->t('bloggers')}"
        )->fetchAll();

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['poster_client_id']] = [
                'cashback_pct' => (float) $r['cashback_pct'],
                'gmail'        => (string) $r['gmail'],
                'is_active'    => (int) $r['is_active'],
                'created_by'   => (string) $r['created_by'],
            ];
        }
        return $out;
    }

    public function create(int $clientId, string $gmail, float $cashbackPct, string $createdBy): void
    {
        $this->db->query(
            "INSERT INTO {$this->db->t('bloggers')} (poster_client_id, gmail, cashback_pct, is_active, created_by)
             VALUES (?, ?, ?, 1, ?)
             ON DUPLICATE KEY UPDATE gmail = VALUES(gmail), cashback_pct = VALUES(cashback_pct), is_active = 1",
            [$clientId, $gmail, $cashbackPct, $createdBy]
        );
    }

    public function saveCashbackAndGmail(int $clientId, string $gmail, float $cashbackPct): void
    {
        // The row may not exist yet if the blogger was created directly in
        // Poster — upsert keeps cashback/gmail in sync without clobbering
        // is_active / created_by.
        $this->db->query(
            "INSERT INTO {$this->db->t('bloggers')} (poster_client_id, gmail, cashback_pct, created_by)
             VALUES (?, ?, ?, '')
             ON DUPLICATE KEY UPDATE gmail = VALUES(gmail), cashback_pct = VALUES(cashback_pct)",
            [$clientId, $gmail, $cashbackPct]
        );
    }

    public function setActive(int $clientId, bool $active): void
    {
        $this->db->query(
            "INSERT INTO {$this->db->t('bloggers')} (poster_client_id, is_active) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE is_active = VALUES(is_active)",
            [$clientId, $active ? 1 : 0]
        );
    }

    private function ensureSchema(): void
    {
        if (self::$schemaReady) {
            return;
        }
        $t = $this->db->t('bloggers');
        $this->db->query(
            "CREATE TABLE IF NOT EXISTS {$t} (
                id               INT UNSIGNED   NOT NULL AUTO_INCREMENT,
                poster_client_id INT UNSIGNED   NOT NULL,
                gmail            VARCHAR(255)   NOT NULL DEFAULT '',
                cashback_pct     DECIMAL(5,2)   NOT NULL DEFAULT 0,
                is_active        TINYINT(1)     NOT NULL DEFAULT 1,
                created_by       VARCHAR(255)   NOT NULL DEFAULT '',
                created_at       TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at       TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_poster_client (poster_client_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        self::$schemaReady = true;
    }
}
