<?php
date_default_timezone_set('Asia/Ho_Chi_Minh');

require_once __DIR__ . '/../../src/classes/Database.php';

if (file_exists(__DIR__ . '/../../.env')) {
    $lines = file(__DIR__ . '/../../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        [$name, $value] = explode('=', $line, 2);
        $_ENV[$name] = trim($value);
    }
}

$tableSuffix = (string)($_ENV['DB_TABLE_SUFFIX'] ?? '');
$db = new \App\Classes\Database($_ENV['DB_HOST'] ?? 'localhost', $_ENV['DB_NAME'] ?? 'veranda_my', $_ENV['DB_USER'] ?? 'veranda_my', $_ENV['DB_PASS'] ?? '', $tableSuffix);
$ks = $db->t('kitchen_stats');

$dates = $db->query("SELECT DISTINCT transaction_date AS d FROM {$ks} WHERE transaction_date IS NOT NULL ORDER BY d ASC")->fetchAll();
$totalDates = count($dates);
echo "[" . date('Y-m-d H:i:s') . "] Dates: {$totalDates}\n";

$db->query("UPDATE {$ks} SET prob_close_at = NULL WHERE prob_close_at IS NOT NULL");

$upd = $db->getPdo()->prepare("UPDATE {$ks} SET prob_close_at = ? WHERE id = ?");

$i = 0;
$setTotal = 0;
foreach ($dates as $row) {
    $i++;
    $date = (string)($row['d'] ?? '');
    if ($date === '' || $date === '0000-00-00') continue;

    $readyRows = $db->query(
        "SELECT receipt_number, station, ready_pressed_at, ready_chass_at
         FROM {$ks}
         WHERE transaction_date = ?
           AND receipt_number REGEXP '^[0-9]+$'
           AND COALESCE(was_deleted, 0) = 0
           AND NOT (COALESCE(dish_category_id, 0) = 47 OR COALESCE(dish_sub_category_id, 0) = 47)
           AND (ready_pressed_at IS NOT NULL OR ready_chass_at IS NOT NULL)",
        [$date]
    )->fetchAll();

    $byReceiptStation = [];
    foreach ($readyRows as $r) {
        $receipt = (int)($r['receipt_number'] ?? 0);
        $station = (string)($r['station'] ?? '');
        if ($receipt <= 0 || $station === '') continue;

        $t1 = $r['ready_pressed_at'] ?? null;
        $t2 = $r['ready_chass_at'] ?? null;
        $end = null;
        if ($t1 && $t2) {
            $end = strtotime($t1) <= strtotime($t2) ? $t1 : $t2;
        } elseif ($t1) {
            $end = $t1;
        } elseif ($t2) {
            $end = $t2;
        }
        if ($end === null) continue;

        if (!isset($byReceiptStation[$receipt][$station])) {
            $byReceiptStation[$receipt][$station] = $end;
        } else {
            if (strtotime($end) < strtotime($byReceiptStation[$receipt][$station])) {
                $byReceiptStation[$receipt][$station] = $end;
            }
        }
    }

    if (empty($byReceiptStation)) {
        if ($i % 10 === 0) {
            echo "[" . date('Y-m-d H:i:s') . "] {$i}/{$totalDates} {$date}: no ready rows\n";
        }
        continue;
    }

    $targets = $db->query(
        "SELECT id, receipt_number, station, ticket_sent_at
         FROM {$ks}
         WHERE transaction_date = ?
           AND receipt_number REGEXP '^[0-9]+$'
           AND COALESCE(was_deleted, 0) = 0
           AND NOT (COALESCE(dish_category_id, 0) = 47 OR COALESCE(dish_sub_category_id, 0) = 47)
           AND ticket_sent_at IS NOT NULL
           AND ready_pressed_at IS NULL
           AND ready_chass_at IS NULL",
        [$date]
    )->fetchAll();

    $set = 0;
    foreach ($targets as $t) {
        $id = (int)($t['id'] ?? 0);
        $receipt = (int)($t['receipt_number'] ?? 0);
        $station = (string)($t['station'] ?? '');
        if ($id <= 0 || $receipt <= 0 || $station === '') continue;
        $sentAt = (string)($t['ticket_sent_at'] ?? '');
        $sentTs = $sentAt !== '' ? strtotime($sentAt) : false;
        if ($sentTs === false || $sentTs <= 0) continue;

        $candidate = null;
        for ($d = 1; $d <= 3; $d++) {
            $next = $receipt + $d;
            if (isset($byReceiptStation[$next][$station])) {
                $candidate = $byReceiptStation[$next][$station];
                break;
            }
        }
        if ($candidate !== null) {
            $candTs = strtotime($candidate);
            if ($candTs === false || $candTs < $sentTs) {
                $candidate = null;
            }
        }
        if ($candidate === null) continue;

        $upd->execute([$candidate, $id]);
        $set++;
    }
    $setTotal += $set;

    if ($i % 10 === 0) {
        echo "[" . date('Y-m-d H:i:s') . "] {$i}/{$totalDates} {$date}: set={$set}\n";
    }
}

echo "[" . date('Y-m-d H:i:s') . "] Done. set_total={$setTotal}\n";
