<?php
declare(strict_types=1);

$requireAdmin();

$limit = (int)($_GET['limit'] ?? 20);
$limit = max(1, min(50, $limit));
$items = [];
$rows = $rawRepo->fetchLastForDay($date, $limit);
foreach (is_array($rows) ? $rows : [] as $r) {
  if (!is_array($r)) continue;
  $txt = trim((string)($r['text'] ?? ''));
  if (mb_strlen($txt) > 300) $txt = mb_substr($txt, 0, 300) . '…';
  $mt = trim((string)($r['media_text'] ?? ''));
  if (mb_strlen($mt) > 300) $mt = mb_substr($mt, 0, 300) . '…';
  $items[] = [
    'tg_chat_id' => (string)($r['tg_chat_id'] ?? ''),
    'tg_message_id' => (string)($r['tg_message_id'] ?? ''),
    'received_at' => (string)($r['received_at'] ?? ''),
    'chat_title' => (string)($r['tg_chat_title'] ?? ''),
    'from' => (string)($r['tg_username'] ?? $r['tg_name'] ?? ''),
    'media_type' => (string)($r['media_type'] ?? ''),
    'text' => $txt,
    'media_text' => $mt,
  ];
}
$ok(['date' => $date, 'items' => $items]);
exit;

