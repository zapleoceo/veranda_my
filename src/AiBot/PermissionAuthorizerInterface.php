<?php

declare(strict_types=1);

namespace App\AiBot;

/** Есть ли у числового Telegram id явное право (без кэша: отзыв действует сразу). */
interface PermissionAuthorizerInterface
{
    public function allows(int $telegramUserId): bool;
}
