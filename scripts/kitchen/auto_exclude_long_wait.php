<?php
date_default_timezone_set('Asia/Ho_Chi_Minh');

require_once __DIR__ . '/../../src/classes/Database.php';

function loadEnv(string $path): void {
    if (file_exists($path)) {
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $t = trim($line);
            if ($t === '' || $t[0] === '#') continue;
            if (strpos($t, '=') === false) continue;
            [$k, $v] = explode('=', $line, 2);
            $_ENV[$k] = trim($v);
        }
    }
}

loadEnv(__DIR__ . '/../../.env');

$dbHost = $_ENV['DB_HOST'] ?? 'localhost';
$dbName = $_ENV['DB_NAME'] ?? 'veranda_my';
$dbUser = $_ENV['DB_USER'] ?? 'veranda_my';
$dbPass = $_ENV['DB_PASS'] ?? '';

$from = $argv[1] ?? date('Y-m-01');
$to = $argv[2] ?? date('Y-m-d');

$fromTs = strtotime($from);
$toTs = strtotime($to);
if ($fromTs === false || $toTs === false || $fromTs > $toTs) {
    fwrite(STDERR, "Invalid date range\n");
    exit(2);
}

$db = new \App\Classes\Database($dbHost, $dbName, $dbUser, $dbPass);

$thresholds = [
    '2' => 220.0, // Kitchen
    '3' => 60.0,  // Bar Veranda
];

$rows = $db->query(
    "SELECT id, station, ticket_sent_at, ready_pressed_at, ready_chass_at, prob_close_at, transaction_closed_at, status
     FROM kitchen_stats
     WHERE transaction_date BETWEEN ? AND ?
       AND COALESCE(was_deleted, 0) = 0
       AND COALESCE(exclude_from_dashboard, 0) = 0
       AND NOT (COALESCE(dish_category_id, 0) = 47 OR COALESCE(dish_sub_category_id, 0) = 47)
       AND ticket_sent_at IS NOT NULL",
    [$from, $to]
)->fetchAll();

$updates = 0;
$byStation = ['2' => 0, '3' => 0, 'other' => 0];

$upd = $db->getPdo()->prepare("UPDATE kitchen_stats SET exclude_from_dashboard = 1, exclude_auto = 1 WHERE id = ?");

foreach ($rows as $r) {
    $id = (int)($r['id'] ?? 0);
    if ($id <= 0) continue;

    $station = (string)($r['station'] ?? '');
    $stationId = null;
    if ($station === '2' || $station === 2 || $station === 'Kitchen' || $station === 'Main') $stationId = '2';
    if ($station === '3' || $station === 3 || $station === 'Bar Veranda') $stationId = '3';
    if ($stationId === null) continue;

    $sentAt = (string)($r['ticket_sent_at'] ?? '');
    $sentTs = $sentAt !== '' ? strtotime($sentAt) : false;
    if ($sentTs === false || $sentTs <= 0) continue;

    $end = null;
    $endTs = 0;
    foreach ([
        (string)($r['ready_pressed_at'] ?? ''),
        (string)($r['ready_chass_at'] ?? ''),
        (string)($r['prob_close_at'] ?? ''),
    ] as $t) {
        if ($t === '') continue;
        $ts = strtotime($t);
        if ($ts === false || $ts <= 0) continue;
        if ($ts < $sentTs) continue;
        $end = $t;
        $endTs = $ts;
        break;
    }
    if ($end === null) {
        $closedAt = (string)($r['transaction_closed_at'] ?? '');
        if ($closedAt !== '' && $closedAt !== '0000-00-00 00:00:00' && (int)($r['status'] ?? 1) > 1) {
            $ts = strtotime($closedAt);
            if ($ts !== false && $ts >= $sentTs) {
                $end = $closedAt;
                $endTs = $ts;
            }
        }
    }
    if ($end === null && (int)($r['status'] ?? 1) === 1) {
        $endTs = time();
        if ($endTs >= $sentTs) {
            $end = date('Y-m-d H:i:s', $endTs);
        }
    }
    if ($end === null) continue;

    $waitMin = ($endTs - $sentTs) / 60.0;
    $limit = $thresholds[$stationId];
    if ($waitMin > $limit) {
        $upd->execute([$id]);
        $updates++;
        $byStation[$stationId]++;
    }
}

echo "Auto-excluded: {$updates}\n";
echo "  Kitchen: {$byStation['2']}\n";
echo "  Bar: {$byStation['3']}\n";
?>
