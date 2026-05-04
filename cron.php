<?php
require_once __DIR__ . '/src/classes/Database.php';
require_once __DIR__ . '/src/classes/PosterAPI.php';
require_once __DIR__ . '/src/classes/KitchenAnalytics.php';
require_once __DIR__ . '/src/classes/EventLogger.php';

// Load .env
if (file_exists(__DIR__ . '/.env')) {
    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($name, $value) = explode('=', $line, 2);
        $_ENV[$name] = trim($value);
    }
}

$spotTzName = trim((string)($_ENV['POSTER_SPOT_TIMEZONE'] ?? ''));
if ($spotTzName === '' || !in_array($spotTzName, timezone_identifiers_list(), true)) {
    $spotTzName = 'Asia/Ho_Chi_Minh';
}
$apiTzName = trim((string)($_ENV['POSTER_API_TIMEZONE'] ?? ''));
if ($apiTzName === '' || !in_array($apiTzName, timezone_identifiers_list(), true)) {
    $apiTzName = $spotTzName;
}
date_default_timezone_set($apiTzName);
$spotTz = new DateTimeZone($spotTzName);

$dbHost = $_ENV['DB_HOST'] ?? 'localhost';
$dbName = $_ENV['DB_NAME'] ?? 'veranda_my';
$dbUser = $_ENV['DB_USER'] ?? 'veranda_my';
$dbPass = $_ENV['DB_PASS'] ?? '';
$token = $_ENV['POSTER_API_TOKEN'] ?? '';
$tableSuffix = (string)($_ENV['DB_TABLE_SUFFIX'] ?? '');

$startedAt = microtime(true);

