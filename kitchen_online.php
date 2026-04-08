<?php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/src/classes/Database.php';
require_once __DIR__ . '/src/classes/PosterAPI.php';
require_once __DIR__ . '/src/classes/KitchenAnalytics.php';
require_once __DIR__ . '/src/classes/MetaRepository.php';
require_once __DIR__ . '/src/classes/EventLogger.php';
veranda_require('kitchen_online');
date_default_timezone_set('Asia/Ho_Chi_Minh');

if (file_exists(__DIR__ . '/.env')) {
    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $t = trim($line);
        if ($t === '' || $t[0] === '#' || strpos($t, '=') === false) continue;
        [$name, $value] = explode('=', $line, 2);
        $_ENV[$name] = trim($value);
    }
}

$dbHost = $_ENV['DB_HOST'] ?? 'localhost';
$dbName = $_ENV['DB_NAME'] ?? 'veranda_my';
$dbUser = $_ENV['DB_USER'] ?? 'veranda_my';
$dbPass = $_ENV['DB_PASS'] ?? '';
$token = $_ENV['POSTER_API_TOKEN'] ?? '';
$tgChatIdEnv = trim((string)($_ENV['TELEGRAM_CHAT_ID'] ?? $_ENV['TG_CHAT_ID'] ?? ''));
$tgChatUsername = trim((string)($_ENV['TELEGRAM_CHAT_USERNAME'] ?? $_ENV['TG_CHAT_USERNAME'] ?? ''));
$tgThreadIdEnv = trim((string)($_ENV['TELEGRAM_THREAD_ID'] ?? $_ENV['TG_THREAD_ID'] ?? ''));
$tgThreadIdVal = ($tgThreadIdEnv !== '' && ctype_digit($tgThreadIdEnv)) ? (int)$tgThreadIdEnv : 0;
$tgChatInternalId = '';
if ($tgChatIdEnv !== '') {
    $tmp = $tgChatIdEnv;
    if (str_starts_with($tmp, '-100')) $tmp = substr($tmp, 4);
    $tmp = ltrim($tmp, '-');
    if ($tmp !== '' && ctype_digit($tmp)) $tgChatInternalId = $tmp;
}

$stationFilter = $_GET['station'] ?? 'all'; // all|kitchen|bar
$isAjax = (($_GET['ajax'] ?? '') === '1');
$action = $_GET['action'] ?? 'list'; // list|sync

$today = date('Y-m-d');
$lastSyncLabel = '—';
$ks = $db->t('kitchen_stats');
$metaTable = $db->t('system_meta');
$tgItems = $db->t('tg_alert_items');
$useLogicalClose = true;
try {
    $m = $db->query("SELECT meta_value FROM {$metaTable} WHERE meta_key='ko_use_logical_close' LIMIT 1")->fetch();
    $useLogicalClose = !isset($m['meta_value']) || (string)$m['meta_value'] !== '0';
} catch (\Throwable $e) {}

try {
    $db->query("ALTER TABLE {$ks} ADD COLUMN transaction_comment TEXT NULL");
} catch (\Throwable $e) {
}
try {
    $db->query("ALTER TABLE {$ks} ADD COLUMN tg_message_id BIGINT NULL");
} catch (\Throwable $e) {
}
try {
    $db->query("ALTER TABLE {$ks} ADD COLUMN tg_sent_at DATETIME NULL");
} catch (\Throwable $e) {
}
try {
    $db->query("ALTER TABLE {$ks} ADD COLUMN tg_last_edit_at DATETIME NULL");
} catch (\Throwable $e) {
}

