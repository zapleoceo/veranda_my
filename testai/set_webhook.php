<?php
declare(strict_types=1);

$ctx = require __DIR__ . '/bootstrap.php';
$cfg = $ctx['cfg'];
$tg = $ctx['tg'];

$adminKey = (string)($cfg->adminKey ?? '');
if ($adminKey !== '' && (string)($_GET['key'] ?? '') !== $adminKey) { echo "forbidden\n"; exit(0); }

$token = (string)($cfg->tgToken ?? '');
$appUrl = $cfg->appUrl !== '' ? $cfg->appUrl : 'https://veranda.my';

if ($token === '') {
  echo "Missing ai_tg_bot\n";
  exit(1);
}

$hookUrl = $appUrl . '/testai/webhook';
$resp = $tg->postJson('setWebhook', [
  'url' => $hookUrl,
  'allowed_updates' => ['message', 'edited_message', 'channel_post', 'edited_channel_post'],
]);
echo json_encode($resp ?? ['ok' => false], JSON_UNESCAPED_UNICODE) . "\n";
