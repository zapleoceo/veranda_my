<?php

declare(strict_types=1);

namespace App\Payday3\Domain;

/**
 * Immutable snapshot of operator-tunable settings (Telegram targets,
 * Poster account IDs, finance categories, poster-admin credentials).
 *
 * Persisted as payday3/local_config.json. The repository layer owns
 * read/write/validate; this class only carries the fields and ships
 * an array shape for the Settings modal.
 *
 * Default values match payday2 so an empty config behaves identically
 * to a default payday2 install — no functional regression on migrate.
 */
final class LocalSettings
{
    /** @param array<int,int>      $allowedCategories     finance.getCategories whitelist */
    /** @param array<int,string>   $customCategoryNames   id → operator-renamed label */
    /** @param array<string,string> $posterAdmin          cookies/CSRF for admin scrape */
    public function __construct(
        public readonly string $telegramChatId,
        public readonly string $telegramThreadId,
        public readonly int    $serviceUserId,
        public readonly int    $accountAndreyId,
        public readonly int    $accountTipsId,
        public readonly int    $accountVietnamId,
        public readonly int    $balanceSyncAccountId,
        public readonly array  $allowedCategories,
        public readonly array  $customCategoryNames,
        public readonly array  $posterAdmin,
    ) {}

    public static function defaults(): self
    {
        return new self(
            telegramChatId:       '-1003889942420',
            // Telegram chat moved to forum/topics on 2026-05-17 —
            // the "Балансы" thread is 5274. Operators can override
            // via the ⚙ Settings modal in either /payday2 or /payday3.
            telegramThreadId:     '5274',
            serviceUserId:        4,
            accountAndreyId:      1,
            accountTipsId:        8,
            accountVietnamId:     9,
            balanceSyncAccountId: 8,
            allowedCategories:    [],
            customCategoryNames:  [],
            posterAdmin:          self::emptyPosterAdmin(),
        );
    }

    public static function emptyPosterAdmin(): array
    {
        return [
            'account' => '', 'pos_session' => '', 'ssid' => '',
            'csrf'    => '', 'cookie'      => '', 'user_agent' => '',
        ];
    }

    /** Shape returned to the client (Settings modal + bootstrap). */
    public function toClientPayload(): array
    {
        return [
            'telegram_chat_id'           => $this->telegramChatId,
            'telegram_message_thread_id' => $this->telegramThreadId,
            'service_user_id'            => $this->serviceUserId,
            'accounts' => [
                'andrey'  => $this->accountAndreyId,
                'tips'    => $this->accountTipsId,
                'vietnam' => $this->accountVietnamId,
            ],
            'balance_sinc_account_id' => $this->balanceSyncAccountId,
            'allowed_categories'      => array_values($this->allowedCategories),
            'custom_category_names'   => (object)$this->customCategoryNames,
            'poster_admin'            => $this->posterAdmin,
        ];
    }
}