try {
    $db = new \App\Classes\Database($dbHost, $dbName, $dbUser, $dbPass, $tableSuffix);
    $ksTable = $db->t('kitchen_stats');
    $metaTable = $db->t('system_meta');
    $logger = new \App\Classes\EventLogger($db, 'cron');
    $api = new \App\Classes\PosterAPI($token);
    $analytics = new \App\Classes\KitchenAnalytics($api);

    // Берем интервал: с начала сегодняшнего дня
    $dateFrom = date('Y-m-d');
    $dateTo = date('Y-m-d');

    echo "[" . date('Y-m-d H:i:s') . "] Starting sync for $dateFrom...\n";
    $logger->info('start', ['date_from' => $dateFrom, 'date_to' => $dateTo, 'suffix' => $tableSuffix]);

    $stats = $analytics->getStatsForPeriod($dateFrom, $dateTo);
    
    if (!empty($stats)) {
        $db->saveStats($stats);
        echo "[" . date('Y-m-d H:i:s') . "] Successfully synced " . count($stats) . " records.\n";
        $logger->info('poster_stats_saved', ['count' => count($stats), 'date' => $dateFrom]);
    } else {
        echo "[" . date('Y-m-d H:i:s') . "] No records found for the period.\n";
        $logger->info('poster_stats_empty', ['date' => $dateFrom]);
    }

    $needsCloseRefresh = $db->query(
        "SELECT DISTINCT transaction_id
         FROM {$ksTable}
         WHERE transaction_date = ?
           AND status > 1
           AND (transaction_closed_at IS NULL OR transaction_closed_at < '2000-01-01 00:00:00')
         LIMIT 200",
        [$dateFrom]
    )->fetchAll();

    $refreshed = 0;
    foreach ($needsCloseRefresh as $row) {
        $txId = (int)$row['transaction_id'];
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
                $candidate->setTimezone($spotTz);
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
                "UPDATE {$ksTable}
                 SET status = ?, pay_type = ?, close_reason = ?, transaction_closed_at = ?
                 WHERE transaction_id = ?",
                [$status, $payType, $closeReason, $closedAt, $txId]
            );
            $refreshed++;
        } catch (\Exception $e) {
            error_log("Close refresh failed for TX {$txId}: " . $e->getMessage());
        }
    }
    if ($refreshed > 0) {
        echo "[" . date('Y-m-d H:i:s') . "] Refreshed close metadata for {$refreshed} transactions.\n";
        $logger->info('close_refreshed', ['count' => $refreshed, 'date' => $dateFrom]);
    }

    $computeProbCloseAt = function (string $date) use ($db, $ksTable): array {
        $readyRows = $db->query(
            "SELECT receipt_number, station, ready_pressed_at
             FROM {$ksTable}
             WHERE transaction_date = ?
               AND receipt_number REGEXP '^[0-9]+$'
               AND COALESCE(was_deleted, 0) = 0
               AND NOT (COALESCE(dish_category_id, 0) = 47 OR COALESCE(dish_sub_category_id, 0) = 47)
               AND ready_pressed_at IS NOT NULL",
            [$date]
        )->fetchAll();

        $byReceiptStation = [];
        foreach ($readyRows as $r) {
            $receipt = (int)($r['receipt_number'] ?? 0);
            $station = (string)($r['station'] ?? '');
            if ($receipt <= 0 || $station === '') continue;

            $end = $r['ready_pressed_at'] ?? null;
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
             FROM {$ksTable}
             WHERE transaction_date = ?
               AND receipt_number REGEXP '^[0-9]+$'
               AND COALESCE(was_deleted, 0) = 0
               AND NOT (COALESCE(dish_category_id, 0) = 47 OR COALESCE(dish_sub_category_id, 0) = 47)
               AND ticket_sent_at IS NOT NULL
               AND ready_pressed_at IS NULL",
            [$date]
        )->fetchAll();

        $upd = $db->getPdo()->prepare("UPDATE {$ksTable} SET prob_close_at = ? WHERE id = ?");
        $setCount = 0;
        $clearCount = 0;

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
            if (($current === null || $current === '') && $candidate === null) {
                continue;
            }
            if ($candidate !== null && (string)$candidate === (string)$current) {
                continue;
            }
            if ($candidate === null && ($current === null || $current === '')) {
                continue;
            }

            $upd->execute([$candidate, $id]);
            if ($candidate !== null) {
                $setCount++;
            } else {
                $clearCount++;
            }
        }

        return ['set' => $setCount, 'cleared' => $clearCount];
    };

    $prob = $computeProbCloseAt($dateFrom);
    if (($prob['set'] ?? 0) > 0 || ($prob['cleared'] ?? 0) > 0) {
        echo "[" . date('Y-m-d H:i:s') . "] ProbCloseTime: set " . (int)($prob['set'] ?? 0) . ", cleared " . (int)($prob['cleared'] ?? 0) . ".\n";
    }

    $autoExclude = function (string $date) use ($db, $ksTable): array {
        $setHookah = $db->query(
            "UPDATE {$ksTable}
             SET exclude_from_dashboard = 1,
                 exclude_auto = 1
             WHERE transaction_date = ?
               AND (COALESCE(dish_category_id, 0) = 47 OR COALESCE(dish_sub_category_id, 0) = 47)",
            [$date]
        )->rowCount();

        $set1 = $db->query(
            "UPDATE {$ksTable}
             SET exclude_from_dashboard = 1,
                 exclude_auto = 1
             WHERE transaction_date = ?
               AND COALESCE(was_deleted, 0) = 0
               AND COALESCE(exclude_from_dashboard, 0) = 0
               AND NOT (COALESCE(dish_category_id, 0) = 47 OR COALESCE(dish_sub_category_id, 0) = 47)
               AND ready_pressed_at IS NULL
               AND prob_close_at IS NOT NULL",
            [$date]
        )->rowCount();

        $set2 = $db->query(
            "UPDATE {$ksTable}
             SET exclude_from_dashboard = 1,
                 exclude_auto = 1
             WHERE transaction_date = ?
               AND COALESCE(was_deleted, 0) = 0
               AND COALESCE(exclude_from_dashboard, 0) = 0
               AND NOT (COALESCE(dish_category_id, 0) = 47 OR COALESCE(dish_sub_category_id, 0) = 47)
               AND ready_pressed_at IS NULL
               AND prob_close_at IS NULL
               AND ticket_sent_at IS NOT NULL
               AND status > 1
               AND transaction_closed_at IS NOT NULL
               AND transaction_closed_at > '2000-01-01 00:00:00'",
            [$date]
        )->rowCount();

        $unset1 = $db->query(
            "UPDATE {$ksTable}
             SET exclude_from_dashboard = 0,
                 exclude_auto = 0
             WHERE transaction_date = ?
               AND exclude_auto = 1
               AND NOT (COALESCE(dish_category_id, 0) = 47 OR COALESCE(dish_sub_category_id, 0) = 47)
               AND ready_pressed_at IS NOT NULL",
            [$date]
        )->rowCount();

        $unset2 = $db->query(
            "UPDATE {$ksTable}
             SET exclude_from_dashboard = 0,
                 exclude_auto = 0
             WHERE transaction_date = ?
               AND exclude_auto = 1
               AND NOT (COALESCE(dish_category_id, 0) = 47 OR COALESCE(dish_sub_category_id, 0) = 47)
               AND ready_pressed_at IS NULL
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
    };

    $auto = $autoExclude($dateFrom);
    $autoAny = array_sum($auto);
    if ($autoAny > 0) {
        echo "[" . date('Y-m-d H:i:s') . "] AutoExclude: set_prob=" . (int)$auto['set_prob'] . ", set_close=" . (int)$auto['set_close'] . ", unset_fact=" . (int)$auto['unset_fact'] . ", unset_lost=" . (int)$auto['unset_lost'] . ".\n";
        $logger->info('auto_exclude', array_merge(['date' => $dateFrom], $auto));
    }

    $db->query(
        "INSERT INTO {$metaTable} (meta_key, meta_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value), updated_at = CURRENT_TIMESTAMP",
        ['poster_last_sync_at', date('Y-m-d H:i:s')]
    );
    echo "[" . date('Y-m-d H:i:s') . "] Updated sync marker.\n";
    $logger->info('done', ['date' => $dateFrom]);

    $durationMs = (int)round((microtime(true) - $startedAt) * 1000);
    $resultParts = [];
    $resultParts[] = 'duration_ms=' . $durationMs;
    $resultParts[] = 'stats=' . (is_array($stats) ? count($stats) : 0);
    $resultParts[] = 'close_refreshed=' . (int)$refreshed;
    $resultParts[] = 'prob_set=' . (int)($prob['set'] ?? 0);
    $resultParts[] = 'prob_cleared=' . (int)($prob['cleared'] ?? 0);
    $resultParts[] = 'auto_exclude=' . (int)($autoAny ?? 0);

    $now = date('Y-m-d H:i:s');
    $db->query(
        "INSERT INTO {$metaTable} (meta_key, meta_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value), updated_at = CURRENT_TIMESTAMP",
        ['kitchen_last_sync_at', $now]
    );
    $db->query(
        "INSERT INTO {$metaTable} (meta_key, meta_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value), updated_at = CURRENT_TIMESTAMP",
        ['kitchen_last_sync_result', implode(', ', $resultParts)]
    );
    $db->query(
        "INSERT INTO {$metaTable} (meta_key, meta_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value), updated_at = CURRENT_TIMESTAMP",
        ['kitchen_last_sync_error', '']
    );

} catch (\Exception $e) {
    echo "[" . date('Y-m-d H:i:s') . "] ERROR: " . $e->getMessage() . "\n";
    error_log("Cron sync error: " . $e->getMessage());
    try {
        if (isset($db) && $db instanceof \App\Classes\Database) {
            $logger = new \App\Classes\EventLogger($db, 'cron');
            $logger->error('fatal', ['error' => $e->getMessage()]);

            $metaTable = $db->t('system_meta');
            $now = date('Y-m-d H:i:s');
            $db->query(
                "INSERT INTO {$metaTable} (meta_key, meta_value) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value), updated_at = CURRENT_TIMESTAMP",
                ['kitchen_last_sync_at', $now]
            );
            $db->query(
                "INSERT INTO {$metaTable} (meta_key, meta_value) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value), updated_at = CURRENT_TIMESTAMP",
                ['kitchen_last_sync_error', $e->getMessage()]
            );
        }
    } catch (\Exception $e2) {
    }
}
