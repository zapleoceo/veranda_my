<?php

declare(strict_types=1);

namespace App\AiBot;

use App\Infrastructure\Config;

/**
 * Настройки @Veranda_aibot. Всё «закрыто по умолчанию»: пустой список
 * пользователей или чатов = никто/нигде не может вносить расходы.
 */
final class AiBotConfig
{
    /** Ключ права «вносить финансовые транзакции из Telegram». Реализован allow-list'ом из .env. */
    public const PERMISSION_KEY = 'tg_finance_write';

    /** Poster finance category «Инвесторы » (с пробелом на конце в Poster). Подкатегории не создаём. */
    public const CATEGORY_ID = 22;

    public const TZ = 'Asia/Ho_Chi_Minh';

    /**
     * Владелец (Дмитрий), проверенный числовой Telegram id. Только он решает
     * судьбу «возможного дубля» (повторить / не вносить) — независимо от того,
     * кто ещё окажется в allow-list. Задан в коде, не в .env, намеренно.
     */
    public const OWNER_TG_ID = 169510539;

    /**
     * @param list<int>    $allowedUserIds  числовые Telegram from.id владельца (Дмитрий) — единственные, чьи команды и подтверждения принимаются; username не используется
     * @param list<string> $allowedChatIds  чаты, где бот вообще реагирует
     */
    public function __construct(
        public readonly array $allowedUserIds,
        public readonly array $allowedChatIds,
        public readonly string $botUsername = 'Veranda_aibot',
        public readonly int $maxUpdateAgeSec = 600,
        public readonly int $duplicateApproverTgId = self::OWNER_TG_ID,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            self::intList(Config::get('AIBOT_FINANCE_ALLOWED_TG_IDS')),
            self::strList(Config::get('AIBOT_FINANCE_ALLOWED_CHAT_IDS')),
            ltrim(Config::get('AIBOT_USERNAME', 'Veranda_aibot'), '@'),
            Config::int('AIBOT_MAX_UPDATE_AGE_SEC', 600),
        );
    }

    public function canWrite(int $tgUserId): bool
    {
        return $tgUserId > 0 && in_array($tgUserId, $this->allowedUserIds, true);
    }

    /** Решение по возможному дублю: только владелец, и он же должен быть в allow-list. */
    public function canDecideDuplicate(int $tgUserId): bool
    {
        return $this->canWrite($tgUserId) && $tgUserId === $this->duplicateApproverTgId;
    }

    public function chatAllowed(string $chatId): bool
    {
        return $chatId !== '' && in_array($chatId, $this->allowedChatIds, true);
    }

    /** @return list<int> */
    private static function intList(string $raw): array
    {
        $out = [];
        foreach (preg_split('/[\s,;]+/', $raw) ?: [] as $p) {
            if (preg_match('/^\d+$/', $p)) {
                $out[] = (int) $p;
            }
        }
        return $out;
    }

    /** @return list<string> */
    private static function strList(string $raw): array
    {
        $out = [];
        foreach (preg_split('/[\s,;]+/', $raw) ?: [] as $p) {
            if (preg_match('/^-?\d+$/', $p)) {
                $out[] = $p;
            }
        }
        return $out;
    }
}
