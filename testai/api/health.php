<?php
declare(strict_types=1);

$dbOk = false;
try {
  $row = $db->query("SELECT 1 AS ok")->fetch();
  $dbOk = is_array($row) && (int)($row['ok'] ?? 0) === 1;
} catch (\Throwable $e) {}
$rawTotals = $rawRepo->getTotals();
$dailyTotal = $dailyRepo->countAll();

$hook = null;
$info = $tg->getWebhookInfo();
if (is_array($info) && isset($info['result']) && is_array($info['result'])) {
  $r = $info['result'];
  $hook = [
    'url' => (string)($r['url'] ?? ''),
    'pending_update_count' => (int)($r['pending_update_count'] ?? 0),
    'last_error_date' => (int)($r['last_error_date'] ?? 0),
    'last_error_message' => (string)($r['last_error_message'] ?? ''),
  ];
}

$ok([
  'db_ok' => $dbOk,
  'has_ai_tg_bot' => $tg->hasToken(),
  'gemini_can_call' => $gemini->canCall(),
  'gemini_model' => (string)$cfg->geminiModel,
  'gemini_proxy_base' => (string)$cfg->geminiProxyBase(),
  'env_file_exists' => $envFile !== '' ? file_exists($envFile) : false,
  'env_keys_loaded' => array_values(array_slice($envLoadedKeys, 0, 50)),
  'allowed_chats_configured' => is_array($allowed),
  'allowed_chats_count' => is_array($allowed) ? count($allowed) : 0,
  'raw_total' => (int)($rawTotals['raw_total'] ?? 0),
  'raw_last_received_at' => (string)($rawTotals['raw_last_received_at'] ?? ''),
  'daily_total' => $dailyTotal,
  'webhook' => $hook,
]);
exit;

