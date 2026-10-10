<?php

declare(strict_types=1);

namespace Tests\Unit\AiBot\Fakes;

use App\AiBot\UserFinanceAuthorizer;
use App\Infrastructure\TelegramUserLookupInterface;

/**
 * «Таблица users» в памяти: Telegram id → пользователь с правами. Изменения
 * (выдать/отозвать право) видны сразу — как в БД без кэша.
 */
final class GrantTable implements TelegramUserLookupInterface
{
    /** @var array<int,array{id:int,email:string,is_active:int,permissions_json:string}> */
    public array $byTg = [];

    public function add(int $tgId, string $email, array $perms, int $active = 1): void
    {
        $this->byTg[$tgId] = ['id' => count($this->byTg) + 1, 'email' => $email, 'is_active' => $active,
            'permissions_json' => (string) json_encode($perms)];
    }

    public function setPerm(int $tgId, string $key, bool $on): void
    {
        $p = json_decode($this->byTg[$tgId]['permissions_json'], true) ?: [];
        $p[$key] = $on;
        $this->byTg[$tgId]['permissions_json'] = (string) json_encode($p);
    }

    public function revoke(int $tgId): void
    {
        $this->setPerm($tgId, UserFinanceAuthorizer::PERMISSION, false);
    }

    public function findByTelegramId(int $telegramUserId): ?array
    {
        return $this->byTg[$telegramUserId] ?? null;
    }
}