try {
    $api = new \App\Classes\PosterAPI($token);
    $meta = $db->query("SELECT meta_value FROM {$metaTable} WHERE meta_key = 'poster_last_sync_at' LIMIT 1")->fetch();
    if (!empty($meta['meta_value'])) {
        $lastSyncLabel = date('d.m.Y H:i:s', strtotime($meta['meta_value']));
    } else {
        $fallback = $db->query("SELECT MAX(created_at) AS last_sync_at FROM {$ks}")->fetch();
        if (!empty($fallback['last_sync_at'])) {
            $lastSyncLabel = date('d.m.Y H:i:s', strtotime($fallback['last_sync_at']));
        }
    }
} catch (\Exception $e) {
}

$stationSql = '';
$stationParams = [];
if ($stationFilter === 'kitchen') {
    $stationSql = " AND (station = '2' OR station = 2 OR station = 'Kitchen' OR station = 'Main')";
} elseif ($stationFilter === 'bar') {
    $stationSql = " AND (station = '3' OR station = 3 OR station = 'Bar Veranda')";
}

$renderCards = function (array $rows, int $waitLimitMinutes): string {
    $cards = [];
    foreach ($rows as $r) {
        $txId = (int)($r['transaction_id'] ?? 0);
        if ($txId <= 0) continue;
        if (!isset($cards[$txId])) {
            $cards[$txId] = [
                'transaction_id' => $txId,
                'receipt_number' => (string)($r['receipt_number'] ?? ''),
                'table_number' => (string)($r['table_number'] ?? ''),
                'waiter_name' => (string)($r['waiter_name'] ?? ''),
                'comment' => trim((string)($r['transaction_comment'] ?? '')),
                'min_sent_ts' => 0,
                'items' => []
            ];
        }
        $sentAt = (string)($r['ticket_sent_at'] ?? '');
        $sentTs = $sentAt !== '' ? strtotime($sentAt) : 0;
        if ($sentTs > 0 && ($cards[$txId]['min_sent_ts'] === 0 || $sentTs < $cards[$txId]['min_sent_ts'])) {
            $cards[$txId]['min_sent_ts'] = $sentTs;
        }
        $cards[$txId]['items'][] = [
            'item_id' => (int)($r['id'] ?? 0),
            'dish_name' => (string)($r['dish_name'] ?? ''),
            'dish_id' => (int)($r['dish_id'] ?? 0),
            'sent_at' => $sentAt,
            'sent_ts' => $sentTs,
            'station' => (string)($r['station'] ?? ''),
            'tg_sent_at' => (string)($r['tg_sent_at'] ?? ''),
            'tg_last_edit_at' => (string)($r['tg_last_edit_at'] ?? ''),
            'tg_message_id' => (int)($r['tg_message_id'] ?? 0),
        ];
    }

    usort($cards, function ($a, $b) {
        $aReceipt = trim((string)($a['receipt_number'] ?? ''));
        $bReceipt = trim((string)($b['receipt_number'] ?? ''));
        $aNum = ctype_digit($aReceipt) ? (int)$aReceipt : 0;
        $bNum = ctype_digit($bReceipt) ? (int)$bReceipt : 0;
        if ($aNum > 0 && $bNum > 0 && $aNum !== $bNum) {
            return $aNum <=> $bNum;
        }
        if ($aNum > 0 && $bNum === 0) return -1;
        if ($aNum === 0 && $bNum > 0) return 1;
        if ($aReceipt !== '' && $bReceipt !== '' && $aReceipt !== $bReceipt) {
            return $aReceipt <=> $bReceipt;
        }
        return ((int)($a['transaction_id'] ?? 0)) <=> ((int)($b['transaction_id'] ?? 0));
    });

    ob_start();
    $nowTs = time();
    foreach ($cards as $c) {
        if (empty($c['items'])) continue;
        $receipt = trim((string)$c['receipt_number']);
        $receiptLabel = $receipt !== '' ? $receipt : ('TX #' . (int)$c['transaction_id']);
        $table = trim((string)$c['table_number']);
        $waiter = trim((string)$c['waiter_name']);
        if ($waiter === '') $waiter = '—';
        ?>
        <div class="ko-card" data-tx-id="<?= (int)$c['transaction_id'] ?>">
            <div class="ko-card-header">
                <div class="ko-card-top">
                    <div class="ko-title"># <?= htmlspecialchars($receiptLabel) ?></div>
                    <div class="ko-table">🍽️ <?= htmlspecialchars($table !== '' ? $table : '—') ?></div>
                </div>
                <div class="ko-meta">
                    <span>Офик: <?= htmlspecialchars($waiter) ?></span>
                    <?php if (!empty($c['comment'])): ?>
                        <span class="ko-comment" style="display:inline-block; margin-left: 8px; font-size:12px; font-style:italic; color:#6b7280;" title="Комментарий к чеку"><?= htmlspecialchars($c['comment']) ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="ko-items">
                <?php foreach ($c['items'] as $it): ?>
                    <?php
                        $sentTs = (int)($it['sent_ts'] ?? 0);
                        $sentLabel = ($sentTs > 0) ? date('H:i:s', $sentTs) : '—';
                        $isOverdue = ($waitLimitMinutes > 0 && $sentTs > 0 && ($nowTs - $sentTs) >= ($waitLimitMinutes * 60));
                    ?>
                    <div class="ko-item<?= $isOverdue ? ' ko-item-overdue' : '' ?>" data-sent-ts="<?= (int)$sentTs ?>"<?= !empty($it['item_id']) ? (' data-item-id="' . (int)$it['item_id'] . '"') : '' ?>>
                        <div class="ko-item-name"><?= htmlspecialchars($it['dish_name'] ?: ('Dish #' . (int)$it['dish_id'])) ?></div>
                        <div class="ko-item-row">
                            <span class="ko-item-sent">Старт: <?= htmlspecialchars($sentLabel) ?></span>
                            <?php if ($sentTs > 0): ?>
                                <span class="ko-item-wait live-wait" data-sent-ts="<?= $sentTs ?>"><span class="live-time">00:00</span></span>
                            <?php else: ?>
                                <span class="ko-item-wait">—</span>
                            <?php endif; ?>
                            <?php
                                $tgSentAt = trim((string)($it['tg_sent_at'] ?? ''));
                                $tgLastEditAt = trim((string)($it['tg_last_edit_at'] ?? ''));
                                $tgMsgId = (int)($it['tg_message_id'] ?? 0);
                                $tgTitle = 'Telegram: уведомление не отправлено';
                                $tgClass = '';
                                $tgHref = '';
                                if ($tgSentAt !== '') {
                                    $tgClass = ' sent';
                                    $tgSentLabel = date('H:i:s', strtotime($tgSentAt));
                                    $tgTitle = 'Telegram: отправлено ' . $tgSentLabel;
                                    if ($tgLastEditAt !== '' && strtotime($tgLastEditAt) > strtotime($tgSentAt)) {
                                        $tgTitle .= '; обновлено ' . date('H:i:s', strtotime($tgLastEditAt));
                                    }
                                    if ($tgMsgId > 0) {
                                        $th = (int)$tgThreadIdVal;
                                        $uname = ltrim((string)$tgChatUsername, '@');
                                        $internal = (string)$tgChatInternalId;
                                        if ($uname !== '') {
                                            $tgHref = $th > 0
                                                ? ('https://t.me/' . $uname . '/' . $th . '/' . $tgMsgId)
                                                : ('https://t.me/' . $uname . '/' . $tgMsgId);
                                        } elseif ($internal !== '') {
                                            $tgHref = $th > 0
                                                ? ('https://t.me/c/' . $internal . '/' . $th . '/' . $tgMsgId)
                                                : ('https://t.me/c/' . $internal . '/' . $tgMsgId);
                                        }
                                    }
                                }
                            ?>
                            <?php if ($tgHref !== ''): ?>
                                <a class="tg-indicator<?= $tgClass ?>" href="<?= htmlspecialchars($tgHref, ENT_QUOTES) ?>" target="_blank" rel="noopener noreferrer" title="<?= htmlspecialchars($tgTitle, ENT_QUOTES) ?>" aria-label="<?= htmlspecialchars($tgTitle, ENT_QUOTES) ?>">
                            <?php else: ?>
                                <span class="tg-indicator<?= $tgClass ?>" title="<?= htmlspecialchars($tgTitle, ENT_QUOTES) ?>" aria-label="<?= htmlspecialchars($tgTitle, ENT_QUOTES) ?>">
                            <?php endif; ?>
                                <svg viewBox="0 0 240 240" aria-hidden="true" focusable="false">
                                    <path d="M120 0C53.7 0 0 53.7 0 120s53.7 120 120 120 120-53.7 120-120S186.3 0 120 0zm58.9 82.2-19.7 93.1c-1.5 6.6-5.5 8.2-11.1 5.1l-30.7-22.6-14.8 14.2c-1.6 1.6-3 3-6.1 3l2.2-31.6 57.5-51.9c2.5-2.2-.5-3.4-3.9-1.2l-71.1 44.8-30.6-9.6c-6.6-2.1-6.8-6.6 1.4-9.8l119.6-46.1c5.5-2 10.3 1.3 8.6 9.6z"/>
                                </svg>
                            <?php if ($tgHref !== ''): ?>
                                </a>
                            <?php else: ?>
                                </span>
                            <?php endif; ?>
                            <?php if (!empty($it['item_id']) && veranda_can('exclude_toggle')): ?>
                                <button type="button" class="ko-ack" title="Игнор" aria-label="Игнор" data-item-id="<?= (int)$it['item_id'] ?>">✕</button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }
    return ob_get_clean();
};

