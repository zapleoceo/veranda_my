<?php
declare(strict_types=1);

function tr3_api_events_for_day(array $ctx): void {
  api_json_headers(true);

  $day = trim((string)($_GET['day'] ?? ''));
  if ($day === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) api_error(400, 'Некорректная дата');

  api_ok(['day' => $day, 'text' => 'События на эту дату еще не запланировано.']);
}
