<?php
declare(strict_types=1);

function tr3_api_events_for_day(array $ctx): void {
  api_json_headers(true);

  $day = trim((string)($_GET['day'] ?? ''));
  if ($day === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) api_error(400, 'Некорректная дата');

  $fallback = 'События на эту дату еще не запланировано.';
  $now = time();

  $metaRepo = $ctx['metaRepo'] ?? null;
  $canCache = is_object($metaRepo) && method_exists($metaRepo, 'getMany') && method_exists($metaRepo, 'set');
  $cacheKey = 'tr3_events_for_day_' . $day;
  if ($canCache) {
    try {
      $vals = $metaRepo->getMany([$cacheKey]);
      $raw = is_array($vals) && array_key_exists($cacheKey, $vals) ? (string)$vals[$cacheKey] : '';
      if ($raw !== '') {
        $cached = json_decode($raw, true);
        $exp = is_array($cached) && array_key_exists('expires', $cached) ? (int)$cached['expires'] : 0;
        $text = is_array($cached) && array_key_exists('text', $cached) ? trim((string)$cached['text']) : '';
        if ($exp > $now && $text !== '') {
          api_ok(['day' => $day, 'text' => $text]);
        }
      }
    } catch (\Throwable $e) {
    }
  }

  $root = dirname(__DIR__);
  $bootstrap = $root . '/testai/bootstrap.php';
  if (!file_exists($bootstrap)) api_ok(['day' => $day, 'text' => $fallback]);

  $text = $fallback;
  $okAi = false;
  try {
    $ai = require $bootstrap;
    $responder = $ai['responder'] ?? null;
    if (!is_object($responder) || !method_exists($responder, 'respond')) throw new \RuntimeException('AI responder not configured');

    $q = "Какие события есть на {$day} коротко в одну строчку? Если ничего нет — напиши, что события на ту дату еще не запланировано.";
    $html = $responder->respond($q, [], false);
    $cand = trim(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", (string)$html)));
    $cand = preg_replace('/\s+/u', ' ', $cand ?? '');
    $cand = trim((string)$cand);
    if ($cand !== '') {
      $text = $cand;
      $okAi = true;
    }
  } catch (\Throwable $e) {
    $text = $fallback;
    $okAi = false;
  }

  if ($canCache) {
    $expires = $now + ($okAi ? (12 * 3600) : (10 * 60));
    $payload = json_encode(['text' => $text, 'expires' => $expires], JSON_UNESCAPED_UNICODE);
    if ($payload !== false) {
      try { $metaRepo->set($cacheKey, $payload); } catch (\Throwable $e) {}
    }
  }

  api_ok(['day' => $day, 'text' => $text]);
}
