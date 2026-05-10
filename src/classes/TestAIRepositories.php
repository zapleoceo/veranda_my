<?php

namespace App\Classes;

class TestAIMessage {
    public string $chatId = '';
    public string $chatType = 'unknown';
    public string $chatTitle = '';
    public int $messageId = 0;
    public ?int $userId = null;
    public string $username = '';
    public string $name = '';
    public string $receivedAt = '';
    public string $text = '';

    public ?string $mediaType = null;
    public ?string $mediaFileId = null;
    public ?string $mediaFileUniqueId = null;
    public ?string $mediaMime = null;
    public ?int $mediaDurationSec = null;
    public ?string $mediaText = null;

    public string $metaJson = '{}';
}

class TestAIRawMessagesRepository {
    private Database $db;
    private string $tRaw;

    public function __construct(Database $db, string $tRaw) {
        $this->db = $db;
        $this->tRaw = $tRaw;
    }

    public function upsert(TestAIMessage $m): void {
        try {
            $this->db->query(
                "INSERT INTO {$this->tRaw}
                    (tg_chat_id, tg_chat_type, tg_chat_title, tg_message_id, tg_user_id, tg_username, tg_name, received_at, text,
                     media_type, media_file_id, media_file_unique_id, media_mime, media_duration_sec, media_text, meta_json)
                 VALUES
                    (?, ?, NULLIF(?, ''), ?, ?, NULLIF(?, ''), NULLIF(?, ''), ?, ?,
                     NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''), ?, NULLIF(?, ''), ?)
                 ON DUPLICATE KEY UPDATE
                    tg_chat_type = VALUES(tg_chat_type),
                    tg_chat_title = VALUES(tg_chat_title),
                    tg_user_id = VALUES(tg_user_id),
                    tg_username = VALUES(tg_username),
                    tg_name = VALUES(tg_name),
                    received_at = VALUES(received_at),
                    text = VALUES(text),
                    media_type = VALUES(media_type),
                    media_file_id = VALUES(media_file_id),
                    media_file_unique_id = VALUES(media_file_unique_id),
                    media_mime = VALUES(media_mime),
                    media_duration_sec = VALUES(media_duration_sec),
                    media_text = IF(VALUES(media_text) IS NULL OR VALUES(media_text) = '', media_text, VALUES(media_text)),
                    meta_json = VALUES(meta_json)",
                [
                    $m->chatId,
                    $m->chatType,
                    $m->chatTitle,
                    $m->messageId,
                    $m->userId,
                    ltrim(strtolower($m->username), '@'),
                    $m->name,
                    $m->receivedAt,
                    $m->text,
                    $m->mediaType,
                    $m->mediaFileId,
                    $m->mediaFileUniqueId,
                    $m->mediaMime,
                    $m->mediaDurationSec,
                    $m->mediaText,
                    $m->metaJson,
                ]
            );
        } catch (\Throwable $e) {
        }
    }

    public function updateMediaText(string $chatId, int $messageId, string $mediaText): void {
        try {
            $this->db->query(
                "UPDATE {$this->tRaw}
                 SET media_text = ?
                 WHERE tg_chat_id = ? AND tg_message_id = ?
                 LIMIT 1",
                [$mediaText, $chatId, $messageId]
            );
        } catch (\Throwable $e) {
        }
    }

    public function getCountsForDay(string $date): array {
        $from = $date . ' 00:00:00';
        $to = $date . ' 23:59:59';
        $cnt = 0;
        $withMedia = 0;
        $withMediaText = 0;
        try {
            $row = $this->db->query(
                "SELECT
                    COUNT(*) AS cnt,
                    SUM(CASE WHEN media_type IS NOT NULL AND media_type <> '' THEN 1 ELSE 0 END) AS with_media,
                    SUM(CASE WHEN media_text IS NOT NULL AND media_text <> '' THEN 1 ELSE 0 END) AS with_media_text
                 FROM {$this->tRaw}
                 WHERE received_at BETWEEN ? AND ?",
                [$from, $to]
            )->fetch();
            if (is_array($row)) {
                $cnt = (int)($row['cnt'] ?? 0);
                $withMedia = (int)($row['with_media'] ?? 0);
                $withMediaText = (int)($row['with_media_text'] ?? 0);
            }
        } catch (\Throwable $e) {
        }
        return ['count' => $cnt, 'with_media' => $withMedia, 'with_media_text' => $withMediaText];
    }

    public function getTotals(): array {
        $rawTotal = 0;
        $rawLast = '';
        try {
            $row = $this->db->query("SELECT COUNT(*) AS c, MAX(received_at) AS m FROM {$this->tRaw}")->fetch();
            if (is_array($row)) {
                $rawTotal = (int)($row['c'] ?? 0);
                $rawLast = (string)($row['m'] ?? '');
            }
        } catch (\Throwable $e) {
        }
        return ['raw_total' => $rawTotal, 'raw_last_received_at' => $rawLast];
    }

    public function fetchForRange(string $from, string $to): array {
        try {
            $rows = $this->db->query(
                "SELECT tg_chat_id, tg_message_id, tg_chat_title, tg_username, tg_name, received_at, text, media_type, media_text, media_mime
                 FROM {$this->tRaw}
                 WHERE received_at BETWEEN ? AND ?
                 ORDER BY received_at ASC",
                [$from, $to]
            )->fetchAll();
            return is_array($rows) ? $rows : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function fetchRecentByChat(string $chatId, int $limit): array {
        $limit = max(1, min(50, (int)$limit));
        try {
            $rows = $this->db->query(
                "SELECT received_at, tg_username, tg_name, text, media_text
                 FROM {$this->tRaw}
                 WHERE tg_chat_id = ?
                 ORDER BY received_at DESC
                 LIMIT {$limit}",
                [$chatId]
            )->fetchAll();
            return is_array($rows) ? $rows : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function fetchLastForDay(string $date, int $limit): array {
        $limit = max(1, min(50, (int)$limit));
        $from = $date . ' 00:00:00';
        $to = $date . ' 23:59:59';
        try {
            $rows = $this->db->query(
                "SELECT tg_chat_id, tg_message_id, tg_chat_title, tg_username, tg_name, received_at, text, media_type, media_text
                 FROM {$this->tRaw}
                 WHERE received_at BETWEEN ? AND ?
                 ORDER BY received_at DESC
                 LIMIT {$limit}",
                [$from, $to]
            )->fetchAll();
            return is_array($rows) ? $rows : [];
        } catch (\Throwable $e) {
            return [];
        }
    }
}

class TestAIDailySummariesRepository {
    private Database $db;
    private string $tDaily;

    public function __construct(Database $db, string $tDaily) {
        $this->db = $db;
        $this->tDaily = $tDaily;
    }

    public function countAll(): int {
        try {
            $row = $this->db->query("SELECT COUNT(*) AS c FROM {$this->tDaily}")->fetch();
            return is_array($row) ? (int)($row['c'] ?? 0) : 0;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    public function getByDay(string $day): ?array {
        try {
            $row = $this->db->query(
                "SELECT summary_text, events_json, created_at
                 FROM {$this->tDaily}
                 WHERE day = ?
                 LIMIT 1",
                [$day]
            )->fetch();
            return is_array($row) ? $row : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function listSince(string $since): array {
        try {
            $rows = $this->db->query(
                "SELECT day, events_json
                 FROM {$this->tDaily}
                 WHERE created_at >= ?
                 ORDER BY day ASC",
                [$since]
            )->fetchAll();
            return is_array($rows) ? $rows : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function upsert(string $day, string $summaryText, string $eventsJson, string $createdAt): void {
        try {
            $this->db->query(
                "INSERT INTO {$this->tDaily} (day, summary_text, events_json, created_at)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    summary_text = VALUES(summary_text),
                    events_json = VALUES(events_json),
                    created_at = VALUES(created_at)",
                [$day, $summaryText, $eventsJson, $createdAt]
            );
        } catch (\Throwable $e) {
        }
    }
}

class TestAISettingsRepository {
    private Database $db;
    private string $tSettings;

    public function __construct(Database $db, string $tSettings) {
        $this->db = $db;
        $this->tSettings = $tSettings;
    }

    public function getBotPrompt(): array {
        $v = '';
        $updatedAt = '';
        try {
            $row = $this->db->query(
                "SELECT v, updated_at FROM {$this->tSettings} WHERE k = ? LIMIT 1",
                ['bot_prompt']
            )->fetch();
            if (is_array($row)) {
                $v = (string)($row['v'] ?? '');
                $updatedAt = (string)($row['updated_at'] ?? '');
            }
        } catch (\Throwable $e) {
        }
        return ['prompt' => $v, 'updated_at' => $updatedAt];
    }

    public function setBotPrompt(string $prompt, string $updatedAt): void {
        try {
            $this->db->query(
                "INSERT INTO {$this->tSettings} (k, v, updated_at)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE v = VALUES(v), updated_at = VALUES(updated_at)",
                ['bot_prompt', $prompt, $updatedAt]
            );
        } catch (\Throwable $e) {
        }
    }

    public function getKey(string $key): array {
        $v = '';
        $updatedAt = '';
        try {
            $row = $this->db->query(
                "SELECT v, updated_at FROM {$this->tSettings} WHERE k = ? LIMIT 1",
                [$key]
            )->fetch();
            if (is_array($row)) {
                $v = (string)($row['v'] ?? '');
                $updatedAt = (string)($row['updated_at'] ?? '');
            }
        } catch (\Throwable $e) {
        }
        return ['v' => $v, 'updated_at' => $updatedAt];
    }

    public function setKey(string $key, string $value, string $updatedAt): void {
        try {
            $this->db->query(
                "INSERT INTO {$this->tSettings} (k, v, updated_at)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE v = VALUES(v), updated_at = VALUES(updated_at)",
                [$key, $value, $updatedAt]
            );
        } catch (\Throwable $e) {
        }
    }
}
