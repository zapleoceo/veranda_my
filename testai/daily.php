<?php
declare(strict_types=1);

$ctx = require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/gemini.php';

$db = $ctx['db'];
$tRaw = $ctx['tRaw'];
$tDaily = $ctx['tDaily'];
$geminiKey = (string)$ctx['geminiKey'];
$geminiModel = (string)$ctx['geminiModel'];

if (!testai_gemini_can_call($geminiKey)) { echo "missing gemini_key\n"; exit(0); }

$day = trim((string)($_GET['day'] ?? ''));
if ($day === '') $day = date('Y-m-d', strtotime('-1 day'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) { echo "bad day\n"; exit(0); }

$from = $day . ' 00:00:00';
$to = $day . ' 23:59:59';

$rows = [];
try {
  $rows = $db->query(
    "SELECT tg_chat_id, tg_message_id, tg_chat_title, tg_username, tg_name, received_at, text, media_text
     FROM {$tRaw}
     WHERE received_at BETWEEN ? AND ?
     ORDER BY received_at ASC",
    [$from, $to]
  )->fetchAll();
} catch (\Throwable $e) { $rows = []; }

$items = [];
foreach (is_array($rows) ? $rows : [] as $r) {
  if (!is_array($r)) continue;
  $txt = trim((string)($r['text'] ?? ''));
  $m = trim((string)($r['media_text'] ?? ''));
  $combined = trim($txt . "\n" . ($m !== '' ? ('[media]\n' . $m) : ''));
  if ($combined === '') continue;
  $items[] = [
    'tg_chat_id' => (string)($r['tg_chat_id'] ?? ''),
    'tg_message_id' => (string)($r['tg_message_id'] ?? ''),
    'received_at' => (string)($r['received_at'] ?? ''),
    'chat_title' => (string)($r['tg_chat_title'] ?? ''),
    'from' => (string)($r['tg_username'] ?? $r['tg_name'] ?? ''),
    'text' => $combined,
  ];
}

$system = 'Return strict JSON only with keys: summary_text (string), events (array). Each event: announce_date (YYYY-MM-DD), title, facts (array of strings), confidence (0..100), sources (array of {tg_chat_id,tg_message_id}).';
$prompt = "Summarize this day chat activity and extract restaurant announcements. Day: {$day}. If no announcements, events must be empty array.";
$resp = testai_gemini_generate(
  $geminiKey,
  $geminiModel,
  [
    ['text' => $prompt],
    ['text' => json_encode(['day' => $day, 'messages' => $items], JSON_UNESCAPED_UNICODE)],
  ],
  ['system' => $system, 'temperature' => 0.2, 'maxOutputTokens' => 2500, 'responseMimeType' => 'application/json']
);
$j = testai_gemini_json($resp);
if (!is_array($j)) { echo "bad gemini response\n"; exit(0); }

$summary = trim((string)($j['summary_text'] ?? ''));
$events = $j['events'] ?? [];
if (!is_array($events)) $events = [];

$eventsJson = json_encode($events, JSON_UNESCAPED_UNICODE);
if ($eventsJson === false) $eventsJson = '[]';

try {
  $db->query(
    "INSERT INTO {$tDaily} (day, summary_text, events_json, created_at)
     VALUES (?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE summary_text = VALUES(summary_text), events_json = VALUES(events_json), created_at = VALUES(created_at)",
    [$day, $summary, $eventsJson, date('Y-m-d H:i:s')]
  );
} catch (\Throwable $e) {}

echo "ok\n";
