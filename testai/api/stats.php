<?php
declare(strict_types=1);

$st = $rawRepo->getCountsForDay($date);
$ok([
  'date' => $date,
  'count' => (int)($st['count'] ?? 0),
  'with_media' => (int)($st['with_media'] ?? 0),
  'with_media_text' => (int)($st['with_media_text'] ?? 0),
]);
exit;

