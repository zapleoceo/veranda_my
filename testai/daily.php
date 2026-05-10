<?php
declare(strict_types=1);

$ctx = require __DIR__ . '/bootstrap.php';
$gemini = $ctx['gemini'];
$svc = $ctx['dailySvc'];

if (!$gemini->canCall()) { echo "missing gemini_key\n"; exit(0); }

$day = trim((string)($_GET['day'] ?? ''));
if ($day === '') $day = date('Y-m-d', strtotime('-1 day'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) { echo "bad day\n"; exit(0); }
$ok = $svc->runDay($day);
if (!$ok) { echo "bad gemini response\n"; exit(0); }
echo "ok\n";
