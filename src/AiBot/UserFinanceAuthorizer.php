<?php

declare(strict_types=1);

namespace App\AiBot;

use App\Infrastructure\TelegramUserLookupInterface;

/**
 * Право `aibot_finance` по связке Telegram id -> активный пользователь сайта.
 * Только явная галочка в permissions_json: роль admin это право НЕ даёт.
 */
final class UserFinanceAuthorizer extends TelegramPermissionAuthorizer implements FinanceAuthorizerInterface
{
    public const PERMISSION = 'aibot_finance';

    public function __construct(TelegramUserLookupInterface $users)
    {
        parent::__construct($users, self::PERMISSION);
    }
}
