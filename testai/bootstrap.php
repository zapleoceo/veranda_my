<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/src/classes/Database.php';

$envFile = $root . '/.env';
$envLoadedKeys = [];

if (file_exists($envFile)) {
  $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
  foreach ($lines as $line) {
    if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) continue;
    [$name, $value] = explode('=', $line, 2);
    $k = trim($name);
    $_ENV[$k] = trim($value);
    $envLoadedKeys[$k] = true;
  }
}

$spotTzName = trim((string)($_ENV['POSTER_SPOT_TIMEZONE'] ?? ''));
if ($spotTzName === '' || !in_array($spotTzName, timezone_identifiers_list(), true)) $spotTzName = 'Asia/Ho_Chi_Minh';
$apiTzName = trim((string)($_ENV['POSTER_API_TIMEZONE'] ?? ''));
if ($apiTzName === '' || !in_array($apiTzName, timezone_identifiers_list(), true)) $apiTzName = $spotTzName;
date_default_timezone_set($apiTzName);

$dbHost = $_ENV['DB_HOST'] ?? 'localhost';
$dbName = $_ENV['DB_NAME'] ?? 'veranda_my';
$dbUser = $_ENV['DB_USER'] ?? 'veranda_my';
$dbPass = $_ENV['DB_PASS'] ?? '';
$tableSuffix = (string)($_ENV['DB_TABLE_SUFFIX'] ?? '');

$testaiTgToken = (string)($_ENV['ai_tg_bot'] ?? '');
$geminiKey = (string)($_ENV['gemini_key'] ?? '');
$allowedChatsRaw = (string)($_ENV['TESTAI_ALLOWED_CHAT_IDS'] ?? '');
$adminKey = (string)($_ENV['TESTAI_ADMIN_KEY'] ?? '');
$geminiModel = trim((string)($_ENV['TESTAI_GEMINI_MODEL'] ?? 'gemini-2.5-flash'));

$db = new \App\Classes\Database($dbHost, $dbName, $dbUser, $dbPass, $tableSuffix);

$tRaw = $db->t('testai_tg_messages_raw');
$tDaily = $db->t('testai_daily_summaries');
$tSettings = $db->t('testai_settings');

try {
  $pdo = $db->getPdo();
  $pdo->exec("CREATE TABLE IF NOT EXISTS {$tRaw} (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tg_chat_id BIGINT NOT NULL,
    tg_chat_type VARCHAR(16) NOT NULL,
    tg_chat_title VARCHAR(255) NULL,
    tg_message_id BIGINT NOT NULL,
    tg_user_id BIGINT NULL,
    tg_username VARCHAR(64) NULL,
    tg_name VARCHAR(128) NULL,
    received_at DATETIME NOT NULL,
    text TEXT NOT NULL,
    media_type VARCHAR(16) NULL,
    media_file_id VARCHAR(255) NULL,
    media_file_unique_id VARCHAR(255) NULL,
    media_mime VARCHAR(128) NULL,
    media_duration_sec INT NULL,
    media_text TEXT NULL,
    meta_json TEXT NULL,
    UNIQUE KEY uniq_chat_msg (tg_chat_id, tg_message_id),
    KEY idx_received_at (received_at),
    KEY idx_chat_time (tg_chat_id, received_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

  $pdo->exec("CREATE TABLE IF NOT EXISTS {$tDaily} (
    day DATE NOT NULL PRIMARY KEY,
    summary_text TEXT NOT NULL,
    events_json TEXT NOT NULL,
    created_at DATETIME NOT NULL,
    KEY idx_created_at (created_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

  $pdo->exec("CREATE TABLE IF NOT EXISTS {$tSettings} (
    k VARCHAR(64) NOT NULL PRIMARY KEY,
    v TEXT NOT NULL,
    updated_at DATETIME NOT NULL
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (\Throwable $e) {}

$allowedChatIds = null;
if (trim($allowedChatsRaw) !== '') {
  $ids = array_values(array_filter(array_map('trim', explode(',', $allowedChatsRaw)), fn($x) => $x !== ''));
  $allowedChatIds = $ids ? array_fill_keys($ids, true) : null;
}

return [
  'root' => $root,
  'envFile' => $envFile,
  'envLoadedKeys' => $envLoadedKeys,
  'db' => $db,
  'tRaw' => $tRaw,
  'tDaily' => $tDaily,
  'tSettings' => $tSettings,
  'tgToken' => $testaiTgToken,
  'geminiKey' => $geminiKey,
  'geminiModel' => $geminiModel,
  'allowedChatIds' => $allowedChatIds,
  'adminKey' => $adminKey,
];
