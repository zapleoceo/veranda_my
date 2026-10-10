<?php

declare(strict_types=1);

namespace App\AiBot;

use App\Infrastructure\TelegramUserLookupInterface;

/**
 * Явное право $permission по связке Telegram id -> активный пользователь сайта.
 * Роль admin права НЕ даёт; ошибка БД или неоднозначность = отказ; кэша нет.
 */
class TelegramPermissionAuthorizer implements PermissionAuthorizerInterface
{
    public function __construct(
        private readonly TelegramUserLookupInterface $users,
        private readonly string $permission,
    ) {}

    public function allows(int $telegramUserId): bool
    {
        try {
            $u = $this->users->findByTelegramId($telegramUserId);
        } catch (\Throwable) {
            return false; // нет связи с БД — закрыто
        }
        if ($u === null || (int) ($u['is_active'] ?? 0) !== 1) {
            return false;
        }
        $perms = json_decode((string) ($u['permissions_json'] ?? ''), true);
        return is_array($perms) && !empty($perms[$this->permission]);
    }
}
