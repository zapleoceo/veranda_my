<?php
declare(strict_types=1);

function testai_gemini_generate(string $apiKey, string $model, array $parts, array $opts = []): array {
  $endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent?key=' . rawurlencode($apiKey);
  $payload = [
    'contents' => [
      [
        'role' => 'user',
        'parts' => $parts,
      ],
    ],
  ];
  if (!empty($opts['system'])) {
    $payload['systemInstruction'] = ['parts' => [['text' => (string)$opts['system']]]];
  }
  if (!empty($opts['temperature']) || array_key_exists('temperature', $opts)) {
    $payload['generationConfig'] = $payload['generationConfig'] ?? [];
    $payload['generationConfig']['temperature'] = (float)$opts['temperature'];
  }
  if (!empty($opts['maxOutputTokens']) || array_key_exists('maxOutputTokens', $opts)) {
    $payload['generationConfig'] = $payload['generationConfig'] ?? [];
    $payload['generationConfig']['maxOutputTokens'] = (int)$opts['maxOutputTokens'];
  }
  if (!empty($opts['responseMimeType'])) {
    $payload['generationConfig'] = $payload['generationConfig'] ?? [];
    $payload['generationConfig']['responseMimeType'] = (string)$opts['responseMimeType'];
  }

  $ch = curl_init($endpoint);
  curl_setopt($ch, CURLOPT_POST, true);
  curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
  curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_TIMEOUT, 25);
  $resp = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  curl_close($ch);

  $data = json_decode(is_string($resp) ? $resp : '', true);
  if (!is_array($data)) $data = [];
  $data['_http_code'] = (int)$code;
  return $data;
}

function testai_gemini_text(array $resp): string {
  if (!isset($resp['candidates'][0]['content']['parts']) || !is_array($resp['candidates'][0]['content']['parts'])) return '';
  $out = '';
  foreach ($resp['candidates'][0]['content']['parts'] as $p) {
    if (is_array($p) && array_key_exists('text', $p)) $out .= (string)$p['text'];
  }
  return trim($out);
}

function testai_gemini_json(array $resp): ?array {
  $t = testai_gemini_text($resp);
  if ($t === '') return null;
  $t = preg_replace('/^```(?:json)?\s*/i', '', $t);
  $t = preg_replace('/\s*```$/', '', $t);
  $j = json_decode($t, true);
  if (is_array($j)) return $j;
  if (preg_match('/\{[\s\S]*\}/', $t, $m)) {
    $j = json_decode($m[0], true);
    return is_array($j) ? $j : null;
  }
  return null;
}

