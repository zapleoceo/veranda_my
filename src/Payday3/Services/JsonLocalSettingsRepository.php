<?php

declare(strict_types=1);

namespace App\Payday3\Services;

use App\Payday3\Contracts\LocalSettingsRepositoryInterface;
use App\Payday3\Domain\LocalSettings;

/**
 * Filesystem-backed config store for payday3.
 *
 *   primary path: payday3/local_config.json (writable by web user)
 *   fallback:     payday2/local_config.json (read-only — for live
 *                 migration from the legacy module)
 *
 * Reading prefers payday3's file; if absent, the payday2 file is
 * used so existing deployments keep their tuned values. Writes
 * always go to payday3's file — once a save happens, the fallback
 * is no longer consulted.
 *
 * The in-process cache is invalidated on save() to keep the next
 * load() consistent for callers within the same request.
 */
final class JsonLocalSettingsRepository implements LocalSettingsRepositoryInterface
{
    private ?LocalSettings $cache = null;

    public function __construct(
        private readonly string $primaryPath,   // payday3/local_config.json
        private readonly string $fallbackPath,  // payday2/local_config.json
    ) {}

    public function load(): LocalSettings
    {
        if ($this->cache !== null) return $this->cache;

        $raw = $this->readJson($this->primaryPath) ?? $this->readJson($this->fallbackPath);
        if (!is_array($raw)) {
            return $this->cache = LocalSettings::defaults();
        }

        $d   = LocalSettings::defaults();
        $acc = isset($raw['accounts']) && is_array($raw['accounts']) ? $raw['accounts'] : [];

        $resolved = new LocalSettings(
            telegramChatId:       self::firstNonEmptyString(
                                       $raw['telegram_chat_id']           ?? null,
                                       $d->telegramChatId),
            telegramThreadId:     self::firstNonEmptyString(
                                       $raw['telegram_message_thread_id'] ?? null,
                                       $d->telegramThreadId),
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

        return $this->cache = $resolved;
    }

    public function save(array $payload): array
    {
        $err = self::validate($payload);
        if ($err !== null) return ['ok' => false, 'error' => $err];

        $accIn = $payload['accounts'] ?? [];
        $out = [
            'telegram_chat_id'           => trim((string)$payload['telegram_chat_id']),
            'telegram_message_thread_id' => trim((string)($payload['telegram_message_thread_id'] ?? '')),
            'service_user_id'            => (int)$payload['service_user_id'],
            'accounts' => [
                'andrey'  => (int)$accIn['andrey'],
                'tips'    => (int)$accIn['tips'],
                'vietnam' => (int)$accIn['vietnam'],
            ],
            'balance_sinc_account_id'    => (int)$payload['balance_sinc_account_id'],
            'allowed_categories'         => array_values(array_map('intval', $payload['allowed_categories'] ?? [])),
            'custom_category_names'      => self::normaliseCustomNames($payload['custom_category_names'] ?? []),
            'poster_admin'               => self::mergePosterAdmin($payload['poster_admin'] ?? [], LocalSettings::emptyPosterAdmin()),
        ];

        $json = json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($json === false) return ['ok' => false, 'error' => 'json_encode failed'];

        if (!is_dir(dirname($this->primaryPath))) {
            @mkdir(dirname($this->primaryPath), 0755, true);
        }
        $tmp = $this->primaryPath . '.tmp.' . bin2hex(random_bytes(6));
        if (@file_put_contents($tmp, $json . "\n") === false) {
            return ['ok' => false, 'error' => 'cannot write tmp file (check perms on ' . dirname($this->primaryPath) . ')'];
        }
        if (!@rename($tmp, $this->primaryPath)) {
            @unlink($tmp);
            return ['ok' => false, 'error' => 'cannot rename to ' . basename($this->primaryPath)];
        }
        $this->cache = null;
        return ['ok' => true];
    }

    // ─── helpers ──────────────────────────────────────────────────

    private function readJson(string $path): ?array
    {
        $s = @file_get_contents($path);
        if (!is_string($s) || $s === '') return null;
        $j = json_decode($s, true);
        return is_array($j) ? $j : null;
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
    private static function normaliseCustomNames(array $in): array
    {
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

    private static function validate(array $p): ?string
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
}
