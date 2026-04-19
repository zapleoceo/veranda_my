<?php
namespace App\Payday2;

/**
 * Reads payday2/local_config.json (optional) and merges with defaults from Config.
 * Edit local_config.json on the server to change Telegram targets and Poster account IDs without code changes.
 */
final class LocalSettings
{
    private static ?array $merged = null;

    public static function merged(): array
    {
        if (self::$merged !== null) {
            return self::$merged;
        }
        $defaults = [
            'telegram_chat_id' => '-1003889942420',
            'telegram_message_thread_id' => '1736',
            'service_user_id' => 4,
            'account_andrey_id' => Config::ACCOUNT_ANDREY,
            'account_tips_id' => Config::ACCOUNT_TIPS,
            'account_vietnam_id' => Config::ACCOUNT_VIETNAM,
            'balance_sinc_account_id' => Config::ACCOUNT_TIPS,
            'allowed_categories' => [],
            'custom_category_names' => [],
        ];
        $path = __DIR__ . DIRECTORY_SEPARATOR . 'local_config.json';
        $file = @file_get_contents($path);
        $j = is_string($file) ? json_decode($file, true) : null;
        if (!is_array($j)) {
            self::$merged = $defaults;
            return self::$merged;
        }
        $acc = isset($j['accounts']) && is_array($j['accounts']) ? $j['accounts'] : [];
        $row = [
            'telegram_chat_id' => isset($j['telegram_chat_id']) ? trim((string)$j['telegram_chat_id']) : $defaults['telegram_chat_id'],
            'telegram_message_thread_id' => isset($j['telegram_message_thread_id'])
                ? trim((string)$j['telegram_message_thread_id'])
                : $defaults['telegram_message_thread_id'],
            'service_user_id' => isset($j['service_user_id']) ? (int)$j['service_user_id'] : $defaults['service_user_id'],
            'account_andrey_id' => isset($acc['andrey']) ? (int)$acc['andrey'] : (isset($j['account_andrey_id']) ? (int)$j['account_andrey_id'] : $defaults['account_andrey_id']),
            'account_tips_id' => isset($acc['tips']) ? (int)$acc['tips'] : (isset($j['account_tips_id']) ? (int)$j['account_tips_id'] : $defaults['account_tips_id']),
            'account_vietnam_id' => isset($acc['vietnam']) ? (int)$acc['vietnam'] : (isset($j['account_vietnam_id']) ? (int)$j['account_vietnam_id'] : $defaults['account_vietnam_id']),
            'balance_sinc_account_id' => isset($j['balance_sinc_account_id'])
                ? (int)$j['balance_sinc_account_id']
                : $defaults['balance_sinc_account_id'],
            'allowed_categories' => isset($j['allowed_categories']) && is_array($j['allowed_categories']) ? array_map('intval', $j['allowed_categories']) : $defaults['allowed_categories'],
            'custom_category_names' => isset($j['custom_category_names']) && is_array($j['custom_category_names']) ? $j['custom_category_names'] : $defaults['custom_category_names'],
        ];
        foreach (['service_user_id', 'account_andrey_id', 'account_tips_id', 'account_vietnam_id', 'balance_sinc_account_id'] as $k) {
            if ((int)$row[$k] <= 0) {
                $row[$k] = $defaults[$k];
            }
        }
        if ($row['telegram_chat_id'] === '') {
            $row['telegram_chat_id'] = $defaults['telegram_chat_id'];
        }
        self::$merged = $row;
        return self::$merged;
    }

    public static function telegramChatId(): string
    {
        return (string)self::merged()['telegram_chat_id'];
    }

    public static function telegramMessageThreadId(): string
    {
        return (string)self::merged()['telegram_message_thread_id'];
    }

    public static function serviceUserId(): int
    {
        return (int)self::merged()['service_user_id'];
    }

    public static function accountAndreyId(): int
    {
        return (int)self::merged()['account_andrey_id'];
    }

    public static function accountTipsId(): int
    {
        return (int)self::merged()['account_tips_id'];
    }

    public static function accountVietnamId(): int
    {
        return (int)self::merged()['account_vietnam_id'];
    }

    public static function balanceSincAccountId(): int
    {
        return (int)self::merged()['balance_sinc_account_id'];
    }

