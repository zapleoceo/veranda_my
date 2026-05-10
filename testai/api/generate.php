<?php
declare(strict_types=1);

$requireAdmin();

if (!$gemini->canCall()) {
  $bad('missing_gemini_key');
  exit;
}

$html = $announcementSvc->generate($date);
$ok(['date' => $date, 'html' => $html]);
exit;

