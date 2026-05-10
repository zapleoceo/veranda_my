<?php

declare(strict_types=1);

namespace App\Classes\TestAI\Repository;

use App\Classes\Database;

class SettingsRepository {
    public function __construct(private Database $db, private string $table) {}

    public function get(string $key): string {
        try {
            $row = $this->db->query(
                "SELECT v FROM {$this->table} WHERE k = ? LIMIT 1",
                [$key]
            )->fetch();
            return is_array($row) ? (string)($row['v'] ?? '') : '';
        } catch (\Throwable) {
            return '';
        }
    }

    public function getWithMeta(string $key): array {
        try {
            $row = $this->db->query(
                "SELECT v, updated_at FROM {$this->table} WHERE k = ? LIMIT 1",
                [$key]
            )->fetch();
            return is_array($row) ? $row : ['v' => '', 'updated_at' => ''];
        } catch (\Throwable) {
            return ['v' => '', 'updated_at' => ''];
        }
    }

    public function set(string $key, string $value): void {
        try {
            $this->db->query(
                "INSERT INTO {$this->table} (k, v, updated_at) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE v = VALUES(v), updated_at = VALUES(updated_at)",
                [$key, $value, date('Y-m-d H:i:s')]
            );
        } catch (\Throwable) {}
    }

    public function setIfEmpty(string $key, string $value): void {
        if ($this->get($key) === '') $this->set($key, $value);
    }
}
