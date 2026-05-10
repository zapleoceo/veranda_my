<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/src/classes/TestAIEnv.php';
require_once $root . '/src/classes/TestAIInfra.php';

$envFile = $root . '/.env';
$envLoadedKeys = (new \App\Classes\TestAIEnvLoader())->load($envFile);
$cfg = \App\Classes\TestAIConfig::fromEnv($root, $envFile, $envLoadedKeys);
$cfg->applyTimezone();

$token = (string)$cfg->tgToken;
$appUrl = $cfg->appUrl !== '' ? $cfg->appUrl : 'https://veranda.my';

if ($token === '') {
  echo "Missing ai_tg_bot\n";
  exit(1);
}

$hookUrl = $appUrl . '/testai/webhook';
$tg = new \App\Classes\TestAITelegramClient($token);
$resp = $tg->postJson('setWebhook', [
  'url' => $hookUrl,
  'allowed_updates' => ['message', 'edited_message', 'channel_post', 'edited_channel_post'],
]);
echo json_encode($resp ?? ['ok' => false], JSON_UNESCAPED_UNICODE) . "\n";
