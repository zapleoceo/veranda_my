<?php
date_default_timezone_set('Asia/Ho_Chi_Minh');
require_once __DIR__ . '/../../src/classes/Database.php';
require_once __DIR__ . '/../../src/classes/PosterAPI.php';
require_once __DIR__ . '/../../src/classes/KitchenAnalytics.php';

function loadEnv(string $path): void {
    if (file_exists($path)) {
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $t = trim($line);
            if ($t === '' || $t[0] === '#') continue;
            if (strpos($t, '=') === false) continue;
            [$k, $v] = explode('=', $t, 2);
            $_ENV[$k] = trim($v);
        }
    }
}

loadEnv(__DIR__ . '/../../.env');

$dbHost = $_ENV['DB_HOST'] ?? 'localhost';
$dbName = $_ENV['DB_NAME'] ?? 'veranda_my';
$dbUser = $_ENV['DB_USER'] ?? 'veranda_my';
$dbPass = $_ENV['DB_PASS'] ?? '';
$token  = $_ENV['POSTER_API_TOKEN'] ?? '';

$from = $argv[1] ?? date('Y-m-01');
$to   = $argv[2] ?? date('Y-m-d');

$fromTs = strtotime($from);
$toTs   = strtotime($to);
if ($fromTs === false || $toTs === false || $fromTs > $toTs) {
    fwrite(STDERR, "Invalid date range\n");
    exit(2);
}

$tableSuffix = (string)($_ENV['DB_TABLE_SUFFIX'] ?? '');
$db = new \App\Classes\Database($dbHost, $dbName, $dbUser, $dbPass, $tableSuffix);
$ks = $db->t('kitchen_stats');
$api = new \App\Classes\PosterAPI($token);
$analytics = new \App\Classes\KitchenAnalytics($api);

$columnExists = function (\App\Classes\Database $db, string $dbName, string $table, string $column): bool {
    $row = $db->query(
        "SELECT COUNT(*) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?",
        [$dbName, $table, $column]
    )->fetch();
    return (int)($row['c'] ?? 0) > 0;
};

foreach (['pay_type' => "TINYINT NULL AFTER status",
          'close_reason' => "TINYINT NULL AFTER pay_type",
          'exclude_from_dashboard' => "TINYINT(1) NOT NULL DEFAULT 0 AFTER close_reason",
          'exclude_auto' => "TINYINT(1) NOT NULL DEFAULT 0 AFTER exclude_from_dashboard",
          'ready_chass_at' => "DATETIME NULL AFTER ready_pressed_at",
          'prob_close_at' => "DATETIME NULL AFTER ready_chass_at",
          'dish_category_id' => "BIGINT NULL AFTER dish_id",
          'dish_sub_category_id' => "BIGINT NULL AFTER dish_category_id"] as $col => $ddl) {
    if (!$columnExists($db, $dbName, $ks, $col)) {
        $db->query("ALTER TABLE {$ks} ADD COLUMN {$col} {$ddl}");
    }
}

function computeProbCloseAt(\App\Classes\Database $db, string $date): array {
    $ks = $db->t('kitchen_stats');
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

    $targets = $db->query(
        "SELECT id, receipt_number, station, prob_close_at, ticket_sent_at
         FROM {$ks}
         WHERE transaction_date = ?
           AND status = 1
           AND receipt_number REGEXP '^[0-9]+$'
           AND COALESCE(was_deleted, 0) = 0
           AND NOT (COALESCE(dish_category_id, 0) = 47 OR COALESCE(dish_sub_category_id, 0) = 47)
           AND ticket_sent_at IS NOT NULL
           AND ready_pressed_at IS NULL
           AND ready_chass_at IS NULL",
        [$date]
    )->fetchAll();

    $upd = $db->getPdo()->prepare("UPDATE {$ks} SET prob_close_at = ? WHERE id = ?");
    $setCount = 0; $clearCount = 0;
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
        $current = $t['prob_close_at'] ?? null;
        if (($current === null || $current === '') && $candidate === null) continue;
        if ($candidate !== null && (string)$candidate === (string)$current) continue;
        if ($candidate === null && ($current === null || $current === '')) continue;
        $upd->execute([$candidate, $id]);
        if ($candidate !== null) $setCount++; else $clearCount++;
    }
    return ['set' => $setCount, 'cleared' => $clearCount];
}

