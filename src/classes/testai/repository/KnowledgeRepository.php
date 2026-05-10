<?php

declare(strict_types=1);

namespace App\Classes\TestAI\Repository;

use App\Classes\Database;

class KnowledgeRepository {
    public function __construct(private Database $db, private string $table) {}

    public function list(int $limit = 80, int $offset = 0): array {
        $limit  = max(1, min(200, $limit));
        $offset = max(0, $offset);
        try {
            $rows = $this->db->query(
                "SELECT id, title, source_url, access, is_active, created_at, updated_at
                 FROM {$this->table}
                 ORDER BY updated_at DESC
                 LIMIT {$limit} OFFSET {$offset}"
            )->fetchAll();
            return is_array($rows) ? $rows : [];
        } catch (\Throwable) {
            return [];
        }
    }

    public function getById(int $id): ?array {
        if ($id <= 0) return null;
        try {
            $row = $this->db->query(
                "SELECT id, title, source_url, content, access, is_active, created_at, updated_at
                 FROM {$this->table} WHERE id = ? LIMIT 1",
                [$id]
            )->fetch();
            return is_array($row) ? $row : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Search active KB docs by keywords.
     * access: 'public' always returned; 'members' only when $authorized=true; 'never' never returned.
     */
    public function searchByKeywords(array $keywords, bool $authorized, int $limit = 6): array {
        $limit = max(1, min(12, $limit));

        // normalize keywords
        $kw = [];
        foreach ($keywords as $k) {
            $t = trim(mb_strtolower((string)$k));
            if ($t !== '' && mb_strlen($t) >= 2) $kw[$t] = true;
        }
        if (!$kw) return [];

        $conds  = [];
        $params = [];
        foreach (array_keys($kw) as $k) {
            $like     = '%' . $k . '%';
            $conds[]  = '(LOWER(title) LIKE ? OR LOWER(content) LIKE ?)';
            $params[] = $like;
            $params[] = $like;
        }
        $where = implode(' OR ', $conds);

        $accessCond = $authorized
            ? "access IN ('public', 'members')"
            : "access = 'public'";

        try {
            $rows = $this->db->query(
                "SELECT id, title, source_url, content, access, updated_at
                 FROM {$this->table}
                 WHERE is_active = 1 AND {$accessCond} AND ({$where})
                 ORDER BY updated_at DESC
                 LIMIT {$limit}",
                $params
            )->fetchAll();
            return is_array($rows) ? $rows : [];
        } catch (\Throwable) {
            return [];
        }
    }

    public function upsert(?int $id, string $title, string $content, string $sourceUrl, string $access, int $isActive): int {
        $title     = mb_substr(trim($title) ?: 'Untitled', 0, 255);
        $sourceUrl = mb_substr(trim($sourceUrl), 0, 512);
        $access    = in_array($access, ['public', 'members', 'never'], true) ? $access : 'public';
        $content   = trim($content);
        $isActive  = $isActive ? 1 : 0;

        try {
            if ($id !== null && $id > 0) {
                $this->db->query(
                    "UPDATE {$this->table}
                     SET title = ?, source_url = NULLIF(?, ''), content = ?, access = ?, is_active = ?
                     WHERE id = ? LIMIT 1",
                    [$title, $sourceUrl, $content, $access, $isActive, $id]
                );
                return $id;
            }
            $this->db->query(
                "INSERT INTO {$this->table} (title, source_url, content, access, is_active)
                 VALUES (?, NULLIF(?, ''), ?, ?, ?)",
                [$title, $sourceUrl, $content, $access, $isActive]
            );
            $pdo = $this->db->getPdo();
            return $pdo ? (int)$pdo->lastInsertId() : 0;
        } catch (\Throwable) {
            return 0;
        }
    }

    public function delete(int $id): bool {
        if ($id <= 0) return false;
        try {
            $this->db->query("DELETE FROM {$this->table} WHERE id = ? LIMIT 1", [$id]);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
