<?php

declare(strict_types=1);

namespace App\AiBot;

use App\Infrastructure\TelegramUserLookupInterface;

/**
 * Право `aibot_finance` по связке Telegram id → активный пользователь сайта.
 * Только явная галочка в permissions_json: роль admin это право НЕ даёт.
 */
final class UserFinanceAuthorizer implements FinanceAuthorizerInterface
{
    public const PERMISSION = 'aibot_finance';

    public function __construct(private readonly TelegramUserLookupInterface $users) {}

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
        return is_array($perms) && !empty($perms[self::PERMISSION]);
    }
}
