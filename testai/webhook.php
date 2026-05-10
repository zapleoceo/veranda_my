<?php
declare(strict_types=1);

$ctx = require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/gemini.php';
require_once __DIR__ . '/tg.php';
require_once __DIR__ . '/html_sanitize.php';

$db = $ctx['db'];
$tRaw = $ctx['tRaw'];
$tSettings = $ctx['tSettings'] ?? null;
$tgToken = (string)$ctx['tgToken'];
$geminiKey = (string)$ctx['geminiKey'];
$geminiModel = (string)$ctx['geminiModel'];
$allowed = $ctx['allowedChatIds'];

$raw = file_get_contents('php://input');
$update = json_decode(is_string($raw) ? $raw : '', true);
if (!is_array($update)) { echo 'ok'; exit; }
if ($tgToken === '') { echo 'ok'; exit; }

$msg = null;
if (!empty($update['message']) && is_array($update['message'])) $msg = $update['message'];
if (!$msg && !empty($update['edited_message']) && is_array($update['edited_message'])) $msg = $update['edited_message'];
if (!$msg && !empty($update['channel_post']) && is_array($update['channel_post'])) $msg = $update['channel_post'];
if (!$msg && !empty($update['edited_channel_post']) && is_array($update['edited_channel_post'])) $msg = $update['edited_channel_post'];
if (!$msg) { echo 'ok'; exit; }

$chat = is_array($msg['chat'] ?? null) ? $msg['chat'] : [];
$chatId = isset($chat['id']) ? (string)$chat['id'] : '';
if ($chatId === '') { echo 'ok'; exit; }
if (is_array($allowed) && !isset($allowed[$chatId])) { echo 'ok'; exit; }

$chatType = (string)($chat['type'] ?? 'unknown');
$chatTitle = (string)($chat['title'] ?? '');
$messageId = isset($msg['message_id']) ? (int)$msg['message_id'] : 0;
$ts = isset($msg['date']) ? (int)$msg['date'] : time();
$receivedAt = date('Y-m-d H:i:s', $ts);

$from = is_array($msg['from'] ?? null) ? $msg['from'] : [];
$userId = isset($from['id']) ? (int)$from['id'] : null;
$username = trim((string)($from['username'] ?? ''));
$name = trim((string)($from['first_name'] ?? '') . ' ' . (string)($from['last_name'] ?? ''));

$text = trim((string)($msg['text'] ?? ''));
$caption = trim((string)($msg['caption'] ?? ''));
if ($text === '' && $caption !== '') $text = $caption;
if ($text === '') $text = '';

$needReply = false;
$queryText = $text;
if ($chatType === 'private') {
  $needReply = $queryText !== '';
} else {
  if (preg_match('/^\/(?:ai|ask)(?:@\w+)?\s+([\s\S]+)$/u', $queryText, $m)) {
    $needReply = true;
    $queryText = trim((string)($m[1] ?? ''));
  }
}

$mediaType = null;
$mediaFileId = null;
$mediaFileUniqueId = null;
$mediaMime = null;
$mediaDurationSec = null;

if (!empty($msg['voice']) && is_array($msg['voice'])) {
  $mediaType = 'voice';
  $mediaFileId = (string)($msg['voice']['file_id'] ?? '');
  $mediaFileUniqueId = (string)($msg['voice']['file_unique_id'] ?? '');
  $mediaMime = (string)($msg['voice']['mime_type'] ?? 'audio/ogg');
  $mediaDurationSec = isset($msg['voice']['duration']) ? (int)$msg['voice']['duration'] : null;
} elseif (!empty($msg['audio']) && is_array($msg['audio'])) {
  $mediaType = 'audio';
  $mediaFileId = (string)($msg['audio']['file_id'] ?? '');
  $mediaFileUniqueId = (string)($msg['audio']['file_unique_id'] ?? '');
  $mediaMime = (string)($msg['audio']['mime_type'] ?? 'audio/mpeg');
  $mediaDurationSec = isset($msg['audio']['duration']) ? (int)$msg['audio']['duration'] : null;
} elseif (!empty($msg['photo']) && is_array($msg['photo'])) {
  $mediaType = 'photo';
  $best = null;
  foreach ($msg['photo'] as $p) {
    if (!is_array($p) || empty($p['file_id'])) continue;
    if (!$best) { $best = $p; continue; }
    $a = (int)($p['file_size'] ?? 0);
    $b = (int)($best['file_size'] ?? 0);
    if ($a >= $b) $best = $p;
  }
  if (is_array($best)) {
    $mediaFileId = (string)($best['file_id'] ?? '');
    $mediaFileUniqueId = (string)($best['file_unique_id'] ?? '');
    $mediaMime = 'image/jpeg';
  }
}