function autoExclude(\App\Classes\Database $db, string $date): array {
    $ks = $db->t('kitchen_stats');
    $setHookah = $db->query(
        "UPDATE {$ks}
         SET exclude_from_dashboard = 1,
             exclude_auto = 1
         WHERE transaction_date = ?
           AND (COALESCE(dish_category_id, 0) = 47 OR COALESCE(dish_sub_category_id, 0) = 47)",
        [$date]
    )->rowCount();
    $set1 = $db->query(
        "UPDATE {$ks}
         SET exclude_from_dashboard = 1,
             exclude_auto = 1
         WHERE transaction_date = ?
           AND COALESCE(was_deleted, 0) = 0
           AND COALESCE(exclude_from_dashboard, 0) = 0
           AND NOT (COALESCE(dish_category_id, 0) = 47 OR COALESCE(dish_sub_category_id, 0) = 47)
           AND ready_pressed_at IS NULL
           AND ready_chass_at IS NULL
           AND prob_close_at IS NOT NULL",
        [$date]
    )->rowCount();
    $set2 = $db->query(
        "UPDATE {$ks}
         SET exclude_from_dashboard = 1,
             exclude_auto = 1
         WHERE transaction_date = ?
           AND COALESCE(was_deleted, 0) = 0
           AND COALESCE(exclude_from_dashboard, 0) = 0
           AND NOT (COALESCE(dish_category_id, 0) = 47 OR COALESCE(dish_sub_category_id, 0) = 47)
           AND ready_pressed_at IS NULL
           AND ready_chass_at IS NULL
           AND prob_close_at IS NULL
           AND ticket_sent_at IS NOT NULL
           AND status > 1
           AND transaction_closed_at IS NOT NULL
           AND transaction_closed_at > '2000-01-01 00:00:00'",
        [$date]
    )->rowCount();
    $unset1 = $db->query(
        "UPDATE {$ks}
         SET exclude_from_dashboard = 0,
             exclude_auto = 0
         WHERE transaction_date = ?
           AND exclude_auto = 1
           AND NOT (COALESCE(dish_category_id, 0) = 47 OR COALESCE(dish_sub_category_id, 0) = 47)
           AND (ready_pressed_at IS NOT NULL OR ready_chass_at IS NOT NULL)",
        [$date]
    )->rowCount();
    $unset2 = $db->query(
        "UPDATE {$ks}
         SET exclude_from_dashboard = 0,
             exclude_auto = 0
         WHERE transaction_date = ?
           AND exclude_auto = 1
           AND NOT (COALESCE(dish_category_id, 0) = 47 OR COALESCE(dish_sub_category_id, 0) = 47)
           AND ready_pressed_at IS NULL
           AND ready_chass_at IS NULL
           AND prob_close_at IS NULL
           AND NOT (
                ticket_sent_at IS NOT NULL
            AND status > 1
            AND transaction_closed_at IS NOT NULL
            AND transaction_closed_at > '2000-01-01 00:00:00'
           )",
        [$date]
    )->rowCount();
    return ['set_hookah' => (int)$setHookah, 'set_prob' => (int)$set1, 'set_close' => (int)$set2, 'unset_fact' => (int)$unset1, 'unset_lost' => (int)$unset2];
}

for ($d = $fromTs; $d <= $toTs; $d = strtotime('+1 day', $d)) {
    $date = date('Y-m-d', $d);
    echo "[" . date('Y-m-d H:i:s') . "] Syncing {$date}...\n";
    try {
        $stats = $analytics->getStatsForPeriod($date, $date);
        if (!empty($stats)) {
            $db->saveStats($stats);
            echo "  Saved " . count($stats) . " rows\n";
        } else {
            echo "  No rows\n";
        }
        // Refresh close metadata from Poster for all closed transactions of the day
        $txRows = $db->query(
            "SELECT DISTINCT transaction_id
             FROM {$ks}
             WHERE transaction_date = ?
               AND status > 1",
            [$date]
        )->fetchAll();
        $refreshed = 0;
        foreach ($txRows as $row) {
            $txId = (int)$row['transaction_id'];
            if ($txId <= 0) continue;
            try {
                $txRes = $api->request('dash.getTransaction', ['transaction_id' => $txId]);
                $tx = $txRes[0] ?? $txRes;
                $status = (int)($tx['status'] ?? 2);
                if ($status <= 1) {
                    continue;
                }
                $payType = isset($tx['pay_type']) ? (int)$tx['pay_type'] : null;
                $closeReason = isset($tx['reason']) && $tx['reason'] !== '' ? (int)$tx['reason'] : null;
                $closedAt = null;
                if (!empty($tx['date_close']) && (int)$tx['date_close'] > 0) {
                    $candidate = new DateTime('@' . round(((int)$tx['date_close']) / 1000));
                    $candidate->setTimezone(new DateTimeZone('Asia/Ho_Chi_Minh'));
                    if ((int)$candidate->format('Y') >= 2000) {
                        $closedAt = $candidate->format('Y-m-d H:i:s');
                    }
                }
                if ($closedAt === null && !empty($tx['date_close_date']) && $tx['date_close_date'] !== '0000-00-00 00:00:00') {
                    $ts = strtotime($tx['date_close_date']);
                    if ($ts !== false && $ts > 0 && (int)date('Y', $ts) >= 2000) {
                        $closedAt = date('Y-m-d H:i:s', $ts);
                    }
                }
                $db->query(
                    "UPDATE {$ks}
                     SET status = ?, pay_type = ?, close_reason = ?, transaction_closed_at = ?
                     WHERE transaction_id = ?",
                    [$status, $payType, $closeReason, $closedAt, $txId]
                );
                $refreshed++;
            } catch (\Exception $e) {
                // ignore per-transaction errors
            }
        }
        if ($refreshed > 0) {
            echo "  Refreshed close metadata for {$refreshed} transactions\n";
        }
        $prob = computeProbCloseAt($db, $date);
        echo "  ProbCloseTime: set {$prob['set']}, cleared {$prob['cleared']}\n";
        $auto = autoExclude($db, $date);
        echo "  AutoExclude: set_prob={$auto['set_prob']} set_close={$auto['set_close']} unset_fact={$auto['unset_fact']} unset_lost={$auto['unset_lost']}\n";
    } catch (\Exception $e) {
        echo "  ERROR: " . $e->getMessage() . "\n";
    }
}

echo "[" . date('Y-m-d H:i:s') . "] DONE\n";
?>
