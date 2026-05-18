<?php

declare(strict_types=1);

namespace App\Payday3\Services;

use App\Payday3\Domain\LocalSettings;

/**
 * Shared (de)serialisation + validation for LocalSettings.
 *
 * Lives next to the repository implementations rather than on the VO
 * itself because validation is a "boundary" concern — the VO is meant
 * to stay pure data. JSON-file and DB repositories both delegate here
 * so the persisted shape is identical regardless of storage backend.
 *
 * The persisted JSON shape matches payday2's local_config.json
 * exactly, so migrating between backends or between modules is a
 * straight copy.
 */
final class LocalSettingsCodec
{
    /** @param array<string,mixed> $raw */
    public static function fromArray(array $raw): LocalSettings
    {
        $d   = LocalSettings::defaults();
        $acc = isset($raw['accounts']) && is_array($raw['accounts']) ? $raw['accounts'] : [];

        return new LocalSettings(
            telegramChatId:       self::firstNonEmptyString($raw['telegram_chat_id']           ?? null, $d->telegramChatId),
            telegramThreadId:     self::firstNonEmptyString($raw['telegram_message_thread_id'] ?? null, $d->telegramThreadId),
            serviceUserId:        self::positiveInt($raw['service_user_id']         ?? null, $d->serviceUserId),
            accountAndreyId:      self::positiveInt($acc['andrey']  ?? ($raw['account_andrey_id']  ?? null), $d->accountAndreyId),
            accountTipsId:        self::positiveInt($acc['tips']    ?? ($raw['account_tips_id']    ?? null), $d->accountTipsId),
            accountVietnamId:     self::positiveInt($acc['vietnam'] ?? ($raw['account_vietnam_id'] ?? null), $d->accountVietnamId),
            balanceSyncAccountId: self::positiveInt($raw['balance_sinc_account_id'] ?? null, $d->balanceSyncAccountId),
            allowedCategories:    is_array($raw['allowed_categories']    ?? null)
                                       ? array_values(array_map('intval', $raw['allowed_categories']))
                                       : $d->allowedCategories,
            customCategoryNames:  is_array($raw['custom_category_names'] ?? null)
                                       ? self::normaliseCustomNames($raw['custom_category_names'])
                                       : $d->customCategoryNames,
            posterAdmin:          self::mergePosterAdmin($raw['poster_admin'] ?? [], $d->posterAdmin),
        );
    }

    /**
     * @param array<string,mixed> $payload  payload as the modal sends it
     * @return array<string,mixed>          canonical persisted shape
     */
    public static function toCanonicalArray(array $payload): array
    {
        $accIn = isset($payload['accounts']) && is_array($payload['accounts']) ? $payload['accounts'] : [];
        return [
            'telegram_chat_id'           => trim((string)($payload['telegram_chat_id'] ?? '')),
            'telegram_message_thread_id' => trim((string)($payload['telegram_message_thread_id'] ?? '')),
            'service_user_id'            => (int)($payload['service_user_id'] ?? 0),
            'accounts' => [
                'andrey'  => (int)($accIn['andrey']  ?? 0),
                'tips'    => (int)($accIn['tips']    ?? 0),
                'vietnam' => (int)($accIn['vietnam'] ?? 0),
            ],
            'balance_sinc_account_id'    => (int)($payload['balance_sinc_account_id'] ?? 0),
            'allowed_categories'         => array_values(array_map('intval', $payload['allowed_categories'] ?? [])),
            'custom_category_names'      => self::normaliseCustomNames($payload['custom_category_names'] ?? []),
            'poster_admin'               => self::mergePosterAdmin($payload['poster_admin'] ?? [], LocalSettings::emptyPosterAdmin()),
        ];
    }

    /** @param array<string,mixed> $p */
    public static function validate(array $p): ?string
    {
        $chat = isset($p['telegram_chat_id']) ? trim((string)$p['telegram_chat_id']) : '';
        if ($chat === '' || mb_strlen($chat) > 80) {
            return 'Укажите telegram_chat_id (1..80 символов).';
        }
        $thread = isset($p['telegram_message_thread_id']) ? trim((string)$p['telegram_message_thread_id']) : '';
        if (mb_strlen($thread) > 32) {
            return 'telegram_message_thread_id слишком длинный.';
        }
        $svc = isset($p['service_user_id']) ? (int)$p['service_user_id'] : 0;
        if ($svc <= 0 || $svc > 999_999_999) {
            return 'Неверный service_user_id.';
        }
        $acc = isset($p['accounts']) && is_array($p['accounts']) ? $p['accounts'] : [];
        foreach (['andrey', 'tips', 'vietnam'] as $label) {
            $n = (int)($acc[$label] ?? 0);
            if ($n <= 0 || $n > 999_999_999) {
                return 'Неверный ID счёта: ' . $label;
            }
        }
        $bs = (int)($p['balance_sinc_account_id'] ?? 0);
        if ($bs <= 0 || $bs > 999_999_999) {
            return 'Неверный balance_sinc_account_id.';
        }
        return null;
    }

    private static function firstNonEmptyString(mixed $v, string $fallback): string
    {
        $s = $v === null ? '' : trim((string)$v);
        return $s !== '' ? $s : $fallback;
    }

    private static function positiveInt(mixed $v, int $fallback): int
    {
        $n = (int)$v;
        return $n > 0 ? $n : $fallback;
    }

    /** @return array<int,string> */
    private static function normaliseCustomNames(mixed $in): array
    {
        if (!is_array($in)) return [];
        $out = [];
        foreach ($in as $k => $v) {
            $id = (int)$k;
            if ($id > 0 && is_string($v) && trim($v) !== '') {
                $out[$id] = trim($v);
            }
        }
        return $out;
    }

    /** @return array<string,string> */
    private static function mergePosterAdmin(mixed $in, array $defaults): array
    {
        if (!is_array($in)) return $defaults;
        $keys = ['account', 'pos_session', 'ssid', 'csrf', 'cookie', 'user_agent'];
        $out = [];
        foreach ($keys as $k) $out[$k] = trim((string)($in[$k] ?? $defaults[$k] ?? ''));
        return $out;
    }
}
