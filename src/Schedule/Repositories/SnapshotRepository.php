<?php

declare(strict_types=1);

namespace App\Schedule\Repositories;

use App\Infrastructure\Database;
use App\Schedule\Contracts\SnapshotRepositoryInterface;

final class SnapshotRepository implements SnapshotRepositoryInterface
{
    private const TABLE = 'schedule_snapshots';

    public function __construct(private readonly Database $db)
    {
        $this->ensureTable();
    }

    public function loadCurrent(): ?array
    {
        $t = $this->db->t(self::TABLE);
        // Prefer the single is_current=1 row (the draft). Fall back to the
        // latest by id to survive legacy DBs where no row was ever marked
        // as current.
        $row = $this->db->query(
            "SELECT json_data FROM {$t} WHERE is_current = 1 ORDER BY id DESC LIMIT 1"
        )->fetch();
        if (!$row) {
            $row = $this->db->query("SELECT json_data FROM {$t} ORDER BY id DESC LIMIT 1")->fetch();
        }
        if (!$row || empty($row['json_data'])) return null;
        $decoded = json_decode((string) $row['json_data'], true);
        return is_array($decoded) ? $decoded : null;
    }

    public function saveCurrent(array $state, string $email): int
    {
        $t       = $this->db->t(self::TABLE);
        $payload = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $row     = $this->db->query("SELECT id FROM {$t} WHERE is_current = 1 ORDER BY id DESC LIMIT 1")->fetch();
        if ($row) {
            $id = (int) $row['id'];
            $this->db->query(
                "UPDATE {$t} SET json_data = ?, label = '', created_by = ?, created_at = CURRENT_TIMESTAMP WHERE id = ?",
                [$payload, $email, $id]
            );
            // Also ensure no other rows accidentally claim is_current — single-row invariant.
            $this->db->query("UPDATE {$t} SET is_current = 0 WHERE id <> ?", [$id]);
            return $id;
        }
        $this->db->query(
            "INSERT INTO {$t} (label, json_data, is_current, created_by) VALUES ('', ?, 1, ?)",
            [$payload, $email]
        );
        return (int) $this->db->lastInsertId();
    }

    public function saveNamedVersion(array $state, string $label, string $email): int
    {
        $label = mb_substr(trim($label), 0, 100, 'UTF-8');
        if ($label === '') {
            throw new \InvalidArgumentException('Named version requires a non-empty label');
        }
        $t = $this->db->t(self::TABLE);
        $this->db->query(
            "INSERT INTO {$t} (label, json_data, is_current, created_by) VALUES (?, ?, 0, ?)",
            [
                $label,
                json_encode($state, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                $email,
            ]
        );
        return (int) $this->db->lastInsertId();
    }

    public function rename(int $id, string $label): bool
    {
        $label = mb_substr(trim($label), 0, 100, 'UTF-8');
        if ($id <= 0 || $label === '') return false;
        $t = $this->db->t(self::TABLE);
        $row = $this->db->query("SELECT is_current FROM {$t} WHERE id = ? LIMIT 1", [$id])->fetch();
        if (!$row || (int) ($row['is_current'] ?? 0) === 1) return false;
        $this->db->query("UPDATE {$t} SET label = ? WHERE id = ?", [$label, $id]);
        return true;
    }

    public function listRecent(int $limit = 25): array
    {
        $limit = max(1, min(100, $limit));
        $t = $this->db->t(self::TABLE);
        // Named versions only. Exclude:
        //   • the draft row (is_current=1)
        //   • empty labels (would mean an orphaned draft)
        //   • legacy auto-save / manual / restored-N labels from before
        //     the model split — they're noise in the UI now
        $rows = $this->db->query(
            "SELECT id, label, is_current, created_at, created_by
             FROM {$t}
             WHERE is_current = 0
               AND label <> ''
               AND label NOT IN ('auto', 'manual')
               AND label NOT LIKE 'restored-%'
             ORDER BY id DESC LIMIT {$limit}"
        )->fetchAll();
        return array_map(static fn($r) => [
            'id'         => (int) $r['id'],
            'label'      => (string) ($r['label'] ?? ''),
            'is_current' => false,
            'created_at' => (string) ($r['created_at'] ?? ''),
            'created_by' => (string) ($r['created_by'] ?? ''),
        ], $rows ?: []);
    }

    public function loadById(int $id): ?array
    {
        $t = $this->db->t(self::TABLE);
        $row = $this->db->query("SELECT json_data FROM {$t} WHERE id = ? LIMIT 1", [$id])->fetch();
        if (!$row || empty($row['json_data'])) return null;
        $decoded = json_decode((string) $row['json_data'], true);
        return is_array($decoded) ? $decoded : null;
    }

    public function delete(int $id): bool
    {
        $t = $this->db->t(self::TABLE);
        $row = $this->db->query("SELECT is_current FROM {$t} WHERE id = ? LIMIT 1", [$id])->fetch();
        if (!$row || (int) ($row['is_current'] ?? 0) === 1) return false;
        $this->db->query("DELETE FROM {$t} WHERE id = ?", [$id]);
        return true;
    }

    private function ensureTable(): void
    {
        $t = $this->db->t(self::TABLE);
        $this->db->query("
            CREATE TABLE IF NOT EXISTS {$t} (
                id          INT AUTO_INCREMENT PRIMARY KEY,
                label       VARCHAR(100) NOT NULL DEFAULT '',
                json_data   MEDIUMTEXT NOT NULL,
                is_current  TINYINT(1) NOT NULL DEFAULT 0,
                created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                created_by  VARCHAR(255) NOT NULL DEFAULT '',
                KEY idx_current (is_current),
                KEY idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
}
