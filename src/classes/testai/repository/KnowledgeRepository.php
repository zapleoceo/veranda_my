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
                "SELECT id, title, source_url, access, category, tags, is_active, created_at, updated_at
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
                "SELECT id, title, source_url, content, access, category, tags, is_active, created_at, updated_at
                 FROM {$this->table} WHERE id = ? LIMIT 1",
                [$id]
            )->fetch();
            return is_array($row) ? $row : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * FULLTEXT search with LIKE fallback. Used by ToolDispatcher.
     */
    public function searchFulltext(string $query, bool $authorized, int $limit = 6): array {
        $limit      = max(1, min(12, $limit));
        $accessCond = $authorized ? "access IN ('public','members')" : "access = 'public'";
        $query      = trim($query);
        if ($query === '') return [];

        try {
            $rows = $this->db->query(
                "SELECT id, title, source_url, content, access, category, updated_at
                 FROM {$this->table}
                 WHERE is_active = 1 AND {$accessCond}
                   AND MATCH(title, content) AGAINST(? IN BOOLEAN MODE)
                 ORDER BY updated_at DESC LIMIT {$limit}",
                [$query]
            )->fetchAll();
            if (is_array($rows) && count($rows) > 0) return $rows;
        } catch (\Throwable) {}

        // Fallback: LIKE
        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($query)) . '%';
        try {
            $rows = $this->db->query(
                "SELECT id, title, source_url, content, access, category, updated_at
                 FROM {$this->table}
                 WHERE is_active = 1 AND {$accessCond}
                   AND (LOWER(title) LIKE ? OR LOWER(content) LIKE ?)
                 ORDER BY updated_at DESC LIMIT {$limit}",
                [$like, $like]
            )->fetchAll();
            return is_array($rows) ? $rows : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Keyword LIKE search (legacy, used by KnowledgeService::search).
     */
    public function searchByKeywords(array $keywords, bool $authorized, int $limit = 6): array {
        $limit = max(1, min(12, $limit));

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
                "SELECT id, title, source_url, content, access, category, updated_at
                 FROM {$this->table}
                 WHERE is_active = 1 AND {$accessCond} AND ({$where})
                 ORDER BY updated_at DESC LIMIT {$limit}",
                $params
            )->fetchAll();
            return is_array($rows) ? $rows : [];
        } catch (\Throwable) {
            return [];
        }
    }

    public function upsert(
        ?int $id,
        string $title,
        string $content,
        string $sourceUrl,
        string $access,
        int $isActive,
        string $category = 'other',
        string $tags = ''
    ): int {
        $title     = mb_substr(trim($title) ?: 'Untitled', 0, 255);
        $sourceUrl = mb_substr(trim($sourceUrl), 0, 512);
        $access    = in_array($access, ['public', 'members', 'never'], true) ? $access : 'public';
        $category  = mb_substr(trim($category) ?: 'other', 0, 64);
        $content   = trim($content);
        $isActive  = $isActive ? 1 : 0;
        $tagsVal   = trim($tags) !== '' ? trim($tags) : null;

        try {
            if ($id !== null && $id > 0) {
                $this->db->query(
                    "UPDATE {$this->table}
                     SET title = ?, source_url = NULLIF(?, ''), content = ?, access = ?,
                         category = ?, tags = ?, is_active = ?
                     WHERE id = ? LIMIT 1",
                    [$title, $sourceUrl, $content, $access, $category, $tagsVal, $isActive, $id]
                );
                return $id;
            }
            $this->db->query(
                "INSERT INTO {$this->table} (title, source_url, content, access, category, tags, is_active)
                 VALUES (?, NULLIF(?, ''), ?, ?, ?, ?, ?)",
                [$title, $sourceUrl, $content, $access, $category, $tagsVal, $isActive]
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
