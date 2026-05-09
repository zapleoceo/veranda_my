<?php
declare(strict_types=1);

function testai_tg_post_json(string $token, string $method, array $payload): ?array {
  if (trim($token) === '') return null;
  $apiBase = "https://api.telegram.org/bot{$token}";
  $ch = curl_init("{$apiBase}/{$method}");
  curl_setopt($ch, CURLOPT_POST, true);
  curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
  curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_TIMEOUT, 20);
  $resp = curl_exec($ch);
  curl_close($ch);
  if ($resp === false || $resp === null || $resp === '') return null;
  $data = json_decode($resp, true);
  return is_array($data) ? $data : null;
}

function testai_tg_get_file_url(string $token, string $fileId): ?array {
  $info = testai_tg_post_json($token, 'getFile', ['file_id' => $fileId]);
  if (!is_array($info) || empty($info['ok']) || !is_array($info['result'] ?? null)) return null;
  $filePath = (string)($info['result']['file_path'] ?? '');
  if ($filePath === '') return null;
  $url = "https://api.telegram.org/file/bot{$token}/{$filePath}";
  return ['url' => $url, 'file_path' => $filePath, 'file_size' => $info['result']['file_size'] ?? null];
}

function testai_fetch_bytes(string $url, int $timeout = 25): ?string {
  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
  $resp = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  curl_close($ch);
  if (!is_string($resp) || $resp === '' || $code < 200 || $code >= 300) return null;
  return $resp;
}

function testai_tg_send_message(string $token, string $chatId, string $html, ?int $replyToMessageId = null): bool {
  $payload = [
    'chat_id' => $chatId,
    'text' => $html,
    'parse_mode' => 'HTML',
    'disable_web_page_preview' => true,
  ];
  if ($replyToMessageId !== null && $replyToMessageId > 0) $payload['reply_to_message_id'] = $replyToMessageId;
  $r = testai_tg_post_json($token, 'sendMessage', $payload);
  return is_array($r) && !empty($r['ok']);
}
