<?php
declare(strict_types=1);

$html = $announcementSvc->getCached($date);
$ok(['date' => $date, 'html' => $html]);
exit;

