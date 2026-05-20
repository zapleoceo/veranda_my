<?php

declare(strict_types=1);

namespace App\Schedule\Repositories;

use App\Infrastructure\Database;

/**
 * Simple key→value cache backed by `system_meta`. Each value is JSON-encoded,
 * each read checks a TTL against the row's updated_at timestamp.
 *
 * No interface — it's an infrastructure utility used by providers internally.
 */
final class MetaCache
{
    public function __construct(private readonly Database $db) {}

    public function get(string $key, int $ttlSeconds): mixed
    {
        try {
            $t = $this->db->t('system_meta');
            $row = $this->db->query(
                "SELECT meta_value, updated_at FROM {$t} WHERE meta_key = ? LIMIT 1",
                [$key]
            )->fetch();
            if (!$row || empty($row['meta_value'])) return null;
            $age = time() - (int) strtotime((string) $row['updated_at']);
            if ($age > $ttlSeconds) return null;
            $decoded = json_decode((string) $row['meta_value'], true);
            return is_array($decoded) ? $decoded : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function set(string $key, mixed $value): void
    {
        try {
            $t = $this->db->t('system_meta');
            $this->db->query(
                "INSERT INTO {$t} (meta_key, meta_value) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value), updated_at = CURRENT_TIMESTAMP",
                [$key, json_encode($value, JSON_UNESCAPED_UNICODE)]
            );
        } catch (\Throwable) {
        }
    }

    public function purge(array $keys): void
    {
        if (empty($keys)) return;
        try {
            $t = $this->db->t('system_meta');
            $placeholders = implode(',', array_fill(0, count($keys), '?'));
            $this->db->query("DELETE FROM {$t} WHERE meta_key IN ({$placeholders})", $keys);
        } catch (\Throwable) {
        }
    }
}
