<?php

declare(strict_types=1);

/**
 * Диагностика: почему cleanup_orphan_alerts.php репортит 750 still-orphan.
 *
 * Берёт первое сообщение из tg_alert_items, дёргает Telegram руками и
 * печатает ПОЛНЫЙ ответ API. По описанию ошибки сразу понятно:
 *   - "not enough rights to delete a message"   → бот не админ ИЛИ
 *                                                  админ без delete_messages
 *   - "message to delete not found"             → сообщение уже удалено
 *   - "chat not found"                          → неверный chat_id
 *   - "message can't be deleted for everyone"   → редкий случай (>48h без admin)
 *
 * Также выводит:
 *   - getChat: чтобы убедиться что chat_id правильный, и видим имя группы
 *   - getChatMember(self): права бота в группе (status + can_delete_messages)
 *
 * Идемпотентно — НИЧЕГО не удаляет в БД, не правит crontab. Только GET'ы
 * к Telegram API и один пробный deleteMessage.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Infrastructure\Config;
use App\Infrastructure\Database;
use App\Infrastructure\HttpClient;

Config::load(__DIR__ . '/../../.env');
date_default_timezone_set(Config::get('POSTER_SPOT_TIMEZONE', 'Asia/Ho_Chi_Minh'));

$token  = Config::require('TELEGRAM_BOT_TOKEN');
$chatId = Config::require('TELEGRAM_CHAT_ID');
$http   = new HttpClient(timeoutSeconds: 10);
$db     = Database::getInstance();

$call = static function (string $method, array $params) use ($http, $token): array|null {
    return $http->postJson("https://api.telegram.org/bot{$token}/{$method}", $params);
};

echo "=== 1. Config ===\n";
echo "chat_id (.env)    : {$chatId}\n";
echo "bot token prefix  : " . substr($token, 0, 8) . "...\n";

echo "\n=== 2. getMe (self check) ===\n";
$me = $call('getMe', []);
print_r($me);
$botId = (int) ($me['result']['id'] ?? 0);

echo "\n=== 3. getChat (verify chat_id is reachable) ===\n";
$chat = $call('getChat', ['chat_id' => $chatId]);
print_r($chat);

echo "\n=== 4. getChatMember(bot) — admin status + permissions ===\n";
if ($botId > 0) {
    $member = $call('getChatMember', ['chat_id' => $chatId, 'user_id' => $botId]);
    print_r($member);
} else {
    echo "skip: botId unknown\n";
}

echo "\n=== 5. Берём первое сообщение из tg_alert_items + пробуем удалить ===\n";
$ai = $db->t('tg_alert_items');
$row = $db->query(
    "SELECT transaction_date, kitchen_stats_id, message_id
     FROM {$ai}
     WHERE message_id IS NOT NULL
     ORDER BY transaction_date DESC, kitchen_stats_id DESC
     LIMIT 1"
)->fetch();

if (!$row) {
    echo "tg_alert_items пусто — ничего не пробуем.\n";
    exit(0);
}
$msgId = (int) $row['message_id'];
echo "Пробное сообщение: date={$row['transaction_date']} kid={$row['kitchen_stats_id']} msg={$msgId}\n";

echo "\n   deleteMessage($msgId) → \n";
$delResp = $call('deleteMessage', ['chat_id' => $chatId, 'message_id' => $msgId]);
print_r($delResp);

echo "\n=== diagnosis ===\n";
$desc = (string) ($delResp['description'] ?? '');
$code = (int) ($delResp['error_code'] ?? 0);
if (!empty($delResp['ok'])) {
    echo "OK — Telegram согласился удалить. Cleanup-скрипт ДОЛЖЕН был его удалить раньше — расхождение, проверяйте логи.\n";
} elseif (stripos($desc, 'not enough rights') !== false) {
    echo "ROOT CAUSE: у бота нет permission `can_delete_messages` в этой группе.\n";
    echo "Это даже если бот формально admin — отдельная галка в правах админа.\n";
    echo "Действие: в группе → менеджмент → администраторы → найти бота → включить «Delete messages».\n";
} elseif (stripos($desc, 'chat not found') !== false) {
    echo "ROOT CAUSE: chat_id {$chatId} не найден этим ботом (бот не в этой группе ИЛИ id протух).\n";
} elseif (stripos($desc, 'message to delete not found') !== false) {
    echo "ROOT CAUSE: сообщение msg_id={$msgId} в группе {$chatId} уже не существует (удалено руками или из другой группы).\n";
    echo "Тогда строки в tg_alert_items валидно дропнуть БЕЗ API-вызова. Не критично, но cleanup можно делать тише.\n";
} else {
    echo "Неожиданный ответ: error_code={$code} description=«{$desc}»\n";
}
