<?php
require_once __DIR__ . '/src/classes/PosterAPI.php';
require_once __DIR__ . '/src/classes/Database.php';

$db = new \App\Classes\Database();
$token = $db->query("SELECT token FROM poster_accounts LIMIT 1")->fetchColumn();
$api = new \App\Classes\PosterAPI($token);

$resYmd = $api->request('finance.getTransactions', ['dateFrom' => '20260413', 'dateTo' => '20260413']);
$resDmY = $api->request('finance.getTransactions', ['dateFrom' => '13042026', 'dateTo' => '13042026']);

echo "Ymd count: " . (is_array($resYmd) ? count($resYmd) : 'error') . "\n";
echo "dmY count: " . (is_array($resDmY) ? count($resDmY) : 'error') . "\n";
