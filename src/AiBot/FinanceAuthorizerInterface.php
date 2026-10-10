<?php

declare(strict_types=1);

namespace App\AiBot;

/**
 * Кто может вносить финансовые записи через @Veranda_aibot. Источник истины —
 * право `aibot_finance` пользователя сайта (раздел «Доступ» в админке), связанного
 * с числовым Telegram id. Без кэша: отзыв права действует на следующем же действии.
 */
interface FinanceAuthorizerInterface
{
    public function allows(int $telegramUserId): bool;
}
