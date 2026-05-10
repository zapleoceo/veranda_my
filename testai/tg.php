<?php
declare(strict_types=1);

function testai_tg_post_json(string $token, string $method, array $payload): ?array {
  $root = dirname(__DIR__);
  require_once $root . '/src/classes/TestAIInfra.php';
  $c = new \App\Classes\TestAITelegramClient($token);
  return $c->postJson($method, $payload);
}

function testai_tg_get_file_url(string $token, string $fileId): ?array {
  $root = dirname(__DIR__);
  require_once $root . '/src/classes/TestAIInfra.php';
  $c = new \App\Classes\TestAITelegramClient($token);
  return $c->getFileUrl($fileId);
}

function testai_fetch_bytes(string $url, int $timeout = 25): ?string {
  $root = dirname(__DIR__);
  require_once $root . '/src/classes/TestAIInfra.php';
  $c = new \App\Classes\TestAITelegramClient('');
  return $c->fetchBytes($url, $timeout);
}

function testai_tg_send_message(string $token, string $chatId, string $html, ?int $replyToMessageId = null): bool {
  $root = dirname(__DIR__);
  require_once $root . '/src/classes/TestAIInfra.php';
  $c = new \App\Classes\TestAITelegramClient($token);
  return $c->sendMessage($chatId, $html, $replyToMessageId);
}
