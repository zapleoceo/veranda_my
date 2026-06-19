<?php

declare(strict_types=1);

namespace App\Bloggers\Repositories;

use App\Bloggers\Contracts\BloggerRepositoryInterface;
use App\Infrastructure\Database;

/**
 * Local `bloggers` table (cashback %, active flag) plus the module config
 * (group id + payout finance-category id), stored as two `system_meta` keys.
 * Self-creates the `bloggers` table on first use.
 *
 * Blogger email is NOT stored locally — it lives on the Poster client record
 * and is looked up from there during Google OAuth.
 */
final class BloggerRepository implements BloggerRepositoryInterface
{
    public const DEFAULT_GROUP_ID    = 10; // "Blogers" client group
    public const DEFAULT_CATEGORY_ID = 24; // "Bloggers" finance category

    private const META_GROUP    = 'bloggers_group_id';
    private const META_CATEGORY = 'bloggers_payout_category_id';

    private static bool $schemaReady = false;

    public function __construct(private readonly Database $db)
    {
        $this->ensureSchema();
    }

    public function allByClientId(): array
    {
        $rows = $this->db->query(
            "SELECT poster_client_id, cashback_pct, is_active, created_by FROM {$this->db->t('bloggers')}"
        )->fetchAll();

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['poster_client_id']] = [
                'cashback_pct' => (float) $r['cashback_pct'],
                'is_active'    => (int) $r['is_active'],
                'created_by'   => (string) $r['created_by'],
            ];
        }
        return $out;
    }

    public function create(int $clientId, float $cashbackPct, string $createdBy): void
    {
        $this->db->query(
            "INSERT INTO {$this->db->t('bloggers')} (poster_client_id, cashback_pct, is_active, created_by)
             VALUES (?, ?, 1, ?)
             ON DUPLICATE KEY UPDATE cashback_pct = VALUES(cashback_pct), is_active = 1",
            [$clientId, $cashbackPct, $createdBy]
        );
    }

    public function saveCashback(int $clientId, float $cashbackPct): void
    {
        $this->db->query(
            "INSERT INTO {$this->db->t('bloggers')} (poster_client_id, cashback_pct, created_by)
             VALUES (?, ?, '')
             ON DUPLICATE KEY UPDATE cashback_pct = VALUES(cashback_pct)",
            [$clientId, $cashbackPct]
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

    public function loadConfig(): array
    {
        $m   = $this->db->t('system_meta');
        $kv  = [];
        try {
            $rows = $this->db->query(
                "SELECT meta_key, meta_value FROM {$m} WHERE meta_key IN (?, ?)",
                [self::META_GROUP, self::META_CATEGORY]
            )->fetchAll();
            foreach ($rows as $r) {
                $kv[(string) $r['meta_key']] = (string) $r['meta_value'];
            }
        } catch (\Throwable) {
            // system_meta missing → fall back to defaults
        }

        $group    = (int) ($kv[self::META_GROUP] ?? 0);
        $category = (int) ($kv[self::META_CATEGORY] ?? 0);
        return [
            'group_id'           => $group    > 0 ? $group    : self::DEFAULT_GROUP_ID,
            'payout_category_id' => $category > 0 ? $category : self::DEFAULT_CATEGORY_ID,
        ];
    }

    public function saveConfig(int $groupId, int $payoutCategoryId): void
    {
        $m = $this->db->t('system_meta');
        foreach ([[self::META_GROUP, $groupId], [self::META_CATEGORY, $payoutCategoryId]] as [$key, $val]) {
            $this->db->query(
                "INSERT INTO {$m} (meta_key, meta_value) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)",
                [$key, (string) $val]
            );
        }
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