$mediaText = null;

$meta = [
  'has_media' => $mediaType ? 1 : 0,
];
$metaJson = json_encode($meta, JSON_UNESCAPED_UNICODE);
if ($metaJson === false) $metaJson = '{}';

try {
  $db->query(
    "INSERT INTO {$tRaw}
      (tg_chat_id, tg_chat_type, tg_chat_title, tg_message_id, tg_user_id, tg_username, tg_name, received_at, text,
       media_type, media_file_id, media_file_unique_id, media_mime, media_duration_sec, media_text, meta_json)
     VALUES
      (?, ?, NULLIF(?, ''), ?, ?, NULLIF(?, ''), NULLIF(?, ''), ?, ?,
       NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''), ?, NULLIF(?, ''), ?)
     ON DUPLICATE KEY UPDATE
      tg_chat_type = VALUES(tg_chat_type),
      tg_chat_title = VALUES(tg_chat_title),
      tg_user_id = VALUES(tg_user_id),
      tg_username = VALUES(tg_username),
      tg_name = VALUES(tg_name),
      received_at = VALUES(received_at),
      text = VALUES(text),
      media_type = VALUES(media_type),
      media_file_id = VALUES(media_file_id),
      media_file_unique_id = VALUES(media_file_unique_id),
      media_mime = VALUES(media_mime),
      media_duration_sec = VALUES(media_duration_sec),
      media_text = IF(VALUES(media_text) IS NULL OR VALUES(media_text) = '', media_text, VALUES(media_text)),
      meta_json = VALUES(meta_json)",
    [
      $chatId,
      $chatType,
      $chatTitle,
      $messageId,
      $userId,
      ltrim(strtolower($username), '@'),
      $name,
      $receivedAt,
      $text,
      $mediaType,
      $mediaFileId,
      $mediaFileUniqueId,
      $mediaMime,
      $mediaDurationSec,
      $mediaText,
      $metaJson,
    ]
  );
} catch (\Throwable $e) {}

if (testai_gemini_can_call($geminiKey) && $mediaType && $mediaFileId) {
  $fileInfo = testai_tg_get_file_url($tgToken, $mediaFileId);
  $fileSize = is_array($fileInfo) ? (int)($fileInfo['file_size'] ?? 0) : 0;
  if (is_array($fileInfo) && !empty($fileInfo['url']) && $fileSize > 0 && $fileSize <= 15_000_000) {
    $bytes = testai_fetch_bytes((string)$fileInfo['url'], 25);
    if (is_string($bytes) && $bytes !== '') {
      $b64 = base64_encode($bytes);
      $system = 'Return strict JSON only: {"text":"...","lang":"","confidence":0}';
      $prompt = 'Transcribe audio or extract visible text from the media. Return only JSON.';
      $resp = testai_gemini_generate($geminiKey, $geminiModel, [
        ['text' => $prompt],
        ['inline_data' => ['mime_type' => $mediaMime ?: 'application/octet-stream', 'data' => $b64]],
      ], ['system' => $system, 'temperature' => 0.2, 'maxOutputTokens' => 1000, 'responseMimeType' => 'application/json']);
      $j = testai_gemini_json($resp);
      if (is_array($j) && isset($j['text'])) {
        $mt = trim((string)$j['text']);
        if ($mt !== '') $mediaText = $mt;
      }
    }
  }
}

