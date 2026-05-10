<?php
declare(strict_types=1);

$row = $dailyRepo->getByDay($date);
$ok([
  'date' => $date,
  'exists' => is_array($row),
  'summary_text' => is_array($row) ? (string)($row['summary_text'] ?? '') : '',
  'events_json' => is_array($row) ? (string)($row['events_json'] ?? '[]') : '[]',
]);
exit;

