<?php
date_default_timezone_set('Asia/Ho_Chi_Minh');

require_once __DIR__ . '/src/classes/Database.php';
require_once __DIR__ . '/src/classes/PosterAPI.php';
require_once __DIR__ . '/src/classes/KitchenAnalytics.php';
require_once __DIR__ . '/src/classes/CodemealAPI.php';
require_once __DIR__ . '/src/classes/ChefAssistantSync.php';

// Load .env
if (file_exists(__DIR__ . '/.env')) {
    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($name, $value) = explode('=', $line, 2);
        $_ENV[$name] = trim($value);
    }
}

$dbHost = $_ENV['DB_HOST'] ?? 'localhost';
$dbName = $_ENV['DB_NAME'] ?? 'veranda_my';
$dbUser = $_ENV['DB_USER'] ?? 'veranda_my';
$dbPass = $_ENV['DB_PASS'] ?? '';
$token = $_ENV['POSTER_API_TOKEN'] ?? '';

try {
    $db = new \App\Classes\Database($dbHost, $dbName, $dbUser, $dbPass);
    $api = new \App\Classes\PosterAPI($token);
    $analytics = new \App\Classes\KitchenAnalytics($api);

    $columnExists = function (\App\Classes\Database $db, string $dbName, string $table, string $column): bool {
        $row = $db->query(
            "SELECT COUNT(*) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?",
            [$dbName, $table, $column]
        )->fetch();
        return (int)($row['c'] ?? 0) > 0;
    };

    if (!$columnExists($db, $dbName, 'kitchen_stats', 'pay_type')) {
        $db->query("ALTER TABLE kitchen_stats ADD COLUMN pay_type TINYINT NULL AFTER status");
    }
    if (!$columnExists($db, $dbName, 'kitchen_stats', 'close_reason')) {
        $db->query("ALTER TABLE kitchen_stats ADD COLUMN close_reason TINYINT NULL AFTER pay_type");
    }
    if (!$columnExists($db, $dbName, 'kitchen_stats', 'exclude_from_dashboard')) {
        $db->query("ALTER TABLE kitchen_stats ADD COLUMN exclude_from_dashboard TINYINT(1) NOT NULL DEFAULT 0 AFTER close_reason");
    }
    if (!$columnExists($db, $dbName, 'kitchen_stats', 'exclude_auto')) {
        $db->query("ALTER TABLE kitchen_stats ADD COLUMN exclude_auto TINYINT(1) NOT NULL DEFAULT 0 AFTER exclude_from_dashboard");
    }
    if (!$columnExists($db, $dbName, 'kitchen_stats', 'ready_chass_at')) {
        $db->query("ALTER TABLE kitchen_stats ADD COLUMN ready_chass_at DATETIME NULL AFTER ready_pressed_at");
    }
    if (!$columnExists($db, $dbName, 'kitchen_stats', 'prob_close_at')) {
        $db->query("ALTER TABLE kitchen_stats ADD COLUMN prob_close_at DATETIME NULL AFTER ready_chass_at");
    }
    if (!$columnExists($db, $dbName, 'kitchen_stats', 'dish_category_id')) {
        $db->query("ALTER TABLE kitchen_stats ADD COLUMN dish_category_id BIGINT NULL AFTER dish_id");
    }
    if (!$columnExists($db, $dbName, 'kitchen_stats', 'dish_sub_category_id')) {
        $db->query("ALTER TABLE kitchen_stats ADD COLUMN dish_sub_category_id BIGINT NULL AFTER dish_category_id");
    }

    // Берем интервал: с начала сегодняшнего дня
    $dateFrom = date('Y-m-d');
    $dateTo = date('Y-m-d');

    echo "[" . date('Y-m-d H:i:s') . "] Starting sync for $dateFrom...\n";

    $stats = $analytics->getStatsForPeriod($dateFrom, $dateTo);
    
    if (!empty($stats)) {
        $db->saveStats($stats);
        echo "[" . date('Y-m-d H:i:s') . "] Successfully synced " . count($stats) . " records.\n";
    } else {
        echo "[" . date('Y-m-d H:i:s') . "] No records found for the period.\n";
    }

    $needsCloseRefresh = $db->query(
        "SELECT DISTINCT transaction_id
         FROM kitchen_stats
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
                "UPDATE kitchen_stats
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
    }

    $db->query("CREATE TABLE IF NOT EXISTS system_meta (
        meta_key VARCHAR(100) PRIMARY KEY,
        meta_value VARCHAR(255) NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $getMeta = function (string $key) use ($db): string {
        $row = $db->query("SELECT meta_value FROM system_meta WHERE meta_key = ? LIMIT 1", [$key])->fetch();
        return $row ? (string)$row['meta_value'] : '';
    };

    $codemealAuth = $getMeta('codemeal_auth');
    $codemealClient = $getMeta('codemeal_client_number');
    $codemealLocale = $getMeta('codemeal_locale');
    if ($codemealLocale === '') $codemealLocale = 'en';
    $codemealTz = $getMeta('codemeal_timezone');
    if ($codemealTz === '') $codemealTz = 'Asia/Ho_Chi_Minh';

    if ($codemealAuth !== '' && $codemealClient !== '') {
        try {
            $bootstrapDone = $getMeta('chefassistant_bootstrap_done') === '1';
            $parserFixDone = $getMeta('chefassistant_readytime_fix_done') === '1';
            $bootstrapFrom = null;
            if (!$bootstrapDone || !$parserFixDone) {
                $savedFrom = $getMeta('chefassistant_bootstrap_from');
                if ($savedFrom !== '') {
                    $bootstrapFrom = $savedFrom;
                } else {
                    $min = $db->query("SELECT MIN(transaction_date) AS d FROM kitchen_stats")->fetch();
                    $minDate = !empty($min['d']) ? (string)$min['d'] : '';
                    if ($minDate !== '' && $minDate !== '0000-00-00') {
                        $bootstrapFrom = date('Y/m/d 00:00:00', strtotime($minDate . ' -1 day'));
                    } else {
                        $bootstrapFrom = date('Y/m/d 00:00:00', strtotime('-14 days'));
                    }
                }
            }

            $chassFrom = $bootstrapFrom ?? date('Y/m/d 00:00:00', strtotime('-1 day'));
            $apiCh = new \App\Classes\CodemealAPI('https://codemeal.pro', $codemealAuth, $codemealClient, $codemealLocale, $codemealTz);
            $chass = new \App\Classes\ChefAssistantSync($db, $apiCh, $codemealTz);
            $res = $chass->syncOrders($chassFrom, null, $bootstrapFrom !== null ? null : 20);
            if (!empty($res['ok'])) {
                $updateFrom = $bootstrapFrom !== null ? date('Y-m-d', strtotime(str_replace('/', '-', substr($bootstrapFrom, 0, 10)))) : date('Y-m-d', strtotime('-1 day'));
                $updated = $chass->updateKitchenStatsReadyChAss($updateFrom, date('Y-m-d'), $res['order_ids'] ?? []);
                $db->query(
                    "INSERT INTO system_meta (meta_key, meta_value) VALUES (?, ?)
                     ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value), updated_at = CURRENT_TIMESTAMP",
                    ['chefassistant_last_sync_at', date('Y-m-d H:i:s')]
                );
                $db->query(
                    "INSERT INTO system_meta (meta_key, meta_value) VALUES (?, ?)
                     ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value), updated_at = CURRENT_TIMESTAMP",
                    ['chefassistant_last_sync_info', 'saved=' . (int)($res['saved'] ?? 0) . ', pages=' . (int)($res['pages'] ?? 0) . ', updated=' . $updated]
                );
                if ($bootstrapFrom !== null) {
                    $db->query(
                        "INSERT INTO system_meta (meta_key, meta_value) VALUES (?, ?)
                         ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value), updated_at = CURRENT_TIMESTAMP",
                        ['chefassistant_bootstrap_done', '1']
                    );
                    $db->query(
                        "INSERT INTO system_meta (meta_key, meta_value) VALUES (?, ?)
                         ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value), updated_at = CURRENT_TIMESTAMP",
                        ['chefassistant_bootstrap_done_at', date('Y-m-d H:i:s')]
                    );
                    $db->query(
                        "INSERT INTO system_meta (meta_key, meta_value) VALUES (?, ?)
                         ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value), updated_at = CURRENT_TIMESTAMP",
                        ['chefassistant_bootstrap_from', $bootstrapFrom]
                    );
                    $db->query(
                        "INSERT INTO system_meta (meta_key, meta_value) VALUES (?, ?)
                         ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value), updated_at = CURRENT_TIMESTAMP",
                        ['chefassistant_readytime_fix_done', '1']
                    );
                    echo "[" . date('Y-m-d H:i:s') . "] ChefAssistant BOOTSTRAP from {$bootstrapFrom}: saved " . (int)($res['saved'] ?? 0) . ", pages " . (int)($res['pages'] ?? 0) . ", updated {$updated} rows.\n";
                } else {
                    echo "[" . date('Y-m-d H:i:s') . "] ChefAssistant: saved " . (int)($res['saved'] ?? 0) . ", pages " . (int)($res['pages'] ?? 0) . ", updated {$updated} rows.\n";
                }
            } else {
                $err = (string)($res['error'] ?? 'ChefAssistant error');
                $db->query(
                    "INSERT INTO system_meta (meta_key, meta_value) VALUES (?, ?)
                     ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value), updated_at = CURRENT_TIMESTAMP",
                    ['chefassistant_last_sync_error', $err]
                );
                if (!empty($res['auth_error'])) {
                    $db->query(
                        "INSERT INTO system_meta (meta_key, meta_value) VALUES (?, ?)
                         ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value), updated_at = CURRENT_TIMESTAMP",
                        ['chefassistant_auth_problem_at', date('Y-m-d H:i:s')]
                    );
                }
                echo "[" . date('Y-m-d H:i:s') . "] ChefAssistant ERROR: {$err}\n";
            }
        } catch (\Exception $e) {
            $db->query(
                "INSERT INTO system_meta (meta_key, meta_value) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value), updated_at = CURRENT_TIMESTAMP",
                ['chefassistant_last_sync_error', $e->getMessage()]
            );
            echo "[" . date('Y-m-d H:i:s') . "] ChefAssistant ERROR: " . $e->getMessage() . "\n";
        }
    }

    $computeProbCloseAt = function (string $date) use ($db): array {
        $readyRows = $db->query(
            "SELECT receipt_number, station, ready_pressed_at, ready_chass_at
             FROM kitchen_stats
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
             FROM kitchen_stats
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

        $upd = $db->getPdo()->prepare("UPDATE kitchen_stats SET prob_close_at = ? WHERE id = ?");
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

    $autoExclude = function (string $date) use ($db): array {
        $setHookah = $db->query(
            "UPDATE kitchen_stats
             SET exclude_from_dashboard = 1,
                 exclude_auto = 1
             WHERE transaction_date = ?
               AND (COALESCE(dish_category_id, 0) = 47 OR COALESCE(dish_sub_category_id, 0) = 47)",
            [$date]
        )->rowCount();

        $set1 = $db->query(
            "UPDATE kitchen_stats
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
            "UPDATE kitchen_stats
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
            "UPDATE kitchen_stats
             SET exclude_from_dashboard = 0,
                 exclude_auto = 0
             WHERE transaction_date = ?
               AND exclude_auto = 1
               AND NOT (COALESCE(dish_category_id, 0) = 47 OR COALESCE(dish_sub_category_id, 0) = 47)
               AND (ready_pressed_at IS NOT NULL OR ready_chass_at IS NOT NULL)",
            [$date]
        )->rowCount();

        $unset2 = $db->query(
            "UPDATE kitchen_stats
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
    };

    $auto = $autoExclude($dateFrom);
    $autoAny = array_sum($auto);
    if ($autoAny > 0) {
        echo "[" . date('Y-m-d H:i:s') . "] AutoExclude: set_prob=" . (int)$auto['set_prob'] . ", set_close=" . (int)$auto['set_close'] . ", unset_fact=" . (int)$auto['unset_fact'] . ", unset_lost=" . (int)$auto['unset_lost'] . ".\n";
    }

    $db->query(
        "INSERT INTO system_meta (meta_key, meta_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value), updated_at = CURRENT_TIMESTAMP",
        ['poster_last_sync_at', date('Y-m-d H:i:s')]
    );
    echo "[" . date('Y-m-d H:i:s') . "] Updated sync marker.\n";

} catch (\Exception $e) {
    echo "[" . date('Y-m-d H:i:s') . "] ERROR: " . $e->getMessage() . "\n";
    error_log("Cron sync error: " . $e->getMessage());
}
