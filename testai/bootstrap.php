<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/src/classes/Database.php';
require_once $root . '/src/classes/TestAIEnv.php';
require_once $root . '/src/classes/TestAIInfra.php';
require_once $root . '/src/classes/TestAIRepositories.php';
require_once $root . '/src/classes/TestAIServices.php';

$envFile = $root . '/.env';
$envLoadedKeys = (new \App\Classes\TestAIEnvLoader())->load($envFile);
$cfg = \App\Classes\TestAIConfig::fromEnv($root, $envFile, $envLoadedKeys);
$cfg->applyTimezone();

$db = new \App\Classes\Database($cfg->dbHost, $cfg->dbName, $cfg->dbUser, $cfg->dbPass, $cfg->dbTableSuffix);

$tRaw = $db->t('testai_tg_messages_raw');
$tDaily = $db->t('testai_daily_summaries');
$tSettings = $db->t('testai_settings');
$tKb = $db->t('testai_kb_docs');

(new \App\Classes\TestAIDbSchema())->ensure($db, $tRaw, $tDaily, $tSettings, $tKb);

$rawRepo = new \App\Classes\TestAIRawMessagesRepository($db, $tRaw);
$dailyRepo = new \App\Classes\TestAIDailySummariesRepository($db, $tDaily);
$settingsRepo = new \App\Classes\TestAISettingsRepository($db, $tSettings);
$kbRepo = new \App\Classes\TestAIKnowledgeRepository($db, $tKb);

$log = new \App\Classes\TestAILogger($cfg->logDir);
$tg = new \App\Classes\TestAITelegramClient($cfg->tgToken, $log);
$gemini = new \App\Classes\TestAIGeminiClient($cfg->geminiKey, $cfg->geminiProxyBase(), $cfg->geminiProxyKey, $log);
$sanitizer = new \App\Classes\TestAIHtmlSanitizer();
$knowledgeSvc = new \App\Classes\TestAIKnowledgeService($cfg, $kbRepo, $log);

$announcementSvc = new \App\Classes\TestAIAnnouncementService($cfg, $gemini, $sanitizer, $dailyRepo, $rawRepo, __DIR__ . '/cache');
$dailySvc = new \App\Classes\TestAIDailySummaryService($cfg, $gemini, $rawRepo, $dailyRepo);
$webhookSvc = new \App\Classes\TestAIWebhookService($cfg, $gemini, $tg, $sanitizer, $rawRepo, $dailyRepo, $dailySvc, $settingsRepo, $knowledgeSvc, $log);

return [
  'cfg' => $cfg,
  'root' => $root,
  'envFile' => $envFile,
  'envLoadedKeys' => $envLoadedKeys,
  'log' => $log,
  'db' => $db,
  'tRaw' => $tRaw,
  'tDaily' => $tDaily,
  'tSettings' => $tSettings,
  'tKb' => $tKb,
  'rawRepo' => $rawRepo,
  'dailyRepo' => $dailyRepo,
  'settingsRepo' => $settingsRepo,
  'kbRepo' => $kbRepo,
  'tg' => $tg,
  'gemini' => $gemini,
  'sanitizer' => $sanitizer,
  'knowledgeSvc' => $knowledgeSvc,
  'announcementSvc' => $announcementSvc,
  'dailySvc' => $dailySvc,
  'webhookSvc' => $webhookSvc,
];
