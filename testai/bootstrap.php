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

$defaults = [
  'bot_system_base' => "You are an assistant for a restaurant. Be concise and accurate. If information is missing, do not invent; ask for clarification or suggest contacting staff.",
  'bot_system_chat' => "Reply in Telegram-compatible HTML only. No markdown. Allowed tags: b,strong,i,em,u,ins,s,strike,del,code,pre,a. Do not use div/p/ul/ol/li/h1-h6 tags. Do not use <br> tag; use plain newlines instead.",
  'bot_system_announce' => "Return HTML only. No markdown. No scripts. Use simple tags: div,p,br,strong,em,ul,li,h2,h3,a,span.",
  'bot_system_daily' => "Return strict JSON only with keys: summary_text (string), events (array). Each event: announce_date (YYYY-MM-DD), title, facts (array of strings), confidence (0..100), sources (array of {tg_chat_id,tg_message_id}).",
  'bot_lang_chat' => 'auto',
  'bot_lang_announce' => 'ru',
  'bot_lang_daily' => 'ru',
  'bot_instr_map' => json_encode([
    'chat' => ['common_prompt' => 1, 'system_base' => 1, 'system_chat' => 1, 'system_daily' => 0, 'system_announce' => 0],
    'daily' => ['common_prompt' => 1, 'system_base' => 1, 'system_chat' => 0, 'system_daily' => 1, 'system_announce' => 0],
    'announce' => ['common_prompt' => 1, 'system_base' => 1, 'system_chat' => 0, 'system_daily' => 0, 'system_announce' => 1],
  ], JSON_UNESCAPED_UNICODE),
  'bot_behavior_json' => json_encode([
    'agent' => [
      'enable' => 1,
      'max_calls' => 3,
      'plan_temp' => 0.1,
      'final_temp' => 0.35,
      'final_max_tokens' => 1200,
      'allow_daily_generate' => 0,
    ],
    'tools' => [
      ['name' => 'kb_search', 'enabled' => 1, 'desc' => 'Search knowledge base by query. args: {query,limit}'],
      ['name' => 'kb_fetch_url', 'enabled' => 1, 'desc' => 'Fetch veranda.my URL and extract text. args: {url,max_len}'],
      ['name' => 'daily_get', 'enabled' => 1, 'desc' => 'Get daily summary and events for day from DB. args: {day}'],
      ['name' => 'daily_generate', 'enabled' => 0, 'desc' => 'Generate daily summary for day (costly). args: {day}'],
      ['name' => 'menu_breakfasts', 'enabled' => 1, 'desc' => 'List breakfasts from menu. args: {limit}'],
      ['name' => 'menu_most_expensive', 'enabled' => 1, 'desc' => 'Most expensive kitchen dish. args: {}'],
      ['name' => 'menu_count_kitchen', 'enabled' => 1, 'desc' => 'Count kitchen dishes. args: {}'],
    ],
    'kb' => [
      'enable' => 1,
      'check_triggers' => ['посмотри в базе знаний', 'проверь в базе знаний', 'база знаний', 'kb', 'knowledge base'],
      'live_fetch_enable' => 1,
      'live_fetch_max_docs' => 2,
      'live_fetch_max_len' => 60000,
    ],
    'chat' => [
      'system_append' => "Use payload.tool_results. Do not invent. Prefer using tool results for menu, prices, announcements, and facts. If tool results are empty, say you don't have that info.",
    ],
    'menu_service' => [
      'enable' => 1,
      'menu_url' => 'https://veranda.my/links/menu.php',
      'max_len' => 60000,
    ],
  ], JSON_UNESCAPED_UNICODE),
];
foreach ($defaults as $k => $v) {
  $row = $settingsRepo->getKey($k);
  $val = trim((string)($row['v'] ?? ''));
  if ($val === '') $settingsRepo->setKey($k, (string)$v, date('Y-m-d H:i:s'));
}

$log = new \App\Classes\TestAILogger($cfg->logDir);
$tg = new \App\Classes\TestAITelegramClient($cfg->tgToken, $log);
$gemini = new \App\Classes\TestAIGeminiClient($cfg->geminiKey, $cfg->geminiProxyBase(), $cfg->geminiProxyKey, $log);
$sanitizer = new \App\Classes\TestAIHtmlSanitizer();
$knowledgeSvc = new \App\Classes\TestAIKnowledgeService($cfg, $kbRepo, $settingsRepo, $log);
$menuSvc = new \App\Classes\TestAIMenuService($knowledgeSvc, $settingsRepo);

$announcementSvc = new \App\Classes\TestAIAnnouncementService($cfg, $gemini, $sanitizer, $dailyRepo, $rawRepo, $settingsRepo, __DIR__ . '/cache');
$dailySvc = new \App\Classes\TestAIDailySummaryService($cfg, $gemini, $rawRepo, $dailyRepo, $settingsRepo);
$agentSvc = new \App\Classes\TestAIChatAgentService($cfg, $gemini, $sanitizer, $settingsRepo, $knowledgeSvc, $menuSvc, $dailyRepo, $dailySvc, $log);
$webhookSvc = new \App\Classes\TestAIWebhookService($cfg, $gemini, $tg, $sanitizer, $rawRepo, $dailyRepo, $dailySvc, $settingsRepo, $knowledgeSvc, $menuSvc, $agentSvc, $log);

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
  'menuSvc' => $menuSvc,
  'agentSvc' => $agentSvc,
  'announcementSvc' => $announcementSvc,
  'dailySvc' => $dailySvc,
  'webhookSvc' => $webhookSvc,
];
