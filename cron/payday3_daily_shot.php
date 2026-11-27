<?php

declare(strict_types=1);

// Ежедневная отправка последнего снимка «Итоговый баланс» во вторую группу.
// Запускается cron каждый час (см. cron/crontab.txt); отправляет только в 08:00
// по Вьетнаму и не чаще раза в день (маркер .daily_YYYYmmdd). Только из консоли.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Forbidden: скрипт запускается только из консоли.\n");
}

require_once __DIR__ . '/../vendor/autoload.php';

use App\Infrastructure\Config;
use App\Infrastructure\Database;
use App\Payday3\Services\BalanceShotStore;
use App\Payday3\Services\DbLocalSettingsRepository;
use App\Payday3\Services\JsonLocalSettingsRepository;
use App\Payday3\Services\TelegramNotifier;

Config::load(__DIR__ . '/../.env');
date_default_timezone_set(BalanceShotStore::TZ);

// Ежечасный запуск: в другие часы ничего не делаем (без записи в лог).
if (!BalanceShotStore::isDailySendHour(new DateTimeImmutable('now'))) {
    exit(0);
}

$log = static fn(string $m) => fwrite(STDOUT, '[' . date('c') . '] ' . $m . PHP_EOL);

$store = new BalanceShotStore(BalanceShotStore::defaultDir());
$today = date('Ymd');

if ($store->dailySent($today)) {
    $log("уже отправлено сегодня ({$today}), пропуск");
    exit(0);
}

$latest = $store->latest();
if ($latest === null) {
    $log('снимков нет — отправлять нечего');
    exit(0);
}

$jsonRepo = new JsonLocalSettingsRepository(primaryPath: __DIR__ . '/../payday3/local_config.json');
$repo     = new DbLocalSettingsRepository(Database::getInstance(), $jsonRepo);
$cfg      = $repo->load();

if ($cfg->dailyChatId === '') {
    $log('в настройках не задана группа для 08:00 — пропуск');
    exit(0);
}

// Токен бота @Veranda_aibot (ключ ai_tg_bot в .env). Для основных
// отправок используется TELEGRAM_BOT_TOKEN, здесь намеренно другой бот.
$token = trim(Config::get('ai_tg_bot'));
if ($token === '') {
    $log('в .env нет ai_tg_bot — отправка невозможна');
    exit(1);
}

$bytes = $store->read($latest['name']);
if ($bytes === null) {
    $log("файл снимка {$latest['name']} не читается");
    exit(1);
}

$tg     = new TelegramNotifier($repo, $token);
$result = $tg->sendPhoto(
    $bytes,
    $latest['mime'],
    'Итоговый баланс',
    $cfg->dailyChatId,
    $cfg->dailyThreadId !== '' ? $cfg->dailyThreadId : null,
);

if (!($result['ok'] ?? false)) {
    $log('ошибка Telegram: ' . ($result['error'] ?? '?'));
    exit(1);
}

$store->markDailySent($today);
$log("отправлено: {$latest['name']} → {$cfg->dailyChatId} / тема {$cfg->dailyThreadId}");
exit(0);
