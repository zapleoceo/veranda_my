<?php

declare(strict_types=1);

namespace App\Classes\TestAI\Repository;

use App\Classes\Database;

class Schema {
    public function ensure(
        Database $db,
        string $tRaw,
        string $tDaily,
        string $tSettings,
        string $tKb,
        string $tEvents = ''
    ): void {
        try {
            $pdo = $db->getPdo();

            $pdo->exec("CREATE TABLE IF NOT EXISTS {$tRaw} (
                id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                tg_chat_id           BIGINT NOT NULL,
                tg_chat_type         VARCHAR(16) NOT NULL,
                tg_chat_title        VARCHAR(255) NULL,
                tg_message_id        BIGINT NOT NULL,
                tg_user_id           BIGINT NULL,
                tg_username          VARCHAR(64) NULL,
                tg_name              VARCHAR(128) NULL,
                received_at          DATETIME NOT NULL,
                text                 TEXT NOT NULL,
                media_type           VARCHAR(16) NULL,
                media_file_id        VARCHAR(255) NULL,
                media_file_unique_id VARCHAR(255) NULL,
                media_mime           VARCHAR(128) NULL,
                media_duration_sec   INT NULL,
                media_text           TEXT NULL,
                meta_json            TEXT NULL,
                importance           TINYINT UNSIGNED NOT NULL DEFAULT 5,
                UNIQUE KEY uniq_chat_msg (tg_chat_id, tg_message_id),
                KEY idx_received_at (received_at),
                KEY idx_chat_time   (tg_chat_id, received_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

            // Migrations for existing installations
            try { $pdo->exec("ALTER TABLE {$tRaw} ADD COLUMN importance TINYINT UNSIGNED NOT NULL DEFAULT 5"); } catch (\Throwable) {}
            try { $pdo->exec("ALTER TABLE {$tRaw} ADD FULLTEXT KEY ft_text (text, media_text)"); } catch (\Throwable) {}

            $pdo->exec("CREATE TABLE IF NOT EXISTS {$tDaily} (
                day          DATE NOT NULL PRIMARY KEY,
                summary_text TEXT NOT NULL,
                events_json  TEXT NOT NULL,
                created_at   DATETIME NOT NULL,
                KEY idx_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

            $pdo->exec("CREATE TABLE IF NOT EXISTS {$tSettings} (
                k          VARCHAR(64) NOT NULL PRIMARY KEY,
                v          TEXT NOT NULL,
                updated_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

            $pdo->exec("CREATE TABLE IF NOT EXISTS {$tKb} (
                id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                title      VARCHAR(255) NOT NULL,
                source_url VARCHAR(512) NULL,
                content    MEDIUMTEXT NOT NULL,
                access     VARCHAR(20) NOT NULL DEFAULT 'public',
                category   VARCHAR(64) NOT NULL DEFAULT 'other',
                tags       TEXT NULL,
                is_active  TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_active     (is_active),
                KEY idx_access     (access),
                KEY idx_category   (category),
                KEY idx_updated_at (updated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

            try { $pdo->exec("ALTER TABLE {$tKb} ADD COLUMN access VARCHAR(20) NOT NULL DEFAULT 'public' AFTER content"); } catch (\Throwable) {}
            try { $pdo->exec("ALTER TABLE {$tKb} ADD COLUMN category VARCHAR(64) NOT NULL DEFAULT 'other' AFTER access"); } catch (\Throwable) {}
            try { $pdo->exec("ALTER TABLE {$tKb} ADD COLUMN tags TEXT NULL AFTER category"); } catch (\Throwable) {}
            try { $pdo->exec("ALTER TABLE {$tKb} ADD FULLTEXT KEY ft_kb (title, content)"); } catch (\Throwable) {}

            if ($tEvents !== '') {
                $pdo->exec("CREATE TABLE IF NOT EXISTS {$tEvents} (
                    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    event_date        DATE NULL,
                    title             VARCHAR(255) NOT NULL,
                    description       TEXT NOT NULL,
                    source_chat_id    VARCHAR(32) NULL,
                    source_message_id BIGINT NULL,
                    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    KEY idx_event_date (event_date),
                    FULLTEXT KEY ft_events (title, description)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
            }

        } catch (\Throwable) {}
    }
}
