<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/src/classes/TestAIInfra.php';

function testai_gemini_proxy_base(): string {
  $v = trim((string)($_ENV['GEMINI_PROXY_URL'] ?? ''));
  if ($v !== '') return rtrim($v, '/');
  $appUrl = trim((string)($_ENV['APP_URL'] ?? ''));
  if ($appUrl === '' || !preg_match('#^https?://#i', $appUrl)) return '';
  return rtrim($appUrl, '/') . '/__gemini';
}

function testai_gemini_proxy_key(): string {
  $v = trim((string)($_ENV['CLOUDFLARE_TURN_API_TOKEN'] ?? ''));
  if ($v !== '') return $v;
  return trim((string)($_ENV['GEMINI_PROXY_KEY'] ?? ''));
}

function testai_gemini_can_call(string $apiKey): bool {
  $c = new \App\Classes\TestAIGeminiClient($apiKey, testai_gemini_proxy_base(), testai_gemini_proxy_key());
  return $c->canCall();
}

function testai_gemini_generate(string $apiKey, string $model, array $parts, array $opts = []): array {
  $c = new \App\Classes\TestAIGeminiClient($apiKey, testai_gemini_proxy_base(), testai_gemini_proxy_key());
  return $c->generate($model, $parts, $opts);
}

function testai_gemini_text(array $resp): string {
  $c = new \App\Classes\TestAIGeminiClient('', '', '');
  return $c->text($resp);
}

function testai_gemini_json(array $resp): ?array {
  $c = new \App\Classes\TestAIGeminiClient('', '', '');
  return $c->json($resp);
}
