<?php
declare(strict_types=1);

function testai_sanitize_html(string $html): string {
  $root = dirname(__DIR__);
  require_once $root . '/src/classes/TestAIInfra.php';
  $s = new \App\Classes\TestAIHtmlSanitizer();
  return $s->sanitizeHtml($html);
}

function testai_sanitize_telegram_html(string $html): string {
  $root = dirname(__DIR__);
  require_once $root . '/src/classes/TestAIInfra.php';
  $s = new \App\Classes\TestAIHtmlSanitizer();
  return $s->sanitizeTelegramHtml($html);
}
