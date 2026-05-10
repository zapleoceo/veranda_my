<?php

declare(strict_types=1);

$root = dirname(__DIR__);

// Core database class (unchanged)
require_once $root . '/src/classes/Database.php';
// Config / env loader (unchanged)
require_once $root . '/src/classes/TestAIEnv.php';

// Infra
require_once $root . '/src/classes/testai/infra/Logger.php';
require_once $root . '/src/classes/testai/infra/GeminiClient.php';
require_once $root . '/src/classes/testai/infra/TelegramClient.php';
require_once $root . '/src/classes/testai/infra/HtmlSanitizer.php';
require_once $root . '/src/classes/testai/infra/UrlFetcher.php';

// Repositories
require_once $root . '/src/classes/testai/repository/Schema.php';
require_once $root . '/src/classes/testai/repository/MessageRepository.php';
require_once $root . '/src/classes/testai/repository/DailyRepository.php';
require_once $root . '/src/classes/testai/repository/SettingsRepository.php';
require_once $root . '/src/classes/testai/repository/KnowledgeRepository.php';
require_once $root . '/src/classes/testai/repository/MenuRepository.php';

// Services
require_once $root . '/src/classes/testai/service/KnowledgeService.php';
require_once $root . '/src/classes/testai/service/MenuService.php';
require_once $root . '/src/classes/testai/service/Responder.php';
require_once $root . '/src/classes/testai/service/DailySummaryService.php';
require_once $root . '/src/classes/testai/service/AnnouncementService.php';

// App
require_once $root . '/src/classes/testai/app/WebhookHandler.php';

// ─── Config ──────────────────────────────────────────────────────────────────

$envFile       = $root . '/.env';
$envLoadedKeys = (new \App\Classes\TestAIEnvLoader())->load($envFile);
$cfg           = \App\Classes\TestAIConfig::fromEnv($root, $envFile, $envLoadedKeys);
$cfg->applyTimezone();

// ─── Database ────────────────────────────────────────────────────────────────

$db = new \App\Classes\Database($cfg->dbHost, $cfg->dbName, $cfg->dbUser, $cfg->dbPass, $cfg->dbTableSuffix);

$tRaw      = $db->t('testai_tg_messages_raw');
$tDaily    = $db->t('testai_daily_summaries');
$tSettings = $db->t('testai_settings');
$tKb       = $db->t('testai_kb_docs');

(new \App\Classes\TestAI\Repository\Schema())->ensure($db, $tRaw, $tDaily, $tSettings, $tKb);

// ─── Repositories ────────────────────────────────────────────────────────────

$msgRepo       = new \App\Classes\TestAI\Repository\MessageRepository($db, $tRaw);
$dailyRepo     = new \App\Classes\TestAI\Repository\DailyRepository($db, $tDaily);
$settingsRepo  = new \App\Classes\TestAI\Repository\SettingsRepository($db, $tSettings);
$kbRepo        = new \App\Classes\TestAI\Repository\KnowledgeRepository($db, $tKb);
$menuRepo      = new \App\Classes\TestAI\Repository\MenuRepository($db);

// ─── Default settings (only written once) ────────────────────────────────────

$defaults = [
    'bot_identity' =>
        "Ты — бот кафе Веранда (Veranda). Отвечай коротко и по делу. " .
        "Если информации нет — не придумывай, скажи что не знаешь.",

    'bot_forbidden' =>
        "закупочные цены\nсебестоимость блюд\nзарплаты сотрудников\nданные о поставщиках",

    'bot_system_daily' =>
        "Return strict JSON only with keys: summary_text (string), events (array). " .
        "Each event: announce_date (YYYY-MM-DD), title, facts (array of strings), " .
        "confidence (0..100), sources (array of {tg_chat_id,tg_message_id}).",

    'bot_system_announce' =>
        "Return HTML only. No markdown. No scripts. Use simple tags: div,p,br,strong,em,ul,li,h2,h3,a,span.",
];

foreach ($defaults as $k => $v) {
    $settingsRepo->setIfEmpty($k, $v);
}

// ─── Infra ───────────────────────────────────────────────────────────────────

$log       = new \App\Classes\TestAI\Infra\Logger($cfg->logDir);
$gemini    = new \App\Classes\TestAI\Infra\GeminiClient($cfg->geminiKey, $cfg->geminiProxyBase(), $cfg->geminiProxyKey, $log);
$tg        = new \App\Classes\TestAI\Infra\TelegramClient($cfg->tgToken, $log);
$sanitizer = new \App\Classes\TestAI\Infra\HtmlSanitizer();
$fetcher   = new \App\Classes\TestAI\Infra\UrlFetcher($cfg->appUrl);

// ─── Services ────────────────────────────────────────────────────────────────

$knowledgeSvc    = new \App\Classes\TestAI\Service\KnowledgeService($kbRepo, $fetcher);
$menuSvc         = new \App\Classes\TestAI\Service\MenuService($menuRepo);
$responder       = new \App\Classes\TestAI\Service\Responder($cfg->geminiModel, $gemini, $sanitizer, $settingsRepo, $knowledgeSvc, $menuSvc);
$dailySvc        = new \App\Classes\TestAI\Service\DailySummaryService($cfg->geminiModel, $gemini, $msgRepo, $dailyRepo, $settingsRepo);
$announcementSvc = new \App\Classes\TestAI\Service\AnnouncementService($cfg->geminiModel, $gemini, $sanitizer, $dailyRepo, $msgRepo, $settingsRepo, __DIR__ . '/cache');

// ─── App ─────────────────────────────────────────────────────────────────────

$allowedChatIds = $cfg->allowedChatIds ? array_keys($cfg->allowedChatIds) : [];
$webhookHandler = new \App\Classes\TestAI\App\WebhookHandler(
    $cfg->geminiModel, $gemini, $tg,
    $msgRepo, $dailyRepo, $settingsRepo,
    $dailySvc, $responder, $log,
    $allowedChatIds
);

// ─── Context returned to callers ─────────────────────────────────────────────

return [
    'cfg'             => $cfg,
    'root'            => $root,
    'log'             => $log,
    'db'              => $db,
    'tRaw'            => $tRaw,
    'tDaily'          => $tDaily,
    'tSettings'       => $tSettings,
    'tKb'             => $tKb,
    'msgRepo'         => $msgRepo,
    'dailyRepo'       => $dailyRepo,
    'settingsRepo'    => $settingsRepo,
    'kbRepo'          => $kbRepo,
    'menuRepo'        => $menuRepo,
    'gemini'          => $gemini,
    'tg'              => $tg,
    'sanitizer'       => $sanitizer,
    'fetcher'         => $fetcher,
    'knowledgeSvc'    => $knowledgeSvc,
    'menuSvc'         => $menuSvc,
    'responder'       => $responder,
    'dailySvc'        => $dailySvc,
    'announcementSvc' => $announcementSvc,
    'webhookHandler'  => $webhookHandler,
    // legacy alias so webhook.php doesn't break
    'webhookSvc'      => $webhookHandler,
];
