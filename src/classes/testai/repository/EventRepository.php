<?php

declare(strict_types=1);

namespace App\Classes\TestAI\Repository;

use App\Classes\Database;

class EventRepository {
    public function __construct(private Database $db, private string $table) {}

    /**
     * Search upcoming/recent events.
     * Tries FULLTEXT first, falls back to date-range-only query.
     *
     * @return array<array{event_date:string|null, title:string, description:string}>
     */
    public function searchEvents(string $query, int $daysBack = 14): array {
        $since = date('Y-m-d', strtotime("-{$daysBack} days"));
        $query = trim($query);

        if ($query !== '') {
            try {
                $rows = $this->db->query(
                    "SELECT event_date, title, description
                     FROM {$this->table}
                     WHERE (event_date IS NULL OR event_date >= ?)
                       AND MATCH(title, description) AGAINST(? IN BOOLEAN MODE)
                     ORDER BY event_date ASC LIMIT 10",
                    [$since, $query]
                )->fetchAll();
                if (is_array($rows) && count($rows) > 0) return $rows;
            } catch (\Throwable) {}
        }

        // Fallback: most recent events without text filter
        try {
            $rows = $this->db->query(
                "SELECT event_date, title, description
                 FROM {$this->table}
                 WHERE event_date IS NULL OR event_date >= ?
                 ORDER BY event_date ASC LIMIT 10",
                [$since]
            )->fetchAll();
            return is_array($rows) ? $rows : [];
        } catch (\Throwable) {
            return [];
        }
    }

    public function upsert(
        ?string $eventDate,
        string $title,
        string $description,
        ?string $sourceChatId = null,
        ?int $sourceMessageId = null
    ): void {
        try {
            $this->db->query(
                "INSERT INTO {$this->table}
                    (event_date, title, description, source_chat_id, source_message_id)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    description = VALUES(description)",
                [$eventDate, mb_substr($title, 0, 255), $description, $sourceChatId, $sourceMessageId]
            );
        } catch (\Throwable) {}
    }

    public function deleteOlderThan(string $date): void {
        try {
            $this->db->query(
                "DELETE FROM {$this->table} WHERE event_date IS NOT NULL AND event_date < ?",
                [$date]
            );
        } catch (\Throwable) {}
    }
}
