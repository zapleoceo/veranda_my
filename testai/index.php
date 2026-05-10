<?php
declare(strict_types=1);

$date = trim((string)($_GET['date'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');

header('Location: /admin/?tab=aibot&date=' . rawurlencode($date), true, 302);
exit;
