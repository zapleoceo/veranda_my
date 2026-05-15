<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Infrastructure\Database;

class MetaRepository
{
    public function __construct(private readonly Database $db) {}

    public function getMany(array $keys): array
    {
        $keys = array_values(array_unique(array_filter(
            array_map(fn($v) => trim((string) $v), $keys),
            fn($v) => $v !== ''
        )));

        if ($keys === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $table = $this->db->t('system_meta');

        try {
            $rows = $this->db->query(
                "SELECT meta_key, meta_value FROM {$table} WHERE meta_key IN ({$placeholders})",
                $keys
            )->fetchAll();
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $k = (string) ($row['meta_key'] ?? '');
            if ($k !== '') {
                $out[$k] = (string) ($row['meta_value'] ?? '');
            }
        }
        return $out;
    }

    public function get(string $key, string $default = ''): string
    {
        $result = $this->getMany([$key]);
        return $result[$key] ?? $default;
    }

    public function set(string $key, string $value): void
    {
        $key = trim($key);
        if ($key === '') {
            return;
        }
        $table = $this->db->t('system_meta');
        $this->db->query(
            "INSERT INTO {$table} (meta_key, meta_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value), updated_at = CURRENT_TIMESTAMP",
            [$key, $value]
        );
    }

    public function setMany(array $pairs): void
    {
        foreach ($pairs as $key => $value) {
            $this->set((string) $key, (string) $value);
        }
    }
}
