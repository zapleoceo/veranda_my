<?php

declare(strict_types=1);

namespace App\Classes\TestAI\Repository;

use App\Classes\Database;

class DailyRepository {
    public function __construct(private Database $db, private string $table) {}

    public function getByDay(string $day): ?array {
        try {
            $row = $this->db->query(
                "SELECT day, summary_text, events_json, created_at FROM {$this->table} WHERE day = ? LIMIT 1",
                [$day]
            )->fetch();
            return is_array($row) ? $row : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function upsert(string $day, string $summaryText, string $eventsJson, string $createdAt): void {
        try {
            $this->db->query(
                "INSERT INTO {$this->table} (day, summary_text, events_json, created_at)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE summary_text = VALUES(summary_text), events_json = VALUES(events_json), created_at = VALUES(created_at)",
                [$day, $summaryText, $eventsJson, $createdAt]
            );
        } catch (\Throwable) {}
    }

    public function listSince(string $since): array {
        try {
            $rows = $this->db->query(
                "SELECT day, events_json FROM {$this->table} WHERE created_at >= ? ORDER BY day ASC",
                [$since]
            )->fetchAll();
            return is_array($rows) ? $rows : [];
        } catch (\Throwable) {
            return [];
        }
    }
}
