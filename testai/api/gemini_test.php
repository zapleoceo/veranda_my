<?php
declare(strict_types=1);

$requireAdmin();

if (!$gemini->canCall()) {
  $bad('missing_gemini_key');
  exit;
}
$q = trim((string)($_GET['q'] ?? 'Say ok'));
if ($q === '') $q = 'Say ok';
if (mb_strlen($q) > 500) $q = mb_substr($q, 0, 500);
$resp = $gemini->generate(
  (string)$cfg->geminiModel,
  [['text' => $q]],
  ['system' => 'Reply with plain text only.', 'temperature' => 0.0, 'maxOutputTokens' => 50, 'tag' => 'gemini_test']
);
$txt = $gemini->text($resp);
$err = '';
if (is_array($resp['error'] ?? null)) $err = (string)($resp['error']['message'] ?? '');
$ok([
  'http_code' => (int)($resp['_http_code'] ?? 0),
  'has_candidates' => !empty($resp['candidates']),
  'text_len' => mb_strlen($txt),
  'error' => $err,
]);
exit;