if ($mediaText !== null && trim($mediaText) !== '') {
  try {
    $db->query(
      "UPDATE {$tRaw}
       SET media_text = ?
       WHERE tg_chat_id = ? AND tg_message_id = ?
       LIMIT 1",
      [$mediaText, $chatId, $messageId]
    );
  } catch (\Throwable $e) {}
}

echo 'ok';

if (!$needReply || $queryText === '' || !testai_gemini_can_call($geminiKey)) exit;

$botPrompt = '';
if (is_string($tSettings) && $tSettings !== '') {
  try {
    $row = $db->query("SELECT v FROM {$tSettings} WHERE k = ? LIMIT 1", ['bot_prompt'])->fetch();
    if (is_array($row)) $botPrompt = (string)($row['v'] ?? '');
  } catch (\Throwable $e) {}
}

$ctxMsgs = [];
try {
  $rows = $db->query(
    "SELECT received_at, tg_username, tg_name, text, media_text
     FROM {$tRaw}
     WHERE tg_chat_id = ?
     ORDER BY received_at DESC
     LIMIT 30",
    [$chatId]
  )->fetchAll();
  foreach (is_array($rows) ? array_reverse($rows) : [] as $r) {
    if (!is_array($r)) continue;
    $u = trim((string)($r['tg_username'] ?? ''));
    if ($u === '') $u = trim((string)($r['tg_name'] ?? ''));
    $t = trim((string)($r['text'] ?? ''));
    $mt = trim((string)($r['media_text'] ?? ''));
    $combined = trim($t . "\n" . ($mt !== '' ? ('[media]\n' . $mt) : ''));
    if ($combined === '') continue;
    if (mb_strlen($combined) > 1200) $combined = mb_substr($combined, 0, 1200) . '…';
    $ctxMsgs[] = ['at' => (string)($r['received_at'] ?? ''), 'from' => $u, 'text' => $combined];
  }
} catch (\Throwable $e) {}

$system = trim($botPrompt);
if ($system !== '') $system .= "\n\n";
$system .= "You are a Telegram bot assistant. Reply in Telegram-compatible HTML only. No markdown. Allowed tags: b,strong,i,em,u,ins,s,strike,del,code,pre,a,br. Do not use div/p/ul/ol/li/h1-h6 tags. Keep it concise.";

$payload = [
  'chat_id' => $chatId,
  'chat_type' => $chatType,
  'chat_title' => $chatTitle,
  'user' => [
    'id' => $userId,
    'username' => $username,
    'name' => $name,
  ],
  'message_id' => $messageId,
  'question' => $queryText,
  'context' => $ctxMsgs,
];

$resp = testai_gemini_generate(
  $geminiKey,
  $geminiModel,
  [['text' => json_encode($payload, JSON_UNESCAPED_UNICODE)]],
  ['system' => $system, 'temperature' => 0.35, 'maxOutputTokens' => 1200]
);

$html = testai_gemini_text($resp);
$html = testai_sanitize_telegram_html($html);
if ($html === '') {
  $err = '';
  if (is_array($resp['error'] ?? null)) {
    $err = trim((string)($resp['error']['message'] ?? ''));
  }
  $plain = testai_gemini_text($resp);
  $plain = trim(strip_tags($plain));
  if ($plain !== '') {
    if (mb_strlen($plain) > 3500) $plain = mb_substr($plain, 0, 3500) . '…';
    $html = htmlspecialchars($plain, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  } elseif ($err !== '') {
    if (mb_strlen($err) > 900) $err = mb_substr($err, 0, 900) . '…';
    $html = 'Gemini error: ' . htmlspecialchars($err, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  } else {
    $code = (int)($resp['_http_code'] ?? 0);
    $html = $code ? ("Gemini пустой ответ (HTTP {$code}).") : 'Не получилось сформировать ответ.';
  }
}
testai_tg_send_message($tgToken, $chatId, $html, $messageId);