if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    try {
        $logger = new \App\Classes\EventLogger($db, 'kitchen_online', null, $_SESSION['user_email'] ?? null);
        $api = $api ?? new \App\Classes\PosterAPI($token);
        if ($action === 'list') {
            try {
                $analytics = new \App\Classes\KitchenAnalytics($api);
                $stats = $analytics->getDailyStats($today);
                if (is_array($stats) && count($stats) > 0) {
                    $db->saveStats($stats);
                }
                $now = date('Y-m-d H:i:s');
                $db->query(
                    "INSERT INTO {$metaTable} (meta_key, meta_value)
                     VALUES ('poster_last_sync_at', ?)
                     ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)",
                    [$now]
                );
                $lastSyncLabel = date('d.m.Y H:i:s', strtotime($now));
            } catch (\Throwable $e) {
            }
        }
        if ($action === 'refresh') {
            try {
                $metaRow = $db->query("SELECT meta_value FROM {$metaTable} WHERE meta_key = 'poster_last_sync_at' LIMIT 1")->fetch();
                $last = !empty($metaRow['meta_value']) ? strtotime((string)$metaRow['meta_value']) : 0;
                if ($last <= 0 || (time() - $last) >= 10) {
                    $analytics = new \App\Classes\KitchenAnalytics($api);
                    $stats = $analytics->getDailyStats($today);
                    if (is_array($stats) && count($stats) > 0) {
                        $db->saveStats($stats);
                    }
                    $now = date('Y-m-d H:i:s');
                    $db->query(
                        "INSERT INTO {$metaTable} (meta_key, meta_value)
                         VALUES ('poster_last_sync_at', ?)
                         ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)",
                        [$now]
                    );
                    $lastSyncLabel = date('d.m.Y H:i:s', strtotime($now));
                }
            } catch (\Throwable $e) {
            }
        }
        if ($action === 'exclude' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!veranda_can('exclude_toggle')) {
                http_response_code(403);
                $logger->warn('forbidden_exclude', ['item_id' => (int)($_POST['toggle_exclude_item'] ?? 0)]);
                echo json_encode(['ok' => false], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $itemId = (int)($_POST['toggle_exclude_item'] ?? 0);
            if ($itemId > 0) {
                $db->query("UPDATE {$ks} SET exclude_from_dashboard = 1, exclude_auto = 0 WHERE id = ?", [$itemId]);
            }
            $logger->info('exclude', ['item_id' => $itemId]);
            echo json_encode(['ok' => true, 'item_id' => $itemId], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($action === 'set_logclose' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $raw = file_get_contents('php://input');
            $j = json_decode($raw, true);
            $use = (int)($j['use'] ?? 1);
            try {
                $db->query(
                    "INSERT INTO {$metaTable} (meta_key, meta_value)
                     VALUES ('ko_use_logical_close', ?)
                     ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)",
                    [$use ? '1' : '0']
                );
            } catch (\Throwable $e) {}
            echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($action === 'refresh' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $txIds = $_POST['tx_ids'] ?? [];
            if (!is_array($txIds)) $txIds = [];
            $txIds = array_values(array_unique(array_filter(array_map(function ($v) {
                $i = (int)$v;
                return $i > 0 ? $i : null;
            }, $txIds))));
            if (count($txIds) > 40) {
                $txIds = array_slice($txIds, 0, 40);
            }

            $employeesById = null;
            $getEmployeeNameById = function (int $userId) use (&$employeesById, $api): string {
                if ($userId <= 0) return '';
                if ($employeesById === null) {
                    $employeesById = [];
                    try {
                        $employees = $api->request('access.getEmployees');
                        if (!is_array($employees)) $employees = [];
                        foreach ($employees as $employee) {
                            $id = (int)($employee['user_id'] ?? 0);
                            $name = trim((string)($employee['name'] ?? ''));
                            if ($id > 0 && $name !== '') {
                                $employeesById[$id] = $name;
                            }
                        }
                    } catch (\Exception $e) {
                        $employeesById = [];
                    }
                }
                return (string)($employeesById[$userId] ?? '');
            };

            $isDishDeletedFromHistory = function (array $history, int $dishId): bool {
                $lastStateTime = 0;
                $isDeleted = false;
                foreach ($history as $event) {
                    $type = $event['type_history'] ?? '';
                    $value = (int)($event['value'] ?? 0);
                    if ($value !== $dishId) continue;
                    $t = (int)($event['time'] ?? 0);
                    if ($type === 'changeitemcount') {
                        $count = (int)($event['value2'] ?? 0);
                        if ($t >= $lastStateTime) {
                            $lastStateTime = $t;
                            $isDeleted = $count <= 0;
                        }
                    } elseif ($type === 'deleteitem' || $type === 'delete') {
                        if ($t >= $lastStateTime) {
                            $lastStateTime = $t;
                            $isDeleted = true;
                        }
                    }
                }
                return $isDeleted;
            };

            foreach ($txIds as $txId) {
                $txId = (int)$txId;
                if ($txId <= 0) continue;

                $tx = null;
                try {
                    $res = $api->request('dash.getTransaction', ['transaction_id' => $txId]);
                    $tx = $res[0] ?? $res;
                } catch (\Exception $e) {
                    $tx = null;
                }

                if (is_array($tx)) {
                    $apiStatus = (int)($tx['status'] ?? 1);
                    $apiReason = isset($tx['reason']) && $tx['reason'] !== '' ? (int)$tx['reason'] : null;
                    $apiPayType = isset($tx['pay_type']) ? (int)$tx['pay_type'] : null;
                    if ($apiStatus > 1 || $apiReason !== null) {
                        if ($apiStatus <= 1) $apiStatus = 2;
                        $db->query(
                            "UPDATE {$ks} SET status = ?, pay_type = ?, close_reason = ? WHERE transaction_date = ? AND transaction_id = ?",
                            [$apiStatus, $apiPayType, $apiReason, $today, $txId]
                        );
                        continue;
                    }
                }

                $history = [];
                try {
                    $history = $api->request('dash.getTransactionHistory', ['transaction_id' => $txId]);
                    if (!is_array($history)) $history = [];
                } catch (\Exception $e) {
                    $history = [];
                }

                $waiterName = '';
                if (is_array($tx)) {
                    $empName = trim((string)($tx['employee_name'] ?? ''));
                    if ($empName !== '') {
                        $waiterName = $empName;
                    } else {
                        $name = trim((string)($tx['name'] ?? ''));
                        if ($name !== '' && !is_numeric($name)) {
                            $waiterName = $name;
                        } else {
                            $userId = (int)($tx['user_id'] ?? 0);
                            if ($userId <= 0) {
                                foreach ($history as $event) {
                                    $type = $event['type_history'] ?? '';
                                    if ($type === 'open' || $type === 'print') {
                                        $candidate = (int)($event['value'] ?? 0);
                                        if ($candidate > 0) {
                                            $userId = $candidate;
                                            break;
                                        }
                                    }
                                }
                            }
                            $waiterName = $getEmployeeNameById($userId);
                        }
                    }
                }
                if ($waiterName !== '') {
                    $db->query(
                        "UPDATE {$ks} SET waiter_name = ? WHERE transaction_date = ? AND transaction_id = ?",
                        [$waiterName, $today, $txId]
                    );
                }

                $itemSql = $useLogicalClose
                    ? "SELECT id, dish_id, was_deleted, ticket_sent_at
                     FROM {$ks}
                     WHERE transaction_date = ?
                       AND transaction_id = ?
                       AND status = 1
                       AND ready_pressed_at IS NULL
                       AND ticket_sent_at IS NOT NULL
                       AND COALESCE(exclude_from_dashboard, 0) = 0
                       AND NOT (COALESCE(dish_category_id, 0) = 47 OR COALESCE(dish_sub_category_id, 0) = 47)"
                    : "SELECT id, dish_id, was_deleted, ticket_sent_at
                     FROM {$ks}
                     WHERE transaction_date = ?
                       AND transaction_id = ?
                       AND status = 1
                       AND ready_pressed_at IS NULL
                       AND ticket_sent_at IS NOT NULL
                       AND NOT (COALESCE(exclude_from_dashboard, 0) = 1 AND COALESCE(exclude_auto, 0) = 0)
                       AND NOT (COALESCE(dish_category_id, 0) = 47 OR COALESCE(dish_sub_category_id, 0) = 47)";
                $items = $db->query($itemSql, [$today, $txId])->fetchAll();

                foreach ($items as $it) {
                    $id = (int)($it['id'] ?? 0);
                    $dishId = (int)($it['dish_id'] ?? 0);
                    if ($id <= 0 || $dishId <= 0) continue;

                    $readyTime = null;
                    foreach ($history as $event) {
                        if (($event['type_history'] ?? '') === 'finishedcooking' && (int)($event['value'] ?? 0) === $dishId) {
                            $readyTime = date('Y-m-d H:i:s', ((int)($event['time'] ?? 0)) / 1000);
                            break;
                        }
                    }
                    if ($readyTime !== null) {
                        $db->query("UPDATE {$ks} SET ready_pressed_at = ? WHERE id = ?", [$readyTime, $id]);
                        continue;
                    }

                    $deleted = $isDishDeletedFromHistory($history, $dishId);
                    if ($deleted) {
                        $db->query("UPDATE {$ks} SET was_deleted = 1 WHERE id = ?", [$id]);
                    }
                }
            }
        }

        $settings = [
            'alert_timing_low_load' => 20,
            'alert_load_threshold' => 25,
            'alert_timing_high_load' => 30,
            'exclude_partners_from_load' => 0
        ];
        $metaRepo = new \App\Classes\MetaRepository($db);
        $metaValues = $metaRepo->getMany(array_keys($settings));
        foreach ($settings as $key => $default) {
            $val = array_key_exists($key, $metaValues) ? $metaValues[$key] : $default;
            $settings[$key] = is_numeric($default) ? (int)$val : $val;
        }
        $loadCalculationCount = 0;
        if (!empty($settings['exclude_partners_from_load'])) {
            $otherCountRow = $db->query("SELECT COUNT(DISTINCT transaction_id) as c FROM {$ks} WHERE status = 1 AND transaction_date = ? AND table_number != 'Partners'", [$today])->fetch();
            $loadCalculationCount = (int)($otherCountRow['c'] ?? 0);
        } else {
            $openCountRow = $db->query("SELECT COUNT(DISTINCT transaction_id) as c FROM {$ks} WHERE status = 1 AND transaction_date = ?", [$today])->fetch();
            $loadCalculationCount = (int)($openCountRow['c'] ?? 0);
        }
        $waitLimitMinutes = ($loadCalculationCount < (int)$settings['alert_load_threshold'])
            ? (int)$settings['alert_timing_low_load']
            : (int)$settings['alert_timing_high_load'];

        try {
            $excludeSql = $useLogicalClose
                ? " AND COALESCE(ks.exclude_from_dashboard, 0) = 0 "
                : " AND NOT (COALESCE(ks.exclude_from_dashboard, 0) = 1 AND COALESCE(ks.exclude_auto, 0) = 0) ";
            $rows = $db->query(
                "SELECT
                        ks.id,
                        ks.transaction_id,
                        ks.receipt_number,
                        ks.table_number,
                        ks.waiter_name,
                        ks.transaction_comment,
                        ks.dish_id,
                        ks.dish_name,
                        ks.station,
                        ks.ticket_sent_at,
                        COALESCE(ks.tg_sent_at, tga.created_at) AS tg_sent_at,
                        COALESCE(ks.tg_last_edit_at, tga.updated_at) AS tg_last_edit_at,
                        COALESCE(ks.tg_message_id, tga.message_id) AS tg_message_id
                 FROM {$ks} ks
                 LEFT JOIN {$tgItems} tga
                   ON tga.transaction_date = ks.transaction_date
                  AND tga.kitchen_stats_id = ks.id
                 WHERE ks.transaction_date = ?
                   AND ks.status = 1
                   AND ks.ready_pressed_at IS NULL
                   AND ks.ticket_sent_at IS NOT NULL
                   AND COALESCE(ks.was_deleted, 0) = 0
               {$excludeSql}
                   AND NOT (COALESCE(ks.dish_category_id, 0) = 47 OR COALESCE(ks.dish_sub_category_id, 0) = 47)
                   {$stationSql}
                 ORDER BY ks.ticket_sent_at ASC",
                array_merge([$today], $stationParams)
            )->fetchAll();
        } catch (\Throwable $e) {
            $excludeSql = $useLogicalClose
                ? " AND COALESCE(ks.exclude_from_dashboard, 0) = 0 "
                : " AND NOT (COALESCE(ks.exclude_from_dashboard, 0) = 1 AND COALESCE(ks.exclude_auto, 0) = 0) ";
            $rows = $db->query(
                "SELECT
                        ks.id,
                        ks.transaction_id,
                        ks.receipt_number,
                        ks.table_number,
                        ks.waiter_name,
                        ks.transaction_comment,
                        ks.dish_id,
                        ks.dish_name,
                        ks.station,
                        ks.ticket_sent_at,
                        COALESCE(ks.tg_sent_at, tga.created_at) AS tg_sent_at,
                        COALESCE(ks.tg_last_edit_at, tga.updated_at) AS tg_last_edit_at,
                        COALESCE(ks.tg_message_id, tga.message_id) AS tg_message_id
                 FROM {$ks} ks
                 LEFT JOIN {$tgItems} tga
                   ON tga.transaction_date = ks.transaction_date
                  AND tga.kitchen_stats_id = ks.id
                 WHERE ks.transaction_date = ?
                   AND ks.status = 1
                   AND ks.ready_pressed_at IS NULL
                   AND ks.ticket_sent_at IS NOT NULL
                   AND COALESCE(ks.was_deleted, 0) = 0
               {$excludeSql}
                   AND NOT (COALESCE(ks.dish_category_id, 0) = 47 OR COALESCE(ks.dish_sub_category_id, 0) = 47)
                   {$stationSql}
                 ORDER BY ks.ticket_sent_at ASC",
                array_merge([$today], $stationParams)
            )->fetchAll();
        }

        $html = $renderCards($rows, $waitLimitMinutes);
        echo json_encode(['ok' => true, 'html' => $html, 'last_sync' => $lastSyncLabel, 'wait_limit_minutes' => $waitLimitMinutes], JSON_UNESCAPED_UNICODE);
    } catch (\Exception $e) {
        try {
            $logger = isset($logger) ? $logger : new \App\Classes\EventLogger($db, 'kitchen_online', null, $_SESSION['user_email'] ?? null);
            $logger->error('ajax_error', ['error' => $e->getMessage(), 'action' => (string)($_GET['action'] ?? '')]);
        } catch (\Exception $e2) {
        }
        echo json_encode(['ok' => false, 'error' => 'error'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

$dashboardQuery = http_build_query([
    'dateFrom' => $today,
    'dateTo' => $today,
    'hourStart' => 0,
    'hourEnd' => 23
]);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/svg+xml" href="/links/favicon.svg">
    <title>КухняOnline</title>
    <link rel="stylesheet" href="assets/app.css">
      <?php include $_SERVER['DOCUMENT_ROOT'] . '/analytics.php'; ?>
  <link rel="stylesheet" href="/assets/css/common.css">
  <link rel="stylesheet" href="/assets/css/kitchen_online.css">
</head>
<body>
    <div class="container">
        <div class="top-nav">
            <div class="nav-left">
                <div class="nav-title"><span class="ko-titlebar">КухняОнлайн <button type="button" class="ko-sound" id="soundToggle" aria-label="Звук">🔊</button></span></div>
            </div>
            <div class="nav-mid">
                <span>Последнее обновление из Poster: <span id="lastSync"><?= htmlspecialchars($lastSyncLabel) ?></span></span>
                <span class="ko-refresh" title="Следующее обновление">
                    <span class="ko-refresh-ring" aria-label="Следующее обновление">
                        <svg viewBox="0 0 36 36" aria-hidden="true">
                            <circle class="ko-refresh-track" cx="18" cy="18" r="15"></circle>
                            <circle class="ko-refresh-progress" id="refreshProgress" cx="18" cy="18" r="15"></circle>
                        </svg>
                        <span class="ko-refresh-text" id="refreshIn">10</span>
                    </span>
                </span>
                <label>
                    Цех:
                    <select id="station">
                        <option value="all">Все</option>
                        <option value="kitchen">Кухня</option>
                        <option value="bar">Бар</option>
                    </select>
                </label>
                <label style="display:flex; align-items:center; gap:6px;">
                    <input type="checkbox" id="useLogicalClose"<?= $useLogicalClose ? ' checked' : '' ?>>
                    <span style="font-weight:900;">УчитыватьВрЛогЗакр</span>
                </label>
            </div>
            <?php require __DIR__ . '/partials/user_menu.php'; ?>
        </div>

        <div id="cards" class="cards"></div>
        <div id="empty" class="empty" style="display:none;">ВСЕ ЗАКАЗЫ ВЫДАНЫ</div>
        <div class="ko-footer">
            Табло обновляется автоматически каждые 10 секунд. Если блюдо приготовили или убрали из чека — оно исчезнет из списка. Крестик «✕» рядом с блюдом означает «Игнор»: блюдо больше не будет показываться в табло и не будет учитываться в аналитике задержек.
        </div>
    </div>

    <script src="assets/app.js" defer></script>
    <script src="assets/user_menu.js" defer></script>
    <script src="/assets/js/kitchen_online.js"></script>
</body>
</html>
