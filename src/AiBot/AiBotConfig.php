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
        /**
         * Подтверждения записи (Внести / Повторить / Не вносить) с карточек, чьё
         * текущее поколение выдано раньше этого момента (unix), не исполняются —
         * например, момент активации бота. 0 — без отсечки (поколение всё равно
         * обязательно).
         */
        public readonly int $confirmNotBefore = 0,
        /**
         * Режим «любая группа, только владелец» (AIBOT_FINANCE_ANY_GROUP=1): бот
         * реагирует в любой группе (id чата < 0), но команды и кнопки принимает
         * ТОЛЬКО от владельца ($duplicateApproverTgId), и только если он же есть в
         * allow-list. Других авторов из allow-list в этом режиме нет. Без флага —
         * как раньше: allow-list чатов и пользователей.
         */
        public readonly bool $anyGroup = false,
        /**
         * Источник прав в проде: право `aibot_finance` пользователя сайта, связанного
         * с числовым Telegram id (раздел «Доступ»). Если задан — allow-list
         * $allowedUserIds и OWNER_TG_ID для авторизации НЕ используются.
         */
        public readonly ?FinanceAuthorizerInterface $authorizer = null,
    ) {}

    public static function fromConfig(?FinanceAuthorizerInterface $authorizer = null): self
    {
        return new self(
            // При заданном $authorizer список не участвует в авторизации (оставлен
            // только для режима без БД-прав).
            self::intList(Config::get('AIBOT_FINANCE_ALLOWED_TG_IDS')),
            self::strList(Config::get('AIBOT_FINANCE_ALLOWED_CHAT_IDS')),
            ltrim(Config::get('AIBOT_USERNAME', 'Veranda_aibot'), '@'),
            Config::int('AIBOT_MAX_UPDATE_AGE_SEC', 600),
            self::OWNER_TG_ID,
            Config::int('AIBOT_CONFIRM_NOT_BEFORE', 0),
            Config::get('AIBOT_FINANCE_ANY_GROUP') === '1',
            $authorizer,
        );
    }

    public function canWrite(int $tgUserId): bool
    {
        if ($this->authorizer !== null) {
            return $tgUserId > 0 && $this->authorizer->allows($tgUserId);
        }
        if ($tgUserId <= 0 || !in_array($tgUserId, $this->allowedUserIds, true)) {
            return false;
        }
        // В режиме любой группы — только владелец, даже если в allow-list есть другие.
        return !$this->anyGroup || $tgUserId === $this->duplicateApproverTgId;
    }

    /** Решение по возможному дублю: только владелец, и он же должен быть в allow-list. */
    public function canDecideDuplicate(int $tgUserId): bool
    {
        // С правами из БД решает тот, у кого есть право `aibot_finance` (и только он).
        if ($this->authorizer !== null) {
            return $this->canWrite($tgUserId);
        }
        return $this->canWrite($tgUserId) && $tgUserId === $this->duplicateApproverTgId;
    }

    public function chatAllowed(string $chatId): bool
    {
        if ($this->anyGroup) {
            // Группы и супергруппы — отрицательные id; личные чаты (> 0) — нет.
            return (bool) preg_match('/^-\d+$/', $chatId);
        }
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