    /** Payload for PAYDAY_CONFIG / settings form (matches local_config.json shape). */
    public static function toClientPayload(): array
    {
        $m = self::merged();
        return [
            'telegram_chat_id' => (string)$m['telegram_chat_id'],
            'telegram_message_thread_id' => (string)$m['telegram_message_thread_id'],
            'service_user_id' => (int)$m['service_user_id'],
            'accounts' => [
                'andrey' => (int)$m['account_andrey_id'],
                'tips' => (int)$m['account_tips_id'],
                'vietnam' => (int)$m['account_vietnam_id'],
            ],
            'balance_sinc_account_id' => (int)$m['balance_sinc_account_id'],
            'allowed_categories' => (array)($m['allowed_categories'] ?? []),
            'custom_category_names' => (array)($m['custom_category_names'] ?? []),
        ];
    }

    public static function resetMergedCache(): void
    {
        self::$merged = null;
    }

    /**
     * @param array<string,mixed> $in
     * @return array{ok: true}|array{ok: false, error: string}
     */
    public static function persistPayload(array $in): array
    {
        $tgChat = isset($in['telegram_chat_id']) ? trim((string)$in['telegram_chat_id']) : '';
        if ($tgChat === '' || mb_strlen($tgChat) > 80) {
            return ['ok' => false, 'error' => 'Укажите telegram_chat_id (до 80 символов).'];
        }
        $thread = isset($in['telegram_message_thread_id']) ? trim((string)$in['telegram_message_thread_id']) : '';
        if (mb_strlen($thread) > 32) {
            return ['ok' => false, 'error' => 'telegram_message_thread_id слишком длинный.'];
        }
        $svc = isset($in['service_user_id']) ? (int)$in['service_user_id'] : 0;
        if ($svc <= 0 || $svc > 999999999) {
            return ['ok' => false, 'error' => 'Неверный service_user_id.'];
        }
        $accIn = isset($in['accounts']) && is_array($in['accounts']) ? $in['accounts'] : [];
        $a = isset($accIn['andrey']) ? (int)$accIn['andrey'] : 0;
        $t = isset($accIn['tips']) ? (int)$accIn['tips'] : 0;
        $v = isset($accIn['vietnam']) ? (int)$accIn['vietnam'] : 0;
        foreach (['andrey' => $a, 'tips' => $t, 'vietnam' => $v] as $label => $id) {
            if ($id <= 0 || $id > 999999999) {
                return ['ok' => false, 'error' => 'Неверный ID счёта: ' . $label];
            }
        }
        $bs = isset($in['balance_sinc_account_id']) ? (int)$in['balance_sinc_account_id'] : 0;
        if ($bs <= 0 || $bs > 999999999) {
            return ['ok' => false, 'error' => 'Неверный balance_sinc_account_id.'];
        }
        $allowedCats = isset($in['allowed_categories']) && is_array($in['allowed_categories']) ? array_values(array_map('intval', $in['allowed_categories'])) : [];
        $customNamesIn = isset($in['custom_category_names']) && is_array($in['custom_category_names']) ? $in['custom_category_names'] : [];
        $customNames = [];
        foreach ($customNamesIn as $k => $v) {
            $id = (int)$k;
            if ($id > 0 && is_string($v) && trim($v) !== '') {
                $customNames[$id] = trim($v);
            }
        }

        $out = [
            'telegram_chat_id' => $tgChat,
            'telegram_message_thread_id' => $thread,
            'service_user_id' => $svc,
            'accounts' => [
                'andrey' => $a,
                'tips' => $t,
                'vietnam' => $v,
            ],
            'balance_sinc_account_id' => $bs,
            'allowed_categories' => $allowedCats,
            'custom_category_names' => $customNames,
        ];

        $path = __DIR__ . DIRECTORY_SEPARATOR . 'local_config.json';
        $json = json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return ['ok' => false, 'error' => 'Ошибка кодирования JSON'];
        }
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(6));
        if (file_put_contents($tmp, $json . "\n") === false) {
            @unlink($tmp);
            return ['ok' => false, 'error' => 'Не удалось записать временный файл (права на payday2/?)'];
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return ['ok' => false, 'error' => 'Не удалось сохранить local_config.json'];
        }
        self::$merged = null;
        return ['ok' => true];
    }
}
