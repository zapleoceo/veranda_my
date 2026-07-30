<?php

declare(strict_types=1);

/**
 * Регистрация Telegram-вебхука. ТОЛЬКО из CLI.
 *
 * Что было не так (всё три пункта — эксплуатируемые):
 *  1. Файл лежит в docroot и отдавался nginx'ом любому желающему. Заход по
 *     https://veranda.my/set_tg_webhook.php выполнял setWebhook.
 *  2. Адрес брался из $_SERVER['HTTP_HOST'], если APP_URL не задан (а в проде
 *     он пуст). Запрос с заголовком `Host: evil.com` переводил вебхук бота на
 *     чужой сервер — вместе со всем потоком сообщений и callback'ов.
 *  3. setWebhook вызывался БЕЗ secret_token. Даже безобидный заход стирал
 *     секрет на стороне Telegram, после чего WebhookSecretMiddleware пропускал
 *     бы любой подделанный запрос (авторизация в webhook'е идёт по username).
 *
 * Теперь: запуск только из консоли, APP_URL обязателен и никогда не берётся из
 * заголовков запроса, секрет передаётся всегда, если задан.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Forbidden: этот скрипт запускается только из консоли.\n";
    exit(1);
}

require_once __DIR__ . '/vendor/autoload.php';

use App\Infrastructure\Config;
use App\Infrastructure\HttpClient;
use App\Infrastructure\TelegramBotClient;

Config::load(__DIR__ . '/.env');

$token = trim((string) Config::get('TELEGRAM_BOT_TOKEN', ''));
if ($token === '') {
    fwrite(STDERR, "TELEGRAM_BOT_TOKEN не задан в .env\n");
    exit(1);
}

// Никаких фолбэков на HTTP_HOST: адрес вебхука — это конфигурация, а не то,
// что можно вывести из входящего запроса.
$appUrl = rtrim(trim((string) (Config::get('APP_URL', '') ?: Config::get('SITE_BASE_URL', ''))), '/');
if ($appUrl === '') {
    fwrite(STDERR, "APP_URL не задан в .env — укажите, например APP_URL=https://veranda.my\n");
    exit(1);
}
if (!str_starts_with($appUrl, 'https://')) {
    fwrite(STDERR, "APP_URL должен быть https:// — Telegram не принимает вебхуки по http\n");
    exit(1);
}

$secret  = trim((string) Config::get('TELEGRAM_WEBHOOK_SECRET', ''));
$hookUrl = $appUrl . '/telegram_webhook.php';

if ($secret === '') {
    fwrite(STDERR, "ВНИМАНИЕ: TELEGRAM_WEBHOOK_SECRET пуст — вебхук будет открыт для подделки.\n");
}

$bot = new TelegramBotClient($token, new HttpClient(timeoutSeconds: 15));
$ok  = $bot->setWebhook($hookUrl, $secret);

echo $ok
    ? "OK: вебхук установлен на {$hookUrl}" . ($secret !== '' ? " (с secret_token)" : " (БЕЗ секрета)") . "\n"
    : "ОШИБКА: Telegram не принял setWebhook (детали в логе telegram.api_error)\n";

exit($ok ? 0 : 1);
