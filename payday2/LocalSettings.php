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
}
