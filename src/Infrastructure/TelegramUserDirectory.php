<?php

declare(strict_types=1);

namespace App\Infrastructure;

/**
 * Связка пользователь сайта ↔ числовой Telegram id (users.telegram_user_id).
 * Значение задаёт только админ в разделе «Доступ»; UNIQUE — один id на одного
 * пользователя. telegram_username для авторизации НЕ используется.
 */
final class TelegramUserDirectory implements TelegramUserLookupInterface
{
    private static bool $schemaChecked = false;

    public function __construct(private readonly Database $db) {}

    /** Колонка появляется при первом обращении (как прочие таблицы проекта). */
    public function ensureSchema(): void
    {
        if (self::$schemaChecked) {
            return;
        }
        $t = $this->db->t('users');
        if (!$this->db->query("SHOW COLUMNS FROM {$t} LIKE 'telegram_user_id'")->fetch()) {
            try {
                $this->db->query("ALTER TABLE {$t} ADD COLUMN telegram_user_id BIGINT NULL, ADD UNIQUE KEY uq_users_telegram_user_id (telegram_user_id)");
            } catch (\Throwable $e) {
                // Параллельный запрос уже добавил колонку — ок; иначе пробрасываем.
                if (!$this->db->query("SHOW COLUMNS FROM {$t} LIKE 'telegram_user_id'")->fetch()) {
                    throw $e;
                }
            }
        }
        self::$schemaChecked = true;
    }

    public function findByTelegramId(int $telegramUserId): ?array
    {
        if ($telegramUserId <= 0) {
            return null;
        }
        $this->ensureSchema();
        $rows = $this->db->query(
            "SELECT id, email, is_active, permissions_json FROM {$this->db->t('users')} WHERE telegram_user_id = ? LIMIT 2",
            [$telegramUserId]
        )->fetchAll();
        // UNIQUE гарантирует одну строку; на всякий случай неоднозначность = отказ.
        return count($rows) === 1 ? $rows[0] : null;
    }
}
