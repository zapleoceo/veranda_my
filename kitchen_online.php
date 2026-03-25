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
                                        $uname = '<?= htmlspecialchars($tgChatUsername, ENT_QUOTES) ?>';
                                        $internal = '<?= htmlspecialchars($tgChatInternalId, ENT_QUOTES) ?>';
                                        if ($uname !== '') {
                                            $tgHref = $th > 0 ? ('https://t.me/' . $uname . '/' . $th . '/' . $tgMsgId) : ('https://t.me/' . $uname . '/' . $tgMsgId);
                                        } elseif ($internal !== '') {
                                            $tgHref = $th > 0 ? ('https://t.me/c/' . $internal . '/' . $th . '/' . $tgMsgId) : ('https://t.me/c/' . $internal . '/' . $tgMsgId);
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

                $items = $db->query(
                    "SELECT id, dish_id, was_deleted, ticket_sent_at
                     FROM {$ks}
                     WHERE transaction_date = ?
                       AND transaction_id = ?
                       AND status = 1
                       AND ready_pressed_at IS NULL
                       AND ticket_sent_at IS NOT NULL
                       AND COALESCE(exclude_from_dashboard, 0) = 0
                       AND NOT (COALESCE(dish_category_id, 0) = 47 OR COALESCE(dish_sub_category_id, 0) = 47)",
                    [$today, $txId]
                )->fetchAll();

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
                ? " AND COALESCE(exclude_from_dashboard, 0) = 0 "
                : " AND NOT (COALESCE(exclude_from_dashboard, 0) = 1 AND COALESCE(exclude_auto, 0) = 0) ";
            $rows = $db->query(
                "SELECT ks.id, ks.transaction_id, ks.receipt_number, ks.table_number, ks.waiter_name, ks.transaction_comment, ks.dish_id, ks.dish_name, ks.station, ks.ticket_sent_at,
                        ks.tg_sent_at, ks.tg_last_edit_at, ks.tg_message_id
                 FROM {$ks} ks
                 WHERE transaction_date = ?
                   AND status = 1
                   AND ready_pressed_at IS NULL
                   AND ticket_sent_at IS NOT NULL
                   AND COALESCE(was_deleted, 0) = 0
               {$excludeSql}
                   AND NOT (COALESCE(dish_category_id, 0) = 47 OR COALESCE(dish_sub_category_id, 0) = 47)
                   {$stationSql}
                 ORDER BY ticket_sent_at ASC",
                array_merge([$today], $stationParams)
            )->fetchAll();
        } catch (\Throwable $e) {
            $excludeSql = $useLogicalClose
                ? " AND COALESCE(exclude_from_dashboard, 0) = 0 "
                : " AND NOT (COALESCE(exclude_from_dashboard, 0) = 1 AND COALESCE(exclude_auto, 0) = 0) ";
            $rows = $db->query(
                "SELECT ks.id, ks.transaction_id, ks.receipt_number, ks.table_number, ks.waiter_name, ks.transaction_comment, ks.dish_id, ks.dish_name, ks.station, ks.ticket_sent_at,
                        ks.tg_sent_at, ks.tg_last_edit_at, ks.tg_message_id
                 FROM {$ks} ks
                 WHERE transaction_date = ?
                   AND status = 1
                   AND ready_pressed_at IS NULL
                   AND ticket_sent_at IS NOT NULL
                   AND COALESCE(was_deleted, 0) = 0
               {$excludeSql}
                   AND NOT (COALESCE(dish_category_id, 0) = 47 OR COALESCE(dish_sub_category_id, 0) = 47)
                   {$stationSql}
                 ORDER BY ticket_sent_at ASC",
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
    <style>
        body { font-family: Arial, sans-serif; background: #f5f6fa; margin: 0; padding: 0; }
        .container { width: 100%; max-width: 1800px; margin: 0 auto; padding: 12px; box-sizing: border-box; }
        .top-nav { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; gap: 16px; flex-wrap: wrap; }
        .nav-left { display: flex; gap: 14px; flex-wrap: wrap; align-items: center; min-width: 0; }
        .nav-left a { color: #1a73e8; text-decoration: none; font-weight: 500; }
        .nav-left a:hover { text-decoration: underline; }
        .nav-title { font-weight: 800; color: #2c3e50; }
        .nav-mid { display: flex; justify-content: center; align-items: center; gap: 14px; flex-wrap: wrap; color: #546e7a; font-size: 0.95em; flex: 1 1 360px; min-width: 260px; }
        .ko-refresh { display: inline-flex; align-items: center; gap: 10px; }
        .ko-refresh-ring { width: 34px; height: 34px; position: relative; display: inline-flex; align-items: center; justify-content: center; }
        .ko-refresh-ring svg { position: absolute; inset: 0; width: 34px; height: 34px; transform: rotate(-90deg); }
        .ko-refresh-text { position: relative; z-index: 1; width: 34px; height: 34px; border-radius: 50%; background: #fff; border: 1px solid #e5e7eb; display: inline-flex; align-items: center; justify-content: center; font-weight: 800; color: #37474f; font-size: 12px; box-sizing: border-box; }
        .ko-refresh-track { stroke: #e5e7eb; stroke-width: 4; fill: none; }
        .ko-refresh-progress { stroke: #1a73e8; stroke-width: 4; fill: none; stroke-linecap: round; transition: stroke-dashoffset 0.1s linear; }
        .user-menu { position: relative; }
        .user-chip { display: inline-flex; align-items: center; gap: 8px; padding: 6px 10px; border: 1px solid #e5e7eb; border-radius: 999px; background: #fff; color: #37474f; font-weight: 600; cursor: default; }
        .user-icon { width: 22px; height: 22px; border-radius: 50%; background: #e3f2fd; display: inline-flex; align-items: center; justify-content: center; color: #1a73e8; font-weight: 800; font-size: 12px; overflow: hidden; }
        .user-icon img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .user-dropdown { position: absolute; right: 0; top: calc(100% + 8px); background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; box-shadow: 0 8px 18px rgba(0,0,0,0.12); padding: 8px; min-width: 160px; display: none; z-index: 1000; }
        .user-menu.open .user-dropdown { display: block; }
        .user-dropdown a { display: block; padding: 8px 10px; border-radius: 8px; color: #37474f; text-decoration: none; font-weight: 600; }
        .user-dropdown a:hover { background: #f5f6fa; }
        h1 { text-align: center; color: #2c3e50; margin-bottom: 10px; }
        .ko-titlebar { display: inline-flex; align-items: center; gap: 10px; }
        .ko-sound { border: 0; background: transparent; cursor: pointer; font-size: 18px; line-height: 1; padding: 4px 6px; color: #546e7a; }
        .ko-sound:hover { color: #1a73e8; }
        .nav-mid select { padding: 8px 12px; border-radius: 6px; border: 1px solid #d0d5dd; background: #fff; }
        .wait-spinner { display: inline-block; width: 10px; height: 10px; border: 2px solid rgba(245, 124, 0, 0.3); border-top-color: #f57c00; border-radius: 50%; margin-right: 6px; animation: waitSpin 0.9s linear infinite; vertical-align: -1px; }
        @keyframes waitSpin { to { transform: rotate(360deg); } }
        .cards { display: flex; flex-wrap: nowrap; gap: 10px; align-items: flex-start; overflow-x: auto; overflow-y: hidden; padding-bottom: 8px; }
        .ko-card { flex: 0 0 auto; }
        .ko-card { width: 240px; background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; box-shadow: 0 3px 10px rgba(0,0,0,0.06); overflow: hidden; }
        .ko-card-header { padding: 8px 10px; border-bottom: 1px solid #eee; background: #fafafa; }
        .ko-card-top { display: flex; justify-content: space-between; align-items: center; gap: 12px; }
        .ko-title { font-weight: 800; color: #2c3e50; }
        .ko-table { font-weight: 800; color: #2c3e50; white-space: nowrap; }
        .ko-meta { margin-top: 6px; display: flex; flex-direction: column; gap: 4px; font-size: 13px; color: #607d8b; }
        .ko-items { padding: 8px 10px; display: flex; flex-direction: column; gap: 8px; }
        .ko-item { border: 1px solid #eef2f6; border-radius: 10px; padding: 6px; background: #fff; box-sizing: border-box; }
        .ko-item-overdue { border: 2px solid #d32f2f; }
        .ko-item-name { font-weight: 700; color: #263238; }
        .ko-item-row { margin-top: 4px; display: flex; justify-content: space-between; gap: 2px; font-size: 13px; color: #546e7a; }
        .ko-item-wait { font-weight: 700; color: #d32f2f; white-space: nowrap; }
        .ko-ack { border: 0; background: transparent; color: #9aa0a6; font-size: 18px; line-height: 1; cursor: pointer; padding: 0 4px; }
        .ko-ack:hover { color: #5f6368; }
        .ko-ack:disabled { opacity: 0.4; cursor: default; }
        .tg-indicator { display:inline-flex; align-items:center; justify-content:center; width:18px; height:18px; }
        .tg-indicator svg { width:16px; height:16px; }
        .tg-indicator svg path { fill: #b0b7c3; }
        .tg-indicator.sent svg path { fill: #26a5e4; }
        .empty { text-align: center; color: #65676b; margin-top: 18px; }
        .ko-footer { margin-top: 18px; text-align: center; color: #607d8b; font-size: 13px; line-height: 1.5; }
    </style>
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
    <script>

        const cardsEl = document.getElementById('cards');
        const emptyEl = document.getElementById('empty');
        const stationEl = document.getElementById('station');
        const lastSyncEl = document.getElementById('lastSync');
        const refreshInEl = document.getElementById('refreshIn');
        const refreshProgressEl = document.getElementById('refreshProgress');
        const soundBtn = document.getElementById('soundToggle');
        let loading = false;
        const refreshIntervalSec = 10;
        let refreshCycleStartedAt = Date.now();
        let refreshCircleLen = 0;
        let waitLimitSec = 0;
        let seenIds = null;
        let soundMuted = false;
        let audioCtx = null;
        let isRefreshing = false;

        const loadMuted = () => {
            try { soundMuted = (localStorage.getItem('ko_sound_muted') === '1'); } catch (_) { soundMuted = false; }
        };
        const saveMuted = () => {
            try { localStorage.setItem('ko_sound_muted', soundMuted ? '1' : '0'); } catch (_) {}
        };
        const renderSoundIcon = () => {
            if (!soundBtn) return;
            soundBtn.textContent = soundMuted ? '🔇' : '🔊';
        };
        const ensureAudio = async () => {
            if (audioCtx) return true;
            const Ctx = window.AudioContext || window.webkitAudioContext;
            if (!Ctx) return false;
            audioCtx = new Ctx();
            try { await audioCtx.resume(); } catch (_) {}
            return true;
        };
        const beep = async () => {
            if (soundMuted) return;
            const ok = await ensureAudio();
            if (!ok || !audioCtx) return;
            if (audioCtx.state === 'suspended') {
                try { await audioCtx.resume(); } catch (_) { return; }
            }
            const o = audioCtx.createOscillator();
            const g = audioCtx.createGain();
            o.type = 'sine';
            o.frequency.value = 880;
            g.gain.value = 0.0001;
            o.connect(g);
            g.connect(audioCtx.destination);
            const now = audioCtx.currentTime;
            g.gain.setValueAtTime(0.0001, now);
            g.gain.exponentialRampToValueAtTime(0.15, now + 0.01);
            g.gain.exponentialRampToValueAtTime(0.0001, now + 0.25);
            o.start(now);
            o.stop(now + 0.26);
        };

        const extractItemIds = () => {
            const els = Array.from(cardsEl.querySelectorAll('.ko-item[data-item-id]'));
            return els.map(el => parseInt(el.getAttribute('data-item-id') || '0', 10)).filter(n => n > 0);
        };
        const detectNewItems = () => {
            const current = extractItemIds();
            if (seenIds === null) {
                seenIds = new Set(current);
                return;
            }
            const curSet = new Set(current);
            let hasNew = false;
            for (const id of curSet) {
                if (!seenIds.has(id)) { hasNew = true; break; }
            }
            seenIds = curSet;
            if (hasNew) beep();
        };

        const updateLive = () => {
            const els = Array.from(document.getElementsByClassName('live-wait'));
            const nowSec = Math.floor(Date.now() / 1000);
            for (const el of els) {
                const sentTs = parseInt(el.dataset.sentTs || '0', 10);
                if (!sentTs) continue;
                const diffSec = Math.max(0, nowSec - sentTs);
                if (waitLimitSec > 0) {
                    const itemEl = el.closest('.ko-item');
                    if (itemEl) itemEl.classList.toggle('ko-item-overdue', diffSec >= waitLimitSec);
                    const remaining = Math.max(0, waitLimitSec - diffSec);
                    const ratio = Math.max(0, Math.min(1, remaining / waitLimitSec));
                    const pct = Math.round(ratio * 100);
                    el.style.background = `linear-gradient(to right, rgba(38,165,228,0.18) 0%, rgba(38,165,228,0.18) ${pct}%, transparent ${pct}%, transparent 100%)`;
                    el.style.borderRadius = '6px';
                    el.style.padding = '1px 4px';
                }
                const mm = Math.floor(diffSec / 60);
                const ss = diffSec % 60;
                const out = String(mm).padStart(2, '0') + ':' + String(ss).padStart(2, '0');
                const t = el.querySelector('.live-time');
                if (t) t.textContent = out;
            }
        };

        let reqSeq = 0;
        let activeCtrl = null;
        const request = async (method, action, payload) => {
            reqSeq += 1;
            const mySeq = reqSeq;
            if (activeCtrl) {
                try { activeCtrl.abort(); } catch (_) {}
            }
            activeCtrl = (typeof AbortController !== 'undefined') ? new AbortController() : null;
            loading = true;
            isRefreshing = true;
            try {
                const params = new URLSearchParams();
                params.set('ajax', '1');
                params.set('action', action);
                params.set('station', stationEl.value);
                params.set('_ts', String(Date.now()));
                const init = {
                    method,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    cache: 'no-store',
                    signal: activeCtrl ? activeCtrl.signal : undefined,
                    body: payload
                };
                const res = await fetch(`kitchen_online.php?${params.toString()}`, init);
                const data = await res.json();
                if (mySeq !== reqSeq) return null;
                return data;
            } catch (e) {
                if (e && e.name === 'AbortError') return null;
                return null;
            } finally {
                if (mySeq === reqSeq) {
                    loading = false;
                    isRefreshing = false;
                }
            }
        };

        const loadCards = async (action = 'list') => {
            const data = await request('GET', action, undefined);
            if (!data || !data.ok) return;
            cardsEl.innerHTML = data.html || '';
            emptyEl.style.display = (cardsEl.children.length === 0) ? 'block' : 'none';
            if (data.last_sync) lastSyncEl.textContent = data.last_sync;
            if (typeof data.wait_limit_minutes === 'number') waitLimitSec = data.wait_limit_minutes * 60;
            updateLive();
            detectNewItems();
        };

        const refreshVisible = async () => {
            const txIds = Array.from(cardsEl.querySelectorAll('.ko-card'))
                .map(el => parseInt(el.dataset.txId || '0', 10))
                .filter(n => n > 0);
            if (txIds.length === 0) {
                await loadCards('list');
                return;
            }
            const payload = new FormData();
            for (const id of txIds) payload.append('tx_ids[]', String(id));
            const data = await request('POST', 'refresh', payload);
            if (!data || !data.ok) return;
            cardsEl.innerHTML = data.html || '';
            emptyEl.style.display = (cardsEl.children.length === 0) ? 'block' : 'none';
            if (data.last_sync) lastSyncEl.textContent = data.last_sync;
            if (typeof data.wait_limit_minutes === 'number') waitLimitSec = data.wait_limit_minutes * 60;
            updateLive();
            detectNewItems();
        };

        stationEl.addEventListener('change', () => {
            refreshCycleStartedAt = Date.now();
            loadCards('list');
        });
        loadMuted();
        renderSoundIcon();
        if (soundBtn) {
            soundBtn.addEventListener('click', async () => {
                soundMuted = !soundMuted;
                saveMuted();
                renderSoundIcon();
                if (!soundMuted) {
                    await ensureAudio();
                    beep();
                }
            });
        }

        cardsEl.addEventListener('wheel', (e) => {
            if (!cardsEl || cardsEl.scrollWidth <= cardsEl.clientWidth) return;
            if (e.shiftKey) return;
            const dx = Math.abs(e.deltaX || 0);
            const dy = Math.abs(e.deltaY || 0);
            if (dx > dy) return;
            e.preventDefault();
            cardsEl.scrollLeft += e.deltaY;
        }, { passive: false });

        cardsEl.addEventListener('click', async (e) => {
            const btn = e.target.closest('button.ko-ack');
            if (!btn) return;
            const itemId = parseInt(btn.dataset.itemId || '0', 10);
            if (!itemId) return;
            if (btn.disabled) return;
            btn.disabled = true;
            try {
                const payload = new FormData();
                payload.set('toggle_exclude_item', String(itemId));
                payload.set('exclude_from_dashboard', '1');
                const params = new URLSearchParams();
                params.set('ajax', '1');
                params.set('action', 'exclude');
                const res = await fetch(`kitchen_online.php?${params.toString()}`, { method: 'POST', body: payload, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                const data = await res.json();
                if (!data || !data.ok) throw new Error('bad');
                const itemEl = btn.closest('.ko-item');
                if (itemEl) itemEl.remove();
                const cardEl = btn.closest('.ko-card');
                if (cardEl && cardEl.querySelectorAll('.ko-item').length === 0) {
                    cardEl.remove();
                }
                emptyEl.style.display = (cardsEl.children.length === 0) ? 'block' : 'none';
            } catch (err) {
                btn.disabled = false;
            }
        });

        loadCards('list');
        setInterval(updateLive, 1000);
        if (refreshProgressEl) {
            try {
                refreshCircleLen = refreshProgressEl.getTotalLength();
                refreshProgressEl.style.strokeDasharray = String(refreshCircleLen);
                refreshProgressEl.style.strokeDashoffset = String(refreshCircleLen);
            } catch (_) {
                refreshCircleLen = 0;
            }
        }
        const renderRefreshCountdown = () => {
            const now = Date.now();
            const durMs = refreshIntervalSec * 1000;
            const elapsed = Math.max(0, Math.min(durMs, now - refreshCycleStartedAt));
            const remainingMs = Math.max(0, durMs - elapsed);
            const remainingSec = Math.max(0, Math.floor((Math.max(0, remainingMs) - 1) / 1000));
            if (refreshInEl) refreshInEl.textContent = isRefreshing ? '…' : String(remainingSec);
            if (refreshProgressEl && refreshCircleLen > 0) {
                const progress = isRefreshing ? 0 : (elapsed / durMs);
                refreshProgressEl.style.strokeDashoffset = String(refreshCircleLen * (1 - progress));
            }
        };
        renderRefreshCountdown();
        setInterval(renderRefreshCountdown, 100);
        setInterval(() => {
            refreshCycleStartedAt = Date.now();
            if (refreshProgressEl && refreshCircleLen > 0) {
                refreshProgressEl.style.strokeDashoffset = String(refreshCircleLen);
            }
            renderRefreshCountdown();
            refreshVisible();
        }, refreshIntervalSec * 1000);
    </script>
</body>
</html>
