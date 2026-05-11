<?php

declare(strict_types=1);

namespace App\Classes\TestAI\Repository;

use App\Classes\Database;

class MessageRepository {
    public function __construct(private Database $db, private string $table) {}

    public function upsert(array $m): void {
        try {
            $this->db->query(
                "INSERT INTO {$this->table}
                    (tg_chat_id, tg_chat_type, tg_chat_title, tg_message_id, tg_user_id,
                     tg_username, tg_name, received_at, text,
                     media_type, media_file_id, media_file_unique_id, media_mime, media_duration_sec, media_text, meta_json, importance)
                 VALUES (?, ?, NULLIF(?, ''), ?, ?, NULLIF(?, ''), NULLIF(?, ''), ?, ?,
                         NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''), ?, NULLIF(?, ''), ?, ?)
                 ON DUPLICATE KEY UPDATE
                    tg_chat_type            = VALUES(tg_chat_type),
                    tg_chat_title           = VALUES(tg_chat_title),
                    tg_user_id              = VALUES(tg_user_id),
                    tg_username             = VALUES(tg_username),
                    tg_name                 = VALUES(tg_name),
                    received_at             = VALUES(received_at),
                    text                    = VALUES(text),
                    media_type              = VALUES(media_type),
                    media_file_id           = VALUES(media_file_id),
                    media_file_unique_id    = VALUES(media_file_unique_id),
                    media_mime              = VALUES(media_mime),
                    media_duration_sec      = VALUES(media_duration_sec),
                    media_text              = IF(VALUES(media_text) IS NULL OR VALUES(media_text) = '', media_text, VALUES(media_text)),
                    meta_json               = VALUES(meta_json)",
                [
                    $m['chat_id'], $m['chat_type'], $m['chat_title'], $m['message_id'], $m['user_id'],
                    $m['username'], $m['name'], $m['received_at'], $m['text'],
                    $m['media_type'], $m['media_file_id'], $m['media_file_unique_id'],
                    $m['media_mime'], $m['media_duration_sec'], $m['media_text'], $m['meta_json'],
                    (int)($m['importance'] ?? 5),
                ]
            );
        } catch (\Throwable) {}
    }

    public function updateMediaText(string $chatId, int $messageId, string $text): void {
        try {
            $this->db->query(
                "UPDATE {$this->table} SET media_text = ? WHERE tg_chat_id = ? AND tg_message_id = ? LIMIT 1",
                [$text, $chatId, $messageId]
            );
        } catch (\Throwable) {}
    }

    public function fetchRecentByChat(string $chatId, int $limit = 30): array {
        $limit = max(1, min(100, $limit));
        try {
            $rows = $this->db->query(
                "SELECT tg_username, tg_name, received_at, text, media_text
                 FROM {$this->table}
                 WHERE tg_chat_id = ?
                 ORDER BY received_at DESC
                 LIMIT {$limit}",
                [$chatId]
            )->fetchAll();
            return is_array($rows) ? $rows : [];
        } catch (\Throwable) {
            return [];
        }
    }

    public function fetchForRange(string $from, string $to): array {
        try {
            $rows = $this->db->query(
                "SELECT tg_chat_id, tg_message_id, tg_chat_title, tg_username, tg_name, received_at, text, media_text
                 FROM {$this->table}
                 WHERE received_at BETWEEN ? AND ?
                 ORDER BY received_at ASC",
                [$from, $to]
            )->fetchAll();
            return is_array($rows) ? $rows : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * FULLTEXT search across recent messages.
     * Used by ToolDispatcher when searching for events/announcements.
     *
     * @return array<array{tg_chat_id:string, tg_message_id:int, tg_username:string, received_at:string, text:string, media_text:string|null}>
     */
    public function searchFulltext(string $query, int $daysBack = 14, int $limit = 15): array {
        $limit = max(1, min(50, $limit));
        $since = date('Y-m-d H:i:s', strtotime("-{$daysBack} days"));
        $query = trim($query);

        if ($query !== '') {
            try {
                $rows = $this->db->query(
                    "SELECT tg_chat_id, tg_message_id, tg_username, tg_name, received_at, text, media_text
                     FROM {$this->table}
                     WHERE received_at >= ?
                       AND MATCH(text, media_text) AGAINST(? IN BOOLEAN MODE)
                     ORDER BY received_at DESC LIMIT {$limit}",
                    [$since, $query]
                )->fetchAll();
                if (is_array($rows) && count($rows) > 0) return $rows;
            } catch (\Throwable) {}

            // Fallback: LIKE search
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $query) . '%';
            try {
                $rows = $this->db->query(
                    "SELECT tg_chat_id, tg_message_id, tg_username, tg_name, received_at, text, media_text
                     FROM {$this->table}
                     WHERE received_at >= ? AND (text LIKE ? OR media_text LIKE ?)
                     ORDER BY received_at DESC LIMIT {$limit}",
                    [$since, $like, $like]
                )->fetchAll();
                return is_array($rows) ? $rows : [];
            } catch (\Throwable) {}
        }

        return [];
    }

    public function getTotals(): array {
        try {
            $row = $this->db->query(
                "SELECT COUNT(*) AS raw_total, MAX(received_at) AS raw_last_received_at FROM {$this->table}"
            )->fetch();
            return is_array($row) ? $row : [];
        } catch (\Throwable) {
            return [];
        }
    }

    public function getCountsForDay(string $day): array {
        try {
            $row = $this->db->query(
                "SELECT COUNT(*) AS count,
                        SUM(media_type IS NOT NULL) AS with_media,
                        SUM(media_text IS NOT NULL AND media_text != '') AS with_media_text
                 FROM {$this->table}
                 WHERE received_at BETWEEN ? AND ?",
                [$day . ' 00:00:00', $day . ' 23:59:59']
            )->fetch();
            return is_array($row) ? $row : [];
        } catch (\Throwable) {
            return [];
        }
    }
}
