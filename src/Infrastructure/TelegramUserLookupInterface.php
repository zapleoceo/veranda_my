<?php

declare(strict_types=1);

namespace App\Infrastructure;

interface TelegramUserLookupInterface
{
    /** @return array{id:int|string,email:string,is_active:int|string,permissions_json:?string}|null */
    public function findByTelegramId(int $telegramUserId): ?array;
}
