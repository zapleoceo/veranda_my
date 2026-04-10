<?php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/src/classes/PosterAPI.php';
require_once __DIR__ . '/src/classes/PosterMenuSync.php';
require_once __DIR__ . '/src/classes/MenuAutoFill.php';
require_once __DIR__ . '/src/classes/TelegramBot.php';
require_once __DIR__ . '/src/classes/MetaRepository.php';
veranda_require('admin');

$posterToken = $_ENV['POSTER_API_TOKEN'] ?? null;
if (!is_string($posterToken) || $posterToken === '') {
    if (file_exists(__DIR__ . '/.env')) {
        $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0) continue;
            if (strpos($line, '=') === false) continue;
            [$name, $value] = explode('=', $line, 2);
            $_ENV[$name] = trim($value);
        }
    }
    $posterToken = $_ENV['POSTER_API_TOKEN'] ?? '';
}
$posterToken = (string)$posterToken;

$message = '';
$error = '';

$tab = (string)($_GET['tab'] ?? 'sync');
if ($tab === 'main') $tab = 'access';
if (!in_array($tab, ['sync', 'access', 'telegram', 'menu', 'categories', 'reservations'], true)) {
    $tab = 'sync';
}

$usersTable = $db->t('users');
$metaTable = $db->t('system_meta');

$usersCols = [];
try {
    $cols = $db->query("SHOW COLUMNS FROM {$usersTable}")->fetchAll();
    foreach ($cols as $c) {
        $f = strtolower((string)($c['Field'] ?? ''));
        if ($f !== '') $usersCols[$f] = true;
    }
} catch (\Throwable $e) {
    $usersCols = [];
}

try {
    if (empty($usersCols['name'])) {
        $db->query("ALTER TABLE {$usersTable} ADD COLUMN name VARCHAR(255) NULL");
        $usersCols['name'] = true;
    }
} catch (\Throwable $e) {
}

$permissionKeys = [
    'dashboard' => 'Дашборд',
    'rawdata' => 'Сырые данные',
    'kitchen_online' => 'КухняOnline',
    'errors' => 'Cooked (errors)',
    'zapara' => 'Zapara',
    'employees' => 'ЗП сотрудников',
    'payday' => 'Payday',
    'admin' => 'УПРАВЛЕНИЕ',
    'roma' => 'Roma (кальяны)',
    'banya' => 'Отчет баня',
    'reservations' => 'Брони',
    'exclude_toggle' => 'Игнор + ✅ Принято (Telegram)',
    'telegram_ack' => '✅ Принято (Telegram)',
];

if (isset($_POST['save_user_permissions'])) {
    $targetEmail = trim((string)($_POST['perm_email'] ?? ''));
    if ($targetEmail !== '') {
        $perms = [];
        foreach ($permissionKeys as $k => $_label) {
            $perms[$k] = isset($_POST['perm_' . $k]) ? 1 : 0;
        }
        $perms['telegram_ack'] = !empty($perms['exclude_toggle']) ? 1 : 0;
        $tgUsername = strtolower(trim((string)($_POST['perm_tg_username'] ?? '')));
        $tgUsername = ltrim($tgUsername, '@');
        if ($tgUsername === '') {
            $tgUsername = null;
        }
        $setParts = [];
        $params = [];
        if (!empty($usersCols['permissions_json'])) {
            $setParts[] = "permissions_json = ?";
            $params[] = json_encode($perms, JSON_UNESCAPED_UNICODE);
        }
        if (!empty($usersCols['telegram_username'])) {
            $setParts[] = "telegram_username = ?";
            $params[] = $tgUsername;
        }
        if (!empty($setParts)) {
            $params[] = $targetEmail;
            $db->query("UPDATE {$usersTable} SET " . implode(', ', $setParts) . " WHERE email = ? LIMIT 1", $params);
            $message = "Права для $targetEmail сохранены.";
        } else {
            $error = 'В таблице users нет колонок для сохранения прав (permissions_json/telegram_username).';
        }
    }
}

// Add user
if (isset($_POST['add_email'])) {
    $email = trim($_POST['email']);
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        try {
            if (!empty($usersCols['permissions_json'])) {
                $emptyPerms = [];
                foreach ($permissionKeys as $k => $_label) {
                    $emptyPerms[$k] = 0;
                }
                $emptyPerms['telegram_ack'] = 0;
                $db->query(
                    "INSERT INTO {$usersTable} (email, permissions_json) VALUES (?, ?)",
                    [$email, json_encode($emptyPerms, JSON_UNESCAPED_UNICODE)]
                );
            } else {
                $db->query("INSERT INTO {$usersTable} (email) VALUES (?)", [$email]);
            }
            $message = "Пользователь $email успешно добавлен.";
        } catch (\Exception $e) {
            $error = "Ошибка при добавлении: " . $e->getMessage();
        }
    } else {
        $error = "Некорректный email.";
    }
}

if (isset($_POST['add_self'])) {
    $email = trim((string)($_SESSION['user_email'] ?? ''));
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        try {
            if (!empty($usersCols['is_active'])) {
                $db->query(
                    "INSERT INTO {$usersTable} (email, is_active) VALUES (?, 1)
                     ON DUPLICATE KEY UPDATE is_active = 1",
                    [$email]
                );
            } else {
                $db->query("INSERT INTO {$usersTable} (email) VALUES (?)", [$email]);
            }
            $message = "Пользователь $email успешно добавлен.";
        } catch (\Exception $e) {
            $error = "Ошибка при добавлении: " . $e->getMessage();
        }
    } else {
        $error = "Некорректный email.";
    }
}

// Delete user
if (isset($_GET['delete'])) {
    $emailToDelete = $_GET['delete'];
    // Prevent deleting own email
    if ($emailToDelete !== $_SESSION['user_email']) {
        $db->query("DELETE FROM {$usersTable} WHERE email = ?", [$emailToDelete]);
        $message = "Пользователь $emailToDelete удален.";
    } else {
        $error = "Вы не можете удалить свой собственный email.";
    }
}

$users = [];
try {
    $select = ['email'];
    if (!empty($usersCols['name'])) $select[] = 'name';
    if (!empty($usersCols['telegram_username'])) $select[] = 'telegram_username';
    if (!empty($usersCols['permissions_json'])) $select[] = 'permissions_json';
    if (!empty($usersCols['created_at'])) {
        $select[] = 'created_at';
    } else {
        $select[] = 'NULL AS created_at';
    }
    $orderBy = !empty($usersCols['created_at']) ? 'created_at DESC' : 'email ASC';
    $users = $db->query("SELECT " . implode(', ', $select) . " FROM {$usersTable} ORDER BY {$orderBy}")->fetchAll();
} catch (\Throwable $e) {
    $users = [];
    if ($error === '') {
        $error = 'Ошибка чтения списка пользователей: ' . $e->getMessage();
    }
}

// Settings logic
$settingKeys = [
    'alert_timing_low_load' => 20,
    'alert_load_threshold' => 25,
    'alert_timing_high_load' => 30,
    'alert_ack_snooze_minutes' => 15,
    'exclude_partners_from_load' => 0
];

if (isset($_POST['save_settings']) || array_key_exists('exclude_partners_from_load', $_POST)) {
    foreach ($settingKeys as $key => $default) {
        $val = $_POST[$key] ?? $default;
        if (is_numeric($default)) {
            $val = (int)$val;
        }
        $db->query("INSERT INTO {$metaTable} (meta_key, meta_value) VALUES (?, ?) 
                    ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)", [$key, $val]);
    }
    $message = "Настройки успешно сохранены.";
}

$settings = [];
foreach ($settingKeys as $key => $default) {
    $row = $db->query("SELECT meta_value FROM {$metaTable} WHERE meta_key = ? LIMIT 1", [$key])->fetch();
    $settings[$key] = $row ? $row['meta_value'] : $default;
    if (is_numeric($default)) {
        $settings[$key] = (int)$settings[$key];
    }
}

$resHallId = max(1, (int)($_GET['hall_id'] ?? 2));
$resSpotId = max(1, (int)($_GET['spot_id'] ?? 1));
$resMetaKey = 'reservations_allowed_scheme_nums_hall_' . $resHallId;
$resCapsMetaKey = 'reservations_table_caps_hall_' . $resHallId;
$resAllowedNums = [];
$resCapsByNum = [];
$resTables = [];

if ($tab === 'reservations') {
    $metaRepo = new \App\Classes\MetaRepository($db);
    $defaultCaps = [
        '1' => 8, '2' => 8, '3' => 8,
        '4' => 5, '5' => 5, '6' => 5,
        '7' => 8,
        '8' => 2, '9' => 2, '10' => 2, '11' => 2,
        '12' => 3, '13' => 3, '14' => 3,
        '15' => 5, '16' => 5, '17' => 5, '18' => 5, '19' => 5,
        '20' => 15,
    ];

    if (isset($_POST['save_reservation_tables'])) {
        $resHallIdPost = max(1, (int)($_POST['hall_id'] ?? $resHallId));
        $resSpotIdPost = max(1, (int)($_POST['spot_id'] ?? $resSpotId));
        $resMetaKeyPost = 'reservations_allowed_scheme_nums_hall_' . $resHallIdPost;
        $resCapsMetaKeyPost = 'reservations_table_caps_hall_' . $resHallIdPost;

        $raw = $_POST['allowed_nums'] ?? [];
        $nums = [];
        if (is_array($raw)) {
            foreach ($raw as $v) {
                $s = trim((string)$v);
                if (!preg_match('/^\d+$/', $s)) continue;
                $n = (int)$s;
                if ($n < 1 || $n > 500) continue;
                $nums[$n] = true;
            }
        }
        $nums = array_values(array_keys($nums));
        sort($nums);

        $db->query(
            "INSERT INTO {$metaTable} (meta_key, meta_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value), updated_at = CURRENT_TIMESTAMP",
            [$resMetaKeyPost, json_encode($nums, JSON_UNESCAPED_UNICODE)]
        );

        $capsRaw = $_POST['caps'] ?? [];
        $caps = [];
        if (is_array($capsRaw)) {
            foreach ($capsRaw as $k => $v) {
                $k = trim((string)$k);
                if (!preg_match('/^\d+$/', $k)) continue;
                $n = (int)$k;
                if ($n < 1 || $n > 500) continue;
                $c = (int)$v;
                if ($c < 0) $c = 0;
                if ($c > 999) $c = 999;
                $caps[(string)$n] = $c;
            }
        }
        if (!$caps) $caps = $defaultCaps;
        ksort($caps, SORT_NATURAL);
        $db->query(
            "INSERT INTO {$metaTable} (meta_key, meta_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value), updated_at = CURRENT_TIMESTAMP",
            [$resCapsMetaKeyPost, json_encode($caps, JSON_UNESCAPED_UNICODE)]
        );

        $message = 'Список доступных столов сохранён.';
        $resHallId = $resHallIdPost;
        $resSpotId = $resSpotIdPost;
        $resMetaKey = $resMetaKeyPost;
        $resCapsMetaKey = $resCapsMetaKeyPost;
    }

    $saved = $metaRepo->getMany([$resMetaKey, $resCapsMetaKey]);
    $stored = array_key_exists($resMetaKey, $saved) ? trim((string)$saved[$resMetaKey]) : '';
    if ($stored !== '') {
        $decoded = json_decode($stored, true);
        if (is_array($decoded)) {
            foreach ($decoded as $v) {
                $n = (int)$v;
                if ($n >= 1 && $n <= 500) $resAllowedNums[(string)$n] = true;
            }
        } else {
            foreach (explode(',', $stored) as $part) {
                $part = trim($part);
                if ($part === '' || !preg_match('/^\d+$/', $part)) continue;
                $n = (int)$part;
                if ($n >= 1 && $n <= 500) $resAllowedNums[(string)$n] = true;
            }
        }
    }

    $capsStored = array_key_exists($resCapsMetaKey, $saved) ? trim((string)$saved[$resCapsMetaKey]) : '';
    $capsDecoded = $capsStored !== '' ? json_decode($capsStored, true) : null;
    if (is_array($capsDecoded)) {
        foreach ($capsDecoded as $k => $v) {
            $k = trim((string)$k);
            if (!preg_match('/^\d+$/', $k)) continue;
            $n = (int)$k;
            if ($n < 1 || $n > 500) continue;
            $c = (int)$v;
            if ($c < 0) $c = 0;
            if ($c > 999) $c = 999;
            $resCapsByNum[(string)$n] = $c;
        }
    } else {
        $resCapsByNum = $defaultCaps;
    }

    if ($posterToken === '') {
        $error = $error ?: 'POSTER_API_TOKEN не задан.';
    } else {
        try {
            $api = new \App\Classes\PosterAPI($posterToken);
            $rows = $api->request('spots.getTableHallTables', [
                'spot_id' => $resSpotId,
                'hall_id' => $resHallId,
                'without_deleted' => 1,
            ], 'GET');
            $rows = is_array($rows) ? $rows : [];
            foreach ($rows as $r) {
                if (!is_array($r)) continue;
                $tableId = trim((string)($r['table_id'] ?? ''));
                $tableNum = trim((string)($r['table_num'] ?? ''));
                $tableTitle = trim((string)($r['table_title'] ?? ''));
                $scheme = null;
                if (preg_match('/^\d+$/', $tableTitle)) $scheme = (int)$tableTitle;
                elseif (preg_match('/^\d+$/', $tableNum)) $scheme = (int)$tableNum;
                $resTables[] = [
                    'table_id' => $tableId,
                    'table_num' => $tableNum,
                    'table_title' => $tableTitle,
                    'scheme_num' => $scheme !== null ? (string)$scheme : '',
                    'is_allowed' => $scheme !== null && isset($resAllowedNums[(string)$scheme]) ? 1 : 0,
                    'cap' => $scheme !== null ? (int)($resCapsByNum[(string)$scheme] ?? ($defaultCaps[(string)$scheme] ?? 0)) : 0,
                ];
            }
        } catch (\Throwable $e) {
            $error = $error ?: ('Ошибка Poster API: ' . $e->getMessage());
        }
    }
}

$isMenuAjax = ($_GET['ajax'] ?? '') === 'menu_publish';
if ($isMenuAjax) {
    $pmi = $db->t('poster_menu_items');
    $mi = $db->t('menu_items');
    $miTr = $db->t('menu_item_tr');
    $mc = $db->t('menu_categories');
    header('Content-Type: application/json; charset=utf-8');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $payload = json_decode(file_get_contents('php://input') ?: '[]', true);
    if (!is_array($payload)) {
        $payload = [];
    }
    $posterId = (int)($payload['poster_id'] ?? 0);
    $isPublished = isset($payload['is_published']) ? (bool)$payload['is_published'] : null;
    if ($posterId <= 0 || $isPublished === null) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Bad request'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $row = $db->query(
        "SELECT
                p.id poster_item_id,
                p.is_active,
                p.main_category_id,
                p.sub_category_id,
                mi.id menu_item_id,
                mi.is_published,
                ru.title ru_title,
                en.title en_title,
                vn.title vn_title
         FROM {$pmi} p
         LEFT JOIN {$mi} mi ON mi.poster_item_id = p.id
         LEFT JOIN {$miTr} ru ON ru.item_id = mi.id AND ru.lang = 'ru'
         LEFT JOIN {$miTr} en ON en.item_id = mi.id AND en.lang = 'en'
         LEFT JOIN {$miTr} vn ON vn.item_id = mi.id AND vn.lang = 'vn'
         WHERE p.poster_id = ?
         LIMIT 1",
        [$posterId]
    )->fetch();

    if (!$row) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Not found'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ((int)$row['is_active'] === 0) {
        $menuItemId = (int)($row['menu_item_id'] ?? 0);
        if ($menuItemId > 0) {
            $db->query("UPDATE {$mi} SET is_published = 0 WHERE id = ? LIMIT 1", [$menuItemId]);
        }
        echo json_encode(['ok' => true, 'is_published' => false, 'disabled' => true, 'reason' => 'not_found'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $menuItemId = (int)($row['menu_item_id'] ?? 0);
    if ($menuItemId <= 0) {
        $subPosterCategoryId = (int)($row['sub_category_id'] ?? 0);
        if ($subPosterCategoryId > 0) {
            $categoryRow = $db->query("SELECT id FROM {$mc} WHERE poster_id = ? LIMIT 1", [$subPosterCategoryId])->fetch();
            $categoryId = (int)($categoryRow['id'] ?? 0);
            if ($categoryId > 0) {
                $posterItemId = (int)($row['poster_item_id'] ?? 0);
                $db->query(
                    "INSERT INTO {$mi} (poster_item_id, category_id, image_url, is_published, sort_order)
                     VALUES (?, ?, NULL, 0, 0)
                     ON DUPLICATE KEY UPDATE category_id = VALUES(category_id)",
                    [$posterItemId, $categoryId]
                );
                $menuItemId = (int)$db->query("SELECT id FROM {$mi} WHERE poster_item_id = ? LIMIT 1", [$posterItemId])->fetchColumn();
            }
        }
    }
    if ($menuItemId <= 0) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'Нет записи menu_items для этого блюда. Выполните синхронизацию меню из Poster.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($isPublished) {
        $ruTitle = trim((string)($row['ru_title'] ?? ''));
        $enTitle = trim((string)($row['en_title'] ?? ''));
        $vnTitle = trim((string)($row['vn_title'] ?? ''));
        if ($ruTitle === '' || $enTitle === '' || $vnTitle === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Неадаптировано: заполните названия RU/EN/VN перед публикацией'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    $db->query(
        "UPDATE {$mi} SET is_published = ? WHERE id = ? LIMIT 1",
        [$isPublished ? 1 : 0, $menuItemId]
    );
    echo json_encode(['ok' => true, 'is_published' => $isPublished], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_GET['ajax'] ?? '') === 'telegram_test') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $tgToken = trim((string)($_ENV['TELEGRAM_BOT_TOKEN'] ?? $_ENV['TG_BOT_TOKEN'] ?? ''));
        $tgChatId = trim((string)($_ENV['TELEGRAM_CHAT_ID'] ?? $_ENV['TG_CHAT_ID'] ?? ''));
        $tgThreadId = trim((string)($_ENV['TELEGRAM_THREAD_ID'] ?? $_ENV['TG_THREAD_ID'] ?? ''));
        $tgThreadNum = $tgThreadId !== '' ? (int)$tgThreadId : 0;
        if ($tgToken === '' || $tgChatId === '') {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Missing TELEGRAM_* in .env'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $text = (string)($_POST['text'] ?? $_GET['text'] ?? 'Тест: статус проверки');
        $bot = new \App\Classes\TelegramBot($tgToken, $tgChatId);
        $msgId = $bot->sendMessageGetId($text, $tgThreadNum > 0 ? $tgThreadNum : null);
        if ($msgId) {
            echo json_encode(['ok' => true, 'message_id' => (int)$msgId], JSON_UNESCAPED_UNICODE);
        } else {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Telegram send failed'], JSON_UNESCAPED_UNICODE);
        }
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if (($_GET['ajax'] ?? '') === 'telegram_status_ensure') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $tgToken = trim((string)($_ENV['TELEGRAM_BOT_TOKEN'] ?? $_ENV['TG_BOT_TOKEN'] ?? ''));
        $tgChatId = trim((string)($_ENV['TELEGRAM_CHAT_ID'] ?? $_ENV['TG_CHAT_ID'] ?? ''));
        $tgThreadId = trim((string)($_ENV['TELEGRAM_THREAD_ID'] ?? $_ENV['TG_THREAD_ID'] ?? ''));
        $tgThreadNum = $tgThreadId !== '' ? (int)$tgThreadId : 0;
        if ($tgToken === '' || $tgChatId === '') {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Missing TELEGRAM_* in .env'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $today = date('Y-m-d');
        $ks = $db->t('kitchen_stats');
        $metaTable = $db->t('system_meta');
        $metaRepo = new \App\Classes\MetaRepository($db);
        $getMeta = function (string $key, string $default = '') use ($metaRepo): string {
            $vals = $metaRepo->getMany([$key]);
            return array_key_exists($key, $vals) ? (string)$vals[$key] : $default;
        };
        $setMeta = function (string $key, string $value) use ($db, $metaTable): void {
            $db->query(
                "INSERT INTO {$metaTable} (meta_key, meta_value) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value), updated_at = CURRENT_TIMESTAMP",
                [$key, $value]
            );
        };
        $settingKeys = [
            'alert_timing_low_load' => 20,
            'alert_load_threshold' => 25,
            'alert_timing_high_load' => 30,
            'exclude_partners_from_load' => 0
        ];
        $settingValues = $metaRepo->getMany(array_keys($settingKeys));
        $settings = [];
        foreach ($settingKeys as $key => $default) {
            $val = array_key_exists($key, $settingValues) ? $settingValues[$key] : $default;
            $settings[$key] = is_numeric($default) ? (int)$val : (string)$val;
        }
        $partnersCount = 0;
        $otherCount = 0;
        $openChecksDisplay = '0';
        if (!empty($settings['exclude_partners_from_load'])) {
            $partnersCountRow = $db->query("SELECT COUNT(DISTINCT transaction_id) as c FROM {$ks} WHERE status = 1 AND transaction_date = ? AND table_number = 'Partners'", [$today])->fetch();
            $partnersCount = (int)($partnersCountRow['c'] ?? 0);
            $otherCountRow = $db->query("SELECT COUNT(DISTINCT transaction_id) as c FROM {$ks} WHERE status = 1 AND transaction_date = ? AND table_number != 'Partners'", [$today])->fetch();
            $otherCount = (int)($otherCountRow['c'] ?? 0);
            $loadCount = $otherCount;
            $openChecksDisplay = "{$otherCount}+{$partnersCount}";
        } else {
            $openCountRow = $db->query("SELECT COUNT(DISTINCT transaction_id) as c FROM {$ks} WHERE status = 1 AND transaction_date = ?", [$today])->fetch();
            $openCount = (int)($openCountRow['c'] ?? 0);
            $loadCount = $openCount;
            $openChecksDisplay = (string)$openCount;
        }
        $waitLimit = ($loadCount < (int)$settings['alert_load_threshold'])
            ? (int)$settings['alert_timing_low_load']
            : (int)$settings['alert_timing_high_load'];
        $cutoffTime = date('Y-m-d H:i:s', strtotime("-{$waitLimit} minutes"));
        $excludeSql = " AND COALESCE(was_deleted, 0) = 0 AND COALESCE(exclude_from_dashboard, 0) = 0
                        AND NOT (COALESCE(dish_category_id, 0) = 47 OR COALESCE(dish_sub_category_id, 0) = 47) ";
        $queueBar = 0; $queueKitchen = 0;
        $overdueBar = 0; $overdueKitchen = 0;
        $qRows = $db->query(
            "SELECT COALESCE(station, 1) as st, COUNT(*) as cnt
             FROM {$ks}
             WHERE ready_pressed_at IS NULL
               AND ticket_sent_at IS NOT NULL
               AND transaction_date = ?
               AND status = 1 {$excludeSql}
             GROUP BY COALESCE(station, 1)",
            [$today]
        )->fetchAll();
        foreach ($qRows as $r) {
            $st = (int)($r['st'] ?? 1);
            $cnt = (int)($r['cnt'] ?? 0);
            if ($st === 2) $queueBar += $cnt; else $queueKitchen += $cnt;
        }
        $oRows = $db->query(
            "SELECT COALESCE(station, 1) as st, COUNT(*) as cnt
             FROM {$ks}
             WHERE ready_pressed_at IS NULL
               AND ticket_sent_at IS NOT NULL
               AND transaction_date = ?
               AND status = 1 {$excludeSql}
               AND ticket_sent_at < ?
             GROUP BY COALESCE(station, 1)",
            [$today, $cutoffTime]
        )->fetchAll();
        foreach ($oRows as $r) {
            $st = (int)($r['st'] ?? 1);
            $cnt = (int)($r['cnt'] ?? 0);
            if ($st === 2) $overdueBar += $cnt; else $overdueKitchen += $cnt;
        }
        $lastPosterSync = $getMeta('poster_last_sync_at', '');
        $statusText = 'Открыто чеков: ' . htmlspecialchars($openChecksDisplay) . "\n";
        $statusText .= 'Лимит времени: ' . (int)$waitLimit . " мин\n";
        $statusText .= 'В очереди: 🍸' . $queueBar . ' / 🍔' . $queueKitchen . "\n";
        $statusText .= 'Долгих блюд: 🍸' . $overdueBar . ' / 🍔' . $overdueKitchen . "\n";
        $statusText .= 'Время обновления: ' . ($lastPosterSync !== '' ? $lastPosterSync : date('Y-m-d H:i:s'));
        $statusHash = sha1($statusText);
        $prevStatusId = (int)$getMeta('telegram_status_msg_id', '0');
        $prevStatusHash = (string)$getMeta('telegram_status_msg_hash', '');
        $bot = new \App\Classes\TelegramBot($tgToken, $tgChatId);
        $messageId = null;
        if ($prevStatusId > 0) {
            $ok = $bot->editMessageText($prevStatusId, $statusText, null);
            if ($ok) {
                $setMeta('telegram_status_msg_hash', $statusHash);
                $messageId = $prevStatusId;
            } else {
                $newId = $bot->sendMessageGetId($statusText, $tgThreadNum > 0 ? $tgThreadNum : null);
                if ($newId) {
                    $setMeta('telegram_status_msg_id', (string)$newId);
                    $setMeta('telegram_status_msg_hash', $statusHash);
                    $messageId = (int)$newId;
                } else {
                    $setMeta('telegram_status_msg_id', '0');
                    $setMeta('telegram_status_msg_hash', '');
                }
            }
        } else {
            $newId = $bot->sendMessageGetId($statusText, $tgThreadNum > 0 ? $tgThreadNum : null);
            if ($newId) {
                $setMeta('telegram_status_msg_id', (string)$newId);
                $setMeta('telegram_status_msg_hash', $statusHash);
                $messageId = (int)$newId;
            } else {
                $setMeta('telegram_status_msg_id', '0');
                $setMeta('telegram_status_msg_hash', '');
            }
        }
        echo json_encode(['ok' => $messageId !== null, 'message_id' => $messageId, 'text' => $statusText], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

$menuView = $_GET['view'] ?? 'list';
if (!in_array($menuView, ['list', 'edit', 'categories'], true)) {
    $menuView = 'list';
}
if ($tab === 'categories') {
    $menuView = 'categories';
}
$menuItems = [];
$menuTotal = 0;
$menuPerPage = 50;
$menuPage = max(1, (int)($_GET['page'] ?? 1));
$menuEdit = null;
$menuWorkshops = [];
$menuCategories = [];
$menuSyncMeta = ['last_sync_at' => null, 'last_sync_result' => null, 'last_sync_error' => null];
$menuSyncAtIso = '';

if ($tab === 'menu' || $tab === 'categories') {
    $posterMenuItemsTable = $db->t('poster_menu_items');
    $menuWorkshopsTable = $db->t('menu_workshops');
    $menuWorkshopsTrTable = $db->t('menu_workshop_tr');
    $menuCategoriesTable = $db->t('menu_categories');
    $menuCategoriesTrTable = $db->t('menu_category_tr');
    $menuItemsTable = $db->t('menu_items');
    $menuItemsTrTable = $db->t('menu_item_tr');

    if (($_GET['export'] ?? '') === 'categories_csv') {
        ignore_user_abort(true);
        @set_time_limit(300);
        header('Content-Type: text/csv; charset=utf-8');
        $file = 'menu_categories_' . (new DateTime('now', new DateTimeZone('Asia/Ho_Chi_Minh')))->format('Ymd_His') . '.csv';
        header('Content-Disposition: attachment; filename="' . $file . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $out = fopen('php://output', 'w');
        if ($out === false) {
            http_response_code(500);
            echo 'Cannot open output';
            exit;
        }
        fwrite($out, "\xEF\xBB\xBF");

        fputcsv($out, [
            'Тип',
            'Poster ID',
            'Parent Poster ID',
            'Raw',
            'RU',
            'EN',
            'VN',
            'KO',
            'Отображать',
            'Sort',
        ], ';');

        $wRows = $db->query(
            "SELECT
                w.poster_id,
                w.name_raw,
                w.sort_order,
                w.show_on_site,
                COALESCE(wtr_ru.name, '') name_ru,
                COALESCE(wtr_en.name, '') name_en,
                COALESCE(wtr_vn.name, '') name_vn,
                COALESCE(wtr_ko.name, '') name_ko
             FROM {$menuWorkshopsTable} w
             LEFT JOIN {$menuWorkshopsTrTable} wtr_ru ON wtr_ru.workshop_id = w.id AND wtr_ru.lang = 'ru'
             LEFT JOIN {$menuWorkshopsTrTable} wtr_en ON wtr_en.workshop_id = w.id AND wtr_en.lang = 'en'
             LEFT JOIN {$menuWorkshopsTrTable} wtr_vn ON wtr_vn.workshop_id = w.id AND wtr_vn.lang = 'vn'
             LEFT JOIN {$menuWorkshopsTrTable} wtr_ko ON wtr_ko.workshop_id = w.id AND wtr_ko.lang = 'ko'
             ORDER BY w.sort_order ASC, w.poster_id ASC"
        )->fetchAll();

        foreach ($wRows as $r) {
            fputcsv($out, [
                'workshop',
                (string)($r['poster_id'] ?? ''),
                '',
                (string)($r['name_raw'] ?? ''),
                (string)($r['name_ru'] ?? ''),
                (string)($r['name_en'] ?? ''),
                (string)($r['name_vn'] ?? ''),
                (string)($r['name_ko'] ?? ''),
                !empty($r['show_on_site']) ? '1' : '0',
                (string)($r['sort_order'] ?? '0'),
            ], ';');
        }

        $cRows = $db->query(
            "SELECT
                c.poster_id,
                w.poster_id workshop_poster_id,
                c.name_raw,
                c.sort_order,
                c.show_on_site,
                COALESCE(ctr_ru.name, '') name_ru,
                COALESCE(ctr_en.name, '') name_en,
                COALESCE(ctr_vn.name, '') name_vn,
                COALESCE(ctr_ko.name, '') name_ko
             FROM {$menuCategoriesTable} c
             LEFT JOIN {$menuWorkshopsTable} w ON w.id = c.workshop_id
             LEFT JOIN {$menuCategoriesTrTable} ctr_ru ON ctr_ru.category_id = c.id AND ctr_ru.lang = 'ru'
             LEFT JOIN {$menuCategoriesTrTable} ctr_en ON ctr_en.category_id = c.id AND ctr_en.lang = 'en'
             LEFT JOIN {$menuCategoriesTrTable} ctr_vn ON ctr_vn.category_id = c.id AND ctr_vn.lang = 'vn'
             LEFT JOIN {$menuCategoriesTrTable} ctr_ko ON ctr_ko.category_id = c.id AND ctr_ko.lang = 'ko'
             ORDER BY COALESCE(w.sort_order, 255) ASC, COALESCE(w.poster_id, 0) ASC, c.sort_order ASC, c.poster_id ASC"
        )->fetchAll();

        foreach ($cRows as $r) {
            fputcsv($out, [
                'category',
                (string)($r['poster_id'] ?? ''),
                (string)($r['workshop_poster_id'] ?? ''),
                (string)($r['name_raw'] ?? ''),
                (string)($r['name_ru'] ?? ''),
                (string)($r['name_en'] ?? ''),
                (string)($r['name_vn'] ?? ''),
                (string)($r['name_ko'] ?? ''),
                !empty($r['show_on_site']) ? '1' : '0',
                (string)($r['sort_order'] ?? '0'),
            ], ';');
        }

        fclose($out);
        exit;
    }

    if (($_GET['export'] ?? '') === 'csv') {
        ignore_user_abort(true);
        @set_time_limit(300);
        header('Content-Type: text/csv; charset=utf-8');
        $file = 'menu_export_' . (new DateTime('now', new DateTimeZone('Asia/Ho_Chi_Minh')))->format('Ymd_His') . '.csv';
        header('Content-Disposition: attachment; filename="' . $file . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $out = fopen('php://output', 'w');
        if ($out === false) {
            http_response_code(500);
            echo 'Cannot open output';
            exit;
        }
        fwrite($out, "\xEF\xBB\xBF");

        fputcsv($out, [
            'Poster ID',
            'Название RU',
            'Описание RU',
            'Название EN',
            'Описание EN',
            'Название VN',
            'Описание VN',
            'Название KO',
            'Описание KO',
            'Цена',
            'Категория Poster',
            'Категория адапт. RU',
            'Категория адапт. EN',
            'Категория адапт. VN',
            'Категория адапт. KO',
        ], ';');

        $rows = $db->query(
            "SELECT
                p.poster_id,
                COALESCE(ru.title, '') ru_title,
                COALESCE(ru.description, '') ru_desc,
                COALESCE(en.title, '') en_title,
                COALESCE(en.description, '') en_desc,
                COALESCE(vn.title, '') vn_title,
                COALESCE(vn.description, '') vn_desc,
                COALESCE(ko.title, '') ko_title,
                COALESCE(ko.description, '') ko_desc,
                p.price_raw,
                COALESCE(p.sub_category_name, '') poster_category,
                COALESCE(ctr_ru.name, c.name_raw, '') adapted_category_ru,
                COALESCE(ctr_en.name, c.name_raw, '') adapted_category_en,
                COALESCE(ctr_vn.name, c.name_raw, '') adapted_category_vn,
                COALESCE(ctr_ko.name, c.name_raw, '') adapted_category_ko
             FROM {$posterMenuItemsTable} p
             LEFT JOIN {$menuItemsTable} mi ON mi.poster_item_id = p.id
             LEFT JOIN {$menuItemsTrTable} ru ON ru.item_id = mi.id AND ru.lang = 'ru'
             LEFT JOIN {$menuItemsTrTable} en ON en.item_id = mi.id AND en.lang = 'en'
             LEFT JOIN {$menuItemsTrTable} vn ON vn.item_id = mi.id AND vn.lang = 'vn'
             LEFT JOIN {$menuItemsTrTable} ko ON ko.item_id = mi.id AND ko.lang = 'ko'
             LEFT JOIN {$menuCategoriesTable} c ON c.id = mi.category_id
             LEFT JOIN {$menuCategoriesTrTable} ctr_ru ON ctr_ru.category_id = c.id AND ctr_ru.lang = 'ru'
             LEFT JOIN {$menuCategoriesTrTable} ctr_en ON ctr_en.category_id = c.id AND ctr_en.lang = 'en'
             LEFT JOIN {$menuCategoriesTrTable} ctr_vn ON ctr_vn.category_id = c.id AND ctr_vn.lang = 'vn'
             LEFT JOIN {$menuCategoriesTrTable} ctr_ko ON ctr_ko.category_id = c.id AND ctr_ko.lang = 'ko'
             WHERE p.is_active = 1
             ORDER BY p.poster_id ASC"
        )->fetchAll();

        foreach ($rows as $r) {
            fputcsv($out, [
                (string)($r['poster_id'] ?? ''),
                (string)($r['ru_title'] ?? ''),
                (string)($r['ru_desc'] ?? ''),
                (string)($r['en_title'] ?? ''),
                (string)($r['en_desc'] ?? ''),
                (string)($r['vn_title'] ?? ''),
                (string)($r['vn_desc'] ?? ''),
                (string)($r['ko_title'] ?? ''),
                (string)($r['ko_desc'] ?? ''),
                (string)($r['price_raw'] ?? ''),
                (string)($r['poster_category'] ?? ''),
                (string)($r['adapted_category_ru'] ?? ''),
                (string)($r['adapted_category_en'] ?? ''),
                (string)($r['adapted_category_vn'] ?? ''),
                (string)($r['adapted_category_ko'] ?? ''),
            ], ';');
        }

        fclose($out);
        exit;
    }

    $metaKeys = ['menu_last_sync_at', 'menu_last_sync_result', 'menu_last_sync_error'];
    foreach ($metaKeys as $k) {
        $row = $db->query("SELECT meta_value FROM {$metaTable} WHERE meta_key=? LIMIT 1", [$k])->fetch();
        $menuSyncMeta[str_replace('menu_', '', $k)] = $row ? (string)$row['meta_value'] : null;
    }
    if (!empty($menuSyncMeta['last_sync_at'])) {
        $raw = (string)$menuSyncMeta['last_sync_at'];
        $tz = new DateTimeZone('Asia/Ho_Chi_Minh');
        $dt = DateTime::createFromFormat('Y-m-d H:i:s', $raw, $tz);
        if (!$dt) {
            try {
                $dt = new DateTime($raw, $tz);
            } catch (Exception $e) {
                $dt = null;
            }
        }
        if ($dt) {
            $menuSyncAtIso = $dt->format(DateTime::ATOM);
        }
    }

    if (isset($_POST['sync_menu'])) {
        try {
            ignore_user_abort(true);
            @set_time_limit(300);
            if ($posterToken === '') {
                throw new \Exception('POSTER_API_TOKEN не задан в .env');
            }
            $api = new \App\Classes\PosterAPI($posterToken);
            $sync = new \App\Classes\PosterMenuSync($api, $db);
            $result = $sync->sync(false);
            $db->query(
                "INSERT INTO {$metaTable} (meta_key, meta_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)",
                ['menu_last_sync_at', (new DateTime('now', new DateTimeZone('Asia/Ho_Chi_Minh')))->format('Y-m-d H:i:s')]
            );
            $db->query(
                "INSERT INTO {$metaTable} (meta_key, meta_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)",
                ['menu_last_sync_result', json_encode($result, JSON_UNESCAPED_UNICODE)]
            );
            $db->query(
                "INSERT INTO {$metaTable} (meta_key, meta_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)",
                ['menu_last_sync_error', '']
            );
            $message = 'Меню обновлено из Poster.';
        } catch (\Throwable $e) {
            $db->query(
                "INSERT INTO {$metaTable} (meta_key, meta_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)",
                ['menu_last_sync_error', $e->getMessage() . ' @ ' . basename((string)$e->getFile()) . ':' . (int)$e->getLine()]
            );
            $error = 'Ошибка обновления меню: ' . $e->getMessage();
        }
    }

    if (isset($_POST['autofill_menu'])) {
        try {
            $autofill = new \App\Classes\MenuAutoFill($db);
            $result = $autofill->run();
            $message = 'Привязка ID выполнена: ' . json_encode($result, JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            $error = 'Ошибка автозаполнения меню: ' . $e->getMessage();
        }
    }

    if (isset($_POST['import_categories_csv'])) {
        try {
            $raw = trim((string)($_POST['categories_csv'] ?? ''));
            if ($raw === '') {
                throw new \Exception('Вставьте CSV категорий.');
            }

            $lines = preg_split("/\r\n|\n|\r/", $raw) ?: [];
            $header = null;
            $idx = [];
            $badLines = 0;
            $workshopsUpserted = 0;
            $categoriesUpserted = 0;
            $trUpserts = 0;
            $skipped = 0;

            $norm = static function (string $s): string {
                $s = trim(mb_strtolower($s));
                $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
                return $s;
            };

            foreach ($lines as $line) {
                $line = trim((string)$line);
                if ($line === '') continue;
                $cols = str_getcsv($line, ';');
                if (!$cols || count($cols) < 4) {
                    $badLines++;
                    continue;
                }

                if ($header === null) {
                    $first = $norm((string)($cols[0] ?? ''));
                    if ($first === 'тип') {
                        $header = array_map(static fn($v) => $norm((string)$v), $cols);
                        $find = static function (array $header, string $name): ?int {
                            $i = array_search($name, $header, true);
                            return $i === false ? null : (int)$i;
                        };
                        $idx = [
                            'type' => $find($header, 'тип') ?? 0,
                            'poster_id' => $find($header, 'poster id') ?? 1,
                            'parent_poster_id' => $find($header, 'parent poster id') ?? 2,
                            'raw' => $find($header, 'raw') ?? 3,
                            'ru' => $find($header, 'ru') ?? 4,
                            'en' => $find($header, 'en') ?? 5,
                            'vn' => $find($header, 'vn') ?? 6,
                            'ko' => $find($header, 'ko') ?? 7,
                            'show' => $find($header, 'отображать') ?? 8,
                            'sort' => $find($header, 'sort') ?? 9,
                        ];
                        continue;
                    }
                }

                $type = strtolower(trim((string)($cols[$idx['type']] ?? '')));
                $posterId = (int)($cols[$idx['poster_id']] ?? 0);
                if (!in_array($type, ['workshop', 'category'], true) || $posterId <= 0) {
                    $badLines++;
                    continue;
                }

                $parentPosterId = (int)($cols[$idx['parent_poster_id']] ?? 0);
                $rawName = trim((string)($cols[$idx['raw']] ?? ''));
                $ru = trim((string)($cols[$idx['ru']] ?? ''));
                $en = trim((string)($cols[$idx['en']] ?? ''));
                $vn = trim((string)($cols[$idx['vn']] ?? ''));
                $ko = trim((string)($cols[$idx['ko']] ?? ''));
                $show = (int)trim((string)($cols[$idx['show']] ?? '1')) ? 1 : 0;
                $sort = (int)trim((string)($cols[$idx['sort']] ?? '0'));

                if ($type === 'workshop') {
                    $db->query(
                        "INSERT INTO {$menuWorkshopsTable} (poster_id, name_raw, sort_order, show_on_site)
                         VALUES (?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE
                            name_raw = VALUES(name_raw),
                            sort_order = VALUES(sort_order),
                            show_on_site = VALUES(show_on_site)",
                        [$posterId, $rawName !== '' ? $rawName : ('workshop ' . $posterId), $sort, $show]
                    );
                    $workshopsUpserted++;

                    $workshopId = (int)$db->query("SELECT id FROM {$menuWorkshopsTable} WHERE poster_id = ? LIMIT 1", [$posterId])->fetchColumn();
                    if ($workshopId > 0) {
                        foreach (['ru' => $ru, 'en' => $en, 'vn' => $vn, 'ko' => $ko] as $lang => $name) {
                            if ($name === '') continue;
                            $db->query(
                                "INSERT INTO {$menuWorkshopsTrTable} (workshop_id, lang, name)
                                 VALUES (?, ?, ?)
                                 ON DUPLICATE KEY UPDATE name = VALUES(name)",
                                [$workshopId, $lang, $name]
                            );
                            $trUpserts++;
                        }
                    }
                    continue;
                }

                $workshopId = $parentPosterId > 0
                    ? (int)$db->query("SELECT id FROM {$menuWorkshopsTable} WHERE poster_id = ? LIMIT 1", [$parentPosterId])->fetchColumn()
                    : 0;

                $categoryId = (int)$db->query("SELECT id FROM {$menuCategoriesTable} WHERE poster_id = ? LIMIT 1", [$posterId])->fetchColumn();
                if ($categoryId <= 0) {
                    if ($workshopId <= 0) {
                        $skipped++;
                        continue;
                    }
                    $db->query(
                        "INSERT INTO {$menuCategoriesTable} (poster_id, workshop_id, name_raw, sort_order, show_on_site)
                         VALUES (?, ?, ?, ?, ?)",
                        [$posterId, $workshopId, $rawName !== '' ? $rawName : ('category ' . $posterId), $sort, $show]
                    );
                    $categoriesUpserted++;
                    $categoryId = (int)$db->query("SELECT id FROM {$menuCategoriesTable} WHERE poster_id = ? LIMIT 1", [$posterId])->fetchColumn();
                } else {
                    if ($workshopId > 0) {
                        $db->query(
                            "UPDATE {$menuCategoriesTable}
                             SET workshop_id = ?, name_raw = ?, sort_order = ?, show_on_site = ?
                             WHERE id = ?
                             LIMIT 1",
                            [$workshopId, $rawName !== '' ? $rawName : ('category ' . $posterId), $sort, $show, $categoryId]
                        );
                    } else {
                        $db->query(
                            "UPDATE {$menuCategoriesTable}
                             SET name_raw = ?, sort_order = ?, show_on_site = ?
                             WHERE id = ?
                             LIMIT 1",
                            [$rawName !== '' ? $rawName : ('category ' . $posterId), $sort, $show, $categoryId]
                        );
                    }
                    $categoriesUpserted++;
                }

                if ($categoryId > 0) {
                    foreach (['ru' => $ru, 'en' => $en, 'vn' => $vn, 'ko' => $ko] as $lang => $name) {
                        if ($name === '') continue;
                        $db->query(
                            "INSERT INTO {$menuCategoriesTrTable} (category_id, lang, name)
                             VALUES (?, ?, ?)
                             ON DUPLICATE KEY UPDATE name = VALUES(name)",
                            [$categoryId, $lang, $name]
                        );
                        $trUpserts++;
                    }
                }
            }

            $message = 'Категории/цехи импортированы: ' . json_encode([
                'workshops_upserted' => $workshopsUpserted,
                'categories_upserted' => $categoriesUpserted,
                'tr_upserts' => $trUpserts,
                'skipped' => $skipped,
                'bad_lines' => $badLines,
            ], JSON_UNESCAPED_UNICODE);
            $menuView = 'categories';
        } catch (\Throwable $e) {
            $error = 'Ошибка импорта категорий: ' . $e->getMessage();
            $menuView = 'categories';
        }
    }

    if (isset($_POST['save_menu_item'])) {
        $posterId = (int)($_POST['poster_id'] ?? 0);
        $posterRow = $db->query("SELECT id, is_active FROM {$posterMenuItemsTable} WHERE poster_id=? LIMIT 1", [$posterId])->fetch();
        $posterItemId = (int)($posterRow['id'] ?? 0);
        if ($posterItemId <= 0) {
            $error = 'Позиция не найдена в Poster-таблице.';
        } else {
            $menuItemRow = $db->query("SELECT id, category_id FROM {$menuItemsTable} WHERE poster_item_id = ? LIMIT 1", [$posterItemId])->fetch();
            $menuItemId = (int)($menuItemRow['id'] ?? 0);
            $currentCategoryId = (int)($menuItemRow['category_id'] ?? 0);

            $categoryId = ($_POST['category_id'] ?? '') !== '' ? (int)$_POST['category_id'] : $currentCategoryId;
            $imageUrl = trim((string)($_POST['image_url'] ?? ''));
            $sortOrder = (int)($_POST['sort_order'] ?? 0);
            $isPublished = isset($_POST['is_published']) ? 1 : 0;

            $ruTitle = trim((string)($_POST['ru_title'] ?? ''));
            $ruDescription = trim((string)($_POST['ru_description'] ?? ''));
            $enTitle = trim((string)($_POST['en_title'] ?? ''));
            $enDescription = trim((string)($_POST['en_description'] ?? ''));
            $vnTitle = trim((string)($_POST['vn_title'] ?? ''));
            $vnDescription = trim((string)($_POST['vn_description'] ?? ''));
            $koTitle = trim((string)($_POST['ko_title'] ?? ''));
            $koDescription = trim((string)($_POST['ko_description'] ?? ''));

            if ((int)($posterRow['is_active'] ?? 1) === 0) {
                $isPublished = 0;
            }
            if ($categoryId <= 0) {
                $error = 'Выберите категорию.';
            } elseif ($isPublished === 1 && ($ruTitle === '' || $enTitle === '' || $vnTitle === '')) {
                $error = 'Для публикации заполните названия RU/EN/VN.';
            } else {
                $db->query(
                    "INSERT INTO {$menuItemsTable}
                        (poster_item_id, category_id, image_url, is_published, sort_order)
                     VALUES (?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE
                        image_url=VALUES(image_url),
                        is_published=VALUES(is_published),
                        sort_order=VALUES(sort_order),
                        category_id=VALUES(category_id)",
                    [$posterItemId, $categoryId, $imageUrl !== '' ? $imageUrl : null, $isPublished, $sortOrder]
                );

                $menuItemId = (int)$db->query("SELECT id FROM {$menuItemsTable} WHERE poster_item_id = ? LIMIT 1", [$posterItemId])->fetchColumn();
                if ($menuItemId > 0) {
                    $db->query(
                        "INSERT INTO {$menuItemsTrTable} (item_id, lang, title, description) VALUES (?, 'ru', ?, ?)
                         ON DUPLICATE KEY UPDATE title=VALUES(title), description=VALUES(description)",
                        [$menuItemId, $ruTitle !== '' ? $ruTitle : null, $ruDescription !== '' ? $ruDescription : null]
                    );
                    $db->query(
                        "INSERT INTO {$menuItemsTrTable} (item_id, lang, title, description) VALUES (?, 'en', ?, ?)
                         ON DUPLICATE KEY UPDATE title=VALUES(title), description=VALUES(description)",
                        [$menuItemId, $enTitle !== '' ? $enTitle : null, $enDescription !== '' ? $enDescription : null]
                    );
                    $db->query(
                        "INSERT INTO {$menuItemsTrTable} (item_id, lang, title, description) VALUES (?, 'vn', ?, ?)
                         ON DUPLICATE KEY UPDATE title=VALUES(title), description=VALUES(description)",
                        [$menuItemId, $vnTitle !== '' ? $vnTitle : null, $vnDescription !== '' ? $vnDescription : null]
                    );
                    $db->query(
                        "INSERT INTO {$menuItemsTrTable} (item_id, lang, title, description) VALUES (?, 'ko', ?, ?)
                         ON DUPLICATE KEY UPDATE title=VALUES(title), description=VALUES(description)",
                        [$menuItemId, $koTitle !== '' ? $koTitle : null, $koDescription !== '' ? $koDescription : null]
                    );
                }

                $message = 'Блюдо сохранено.';
                $menuView = 'edit';
                $_GET['poster_id'] = $posterId;
            }
        }
    }

    if (isset($_POST['save_categories'])) {
        $workshopSort = $_POST['workshop_sort'] ?? [];
        if (is_array($workshopSort)) {
            foreach ($workshopSort as $id => $sort) {
                $show = isset($_POST['workshop_show'][(int)$id]) ? 1 : 0;
                $db->query("UPDATE {$menuWorkshopsTable} SET sort_order=?, show_on_site=? WHERE id=?", [(int)$sort, $show, (int)$id]);
            }
        }
        $categorySort = $_POST['category_sort'] ?? [];
        if (is_array($categorySort)) {
            foreach ($categorySort as $id => $sort) {
                $show = isset($_POST['category_show'][(int)$id]) ? 1 : 0;
                $parent = $_POST['category_parent'][(int)$id] ?? null;
                $parent = $parent !== null && $parent !== '' ? (int)$parent : null;
                if ($parent !== null && $parent > 0) {
                    $db->query("UPDATE {$menuCategoriesTable} SET workshop_id=?, sort_order=?, show_on_site=? WHERE id=?", [$parent, (int)$sort, $show, (int)$id]);
                } else {
                    $db->query("UPDATE {$menuCategoriesTable} SET sort_order=?, show_on_site=? WHERE id=?", [(int)$sort, $show, (int)$id]);
                }
            }
        }
        $workshopTr = $_POST['workshop_tr'] ?? [];
        if (is_array($workshopTr)) {
            foreach ($workshopTr as $id => $langs) {
                if (!is_array($langs)) continue;
                foreach ($langs as $lang => $name) {
                    $lang = strtolower(trim((string)$lang));
                    $name = trim((string)$name);
                    if (!in_array($lang, ['ru', 'en', 'vn', 'ko'], true) || $name === '') {
                        continue;
                    }
                    $db->query(
                        "INSERT INTO {$menuWorkshopsTrTable} (workshop_id, lang, name) VALUES (?, ?, ?)
                         ON DUPLICATE KEY UPDATE name=VALUES(name)",
                        [(int)$id, $lang, $name]
                    );
                }
            }
        }
        $categoryTr = $_POST['category_tr'] ?? [];
        if (is_array($categoryTr)) {
            foreach ($categoryTr as $id => $langs) {
                if (!is_array($langs)) continue;
                foreach ($langs as $lang => $name) {
                    $lang = strtolower(trim((string)$lang));
                    $name = trim((string)$name);
                    if (!in_array($lang, ['ru', 'en', 'vn', 'ko'], true) || $name === '') {
                        continue;
                    }
                    $db->query(
                        "INSERT INTO {$menuCategoriesTrTable} (category_id, lang, name) VALUES (?, ?, ?)
                         ON DUPLICATE KEY UPDATE name=VALUES(name)",
                        [(int)$id, $lang, $name]
                    );
                }
            }
        }
        $message = 'Категории сохранены.';
        $menuView = 'categories';
    }

    $menuWorkshops = $db->query(
        "SELECT w.id, w.poster_id, w.name_raw, w.sort_order, w.show_on_site,
                tr_ru.name name_ru, tr_en.name name_en, tr_vn.name name_vn, tr_ko.name name_ko
         FROM {$menuWorkshopsTable} w
         LEFT JOIN {$menuWorkshopsTrTable} tr_ru ON tr_ru.workshop_id=w.id AND tr_ru.lang='ru'
         LEFT JOIN {$menuWorkshopsTrTable} tr_en ON tr_en.workshop_id=w.id AND tr_en.lang='en'
         LEFT JOIN {$menuWorkshopsTrTable} tr_vn ON tr_vn.workshop_id=w.id AND tr_vn.lang='vn'
         LEFT JOIN {$menuWorkshopsTrTable} tr_ko ON tr_ko.workshop_id=w.id AND tr_ko.lang='ko'
         ORDER BY w.sort_order ASC, w.name_raw ASC"
    )->fetchAll();

    $menuCategories = $db->query(
        "SELECT c.id, c.poster_id, c.workshop_id, c.name_raw, c.sort_order, c.show_on_site,
                tr_ru.name name_ru, tr_en.name name_en, tr_vn.name name_vn, tr_ko.name name_ko
         FROM {$menuCategoriesTable} c
         LEFT JOIN {$menuCategoriesTrTable} tr_ru ON tr_ru.category_id=c.id AND tr_ru.lang='ru'
         LEFT JOIN {$menuCategoriesTrTable} tr_en ON tr_en.category_id=c.id AND tr_en.lang='en'
         LEFT JOIN {$menuCategoriesTrTable} tr_vn ON tr_vn.category_id=c.id AND tr_vn.lang='vn'
         LEFT JOIN {$menuCategoriesTrTable} tr_ko ON tr_ko.category_id=c.id AND tr_ko.lang='ko'
         ORDER BY c.sort_order ASC, c.name_raw ASC"
    )->fetchAll();

    $mainItemCounts = [];
    $rows = $db->query(
        "SELECT w.id id, COUNT(*) c
         FROM {$menuItemsTable} mi
         JOIN {$posterMenuItemsTable} p ON p.id = mi.poster_item_id
         JOIN {$menuCategoriesTable} c ON c.id = mi.category_id
         JOIN {$menuWorkshopsTable} w ON w.id = c.workshop_id
         WHERE p.is_active = 1
         GROUP BY w.id"
    )->fetchAll();
    foreach ($rows as $r) {
        $mainItemCounts[(int)$r['id']] = (int)$r['c'];
    }

    $stripNumberPrefix = function (?string $s): string {
        $s = trim((string)$s);
        if ($s === '') return '';
        $s2 = preg_replace('/^\s*\d+(?:\.\d+)*\s*/u', '', $s);
        $out = trim($s2 ?? $s);
        $out2 = preg_replace('/^\s*\.\s*/u', '', $out);
        return trim($out2 ?? $out);
    };

    if ($menuView === 'edit') {
        $posterId = (int)($_GET['poster_id'] ?? 0);
        if ($posterId > 0) {
            $menuEdit = $db->query(
                "SELECT
                        p.*,
                        mi.id menu_item_id,
                        mi.category_id,
                        mi.image_url,
                        mi.is_published,
                        mi.sort_order,
                        ru.title ru_title,
                        ru.description ru_description,
                        en.title en_title,
                        en.description en_description,
                        vn.title vn_title,
                        vn.description vn_description,
                        ko.title ko_title,
                        ko.description ko_description
                 FROM {$posterMenuItemsTable} p
                 LEFT JOIN {$menuItemsTable} mi ON mi.poster_item_id = p.id
                 LEFT JOIN {$menuItemsTrTable} ru ON ru.item_id = mi.id AND ru.lang = 'ru'
                 LEFT JOIN {$menuItemsTrTable} en ON en.item_id = mi.id AND en.lang = 'en'
                 LEFT JOIN {$menuItemsTrTable} vn ON vn.item_id = mi.id AND vn.lang = 'vn'
                 LEFT JOIN {$menuItemsTrTable} ko ON ko.item_id = mi.id AND ko.lang = 'ko'
                 WHERE p.poster_id = ?
                 LIMIT 1",
                [$posterId]
            )->fetch();
        }
        if (!$menuEdit) {
            $menuView = 'list';
        }
    }

    if ($menuView === 'list') {
        $filterWorkshop = ($_GET['workshop_id'] ?? '') !== '' ? (int)$_GET['workshop_id'] : null;
        $filterCategory = ($_GET['category_id'] ?? '') !== '' ? (int)$_GET['category_id'] : null;
        $filterQ = trim((string)($_GET['q'] ?? ''));
        if (array_key_exists('status', $_GET)) {
            $filterStatus = trim((string)($_GET['status'] ?? ''));
        } else {
            $filterStatus = 'published';
        }
        $menuSort = strtolower(trim((string)($_GET['sort'] ?? '')));
        $menuDir = strtolower(trim((string)($_GET['dir'] ?? '')));
        if (!in_array($menuDir, ['asc', 'desc'], true)) {
            $menuDir = 'asc';
        }
        $where = [];
        $params = [];

        if ($filterWorkshop !== null) {
            $where[] = "c.workshop_id = ?";
            $params[] = $filterWorkshop;
        }
        if ($filterCategory !== null) {
            $where[] = "mi.category_id = ?";
            $params[] = $filterCategory;
        }
        if ($filterQ !== '') {
            $where[] = "(p.name_raw LIKE ? OR ru.title LIKE ? OR en.title LIKE ? OR vn.title LIKE ? OR ko.title LIKE ?)";
            $like = '%' . $filterQ . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        if ($filterStatus === 'published') {
            $where[] = "COALESCE(mi.is_published, 0) = 1 AND p.is_active = 1";
        } elseif ($filterStatus === 'hidden') {
            $where[] = "COALESCE(mi.is_published, 0) = 0 AND p.is_active = 1";
        } elseif ($filterStatus === 'not_found') {
            $where[] = "p.is_active = 0";
        } elseif ($filterStatus === 'unadapted') {
            $where[] = "(COALESCE(ru.title,'') = '' OR COALESCE(en.title,'') = '' OR COALESCE(vn.title,'') = '')";
        }

        $whereSql = !empty($where) ? ('WHERE ' . implode(' AND ', $where)) : '';
        $countRow = $db->query(
            "SELECT COUNT(1) c
             FROM {$posterMenuItemsTable} p
             LEFT JOIN {$menuItemsTable} mi ON mi.poster_item_id = p.id
             LEFT JOIN {$menuItemsTrTable} ru ON ru.item_id = mi.id AND ru.lang = 'ru'
             LEFT JOIN {$menuItemsTrTable} en ON en.item_id = mi.id AND en.lang = 'en'
             LEFT JOIN {$menuItemsTrTable} vn ON vn.item_id = mi.id AND vn.lang = 'vn'
             LEFT JOIN {$menuItemsTrTable} ko ON ko.item_id = mi.id AND ko.lang = 'ko'
             LEFT JOIN {$menuCategoriesTable} c ON c.id = mi.category_id
             $whereSql",
            $params
        )->fetch();
        $menuTotal = (int)($countRow['c'] ?? 0);

        $offset = ($menuPage - 1) * $menuPerPage;
        if ($menuSort === 'station') {
            $menuSort = 'poster_station';
        }
        $sortMap = [
            'poster_id' => 'p.poster_id',
            'title_ru' => "COALESCE(NULLIF(ru.title,''), p.name_raw)",
            'title_en' => "COALESCE(NULLIF(en.title,''), p.name_raw)",
            'title_vn' => "COALESCE(NULLIF(vn.title,''), p.name_raw)",
            'title_ko' => "COALESCE(NULLIF(ko.title,''), p.name_raw)",
            'price' => 'p.price_raw',
            'poster_station' => "COALESCE(NULLIF(p.station_name,''), CAST(p.station_id AS CHAR), '')",
            'poster_workshop' => "COALESCE(NULLIF(p.main_category_name,''), '')",
            'poster_category' => "COALESCE(NULLIF(p.sub_category_name,''), '')",
            'adapted_workshop_ru' => "COALESCE(wtr_ru.name, w.name_raw, p.main_category_name)",
            'adapted_workshop_en' => "COALESCE(wtr_en.name, w.name_raw, p.main_category_name)",
            'adapted_workshop_vn' => "COALESCE(wtr_vn.name, w.name_raw, p.main_category_name)",
            'adapted_workshop_ko' => "COALESCE(wtr_ko.name, w.name_raw, p.main_category_name)",
            'adapted_category_ru' => "COALESCE(ctr_ru.name, c.name_raw, p.sub_category_name)",
            'adapted_category_en' => "COALESCE(ctr_en.name, c.name_raw, p.sub_category_name)",
            'adapted_category_vn' => "COALESCE(ctr_vn.name, c.name_raw, p.sub_category_name)",
            'adapted_category_ko' => "COALESCE(ctr_ko.name, c.name_raw, p.sub_category_name)",
            'active' => 'p.is_active',
            'published' => 'COALESCE(mi.is_published, 0)',
            'sort_order' => 'COALESCE(mi.sort_order, 0)',
            'main_sort' => 'COALESCE(w.sort_order, 0)',
            'sub_sort' => 'COALESCE(c.sort_order, 0)',
            'status' => "CASE WHEN p.is_active = 0 THEN 3 WHEN COALESCE(mi.is_published, 0) = 1 THEN 1 ELSE 2 END",
        ];
        if (!array_key_exists($menuSort, $sortMap)) {
            $menuSort = 'main_sort';
            $menuDir = 'asc';
        }
        $orderBySql = $sortMap[$menuSort] . ' ' . strtoupper($menuDir) . ', p.poster_id DESC';
        $sql = "
            SELECT
                p.poster_id,
                p.name_raw,
                p.price_raw,
                p.is_active,
                p.main_category_name,
                p.sub_category_name,
                p.station_id,
                p.station_name,
                ru.title ru_title,
                COALESCE(mi.is_published, 0) is_published,
                mi.sort_order,
                en.title en_title,
                vn.title vn_title,
                ko.title ko_title,
                COALESCE(wtr_ru.name, w.name_raw, p.main_category_name) adapted_workshop_ru,
                COALESCE(wtr_en.name, w.name_raw, p.main_category_name) adapted_workshop_en,
                COALESCE(wtr_vn.name, w.name_raw, p.main_category_name) adapted_workshop_vn,
                COALESCE(wtr_ko.name, w.name_raw, p.main_category_name) adapted_workshop_ko,
                COALESCE(ctr_ru.name, c.name_raw, p.sub_category_name) adapted_category_ru,
                COALESCE(ctr_en.name, c.name_raw, p.sub_category_name) adapted_category_en,
                COALESCE(ctr_vn.name, c.name_raw, p.sub_category_name) adapted_category_vn,
                COALESCE(ctr_ko.name, c.name_raw, p.sub_category_name) adapted_category_ko
            FROM {$posterMenuItemsTable} p
            LEFT JOIN {$menuItemsTable} mi ON mi.poster_item_id = p.id
            LEFT JOIN {$menuItemsTrTable} ru ON ru.item_id = mi.id AND ru.lang = 'ru'
            LEFT JOIN {$menuItemsTrTable} en ON en.item_id = mi.id AND en.lang = 'en'
            LEFT JOIN {$menuItemsTrTable} vn ON vn.item_id = mi.id AND vn.lang = 'vn'
            LEFT JOIN {$menuItemsTrTable} ko ON ko.item_id = mi.id AND ko.lang = 'ko'
            LEFT JOIN {$menuCategoriesTable} c ON c.id = mi.category_id
            LEFT JOIN {$menuCategoriesTrTable} ctr_ru ON ctr_ru.category_id = c.id AND ctr_ru.lang='ru'
            LEFT JOIN {$menuCategoriesTrTable} ctr_en ON ctr_en.category_id = c.id AND ctr_en.lang='en'
            LEFT JOIN {$menuCategoriesTrTable} ctr_vn ON ctr_vn.category_id = c.id AND ctr_vn.lang='vn'
            LEFT JOIN {$menuCategoriesTrTable} ctr_ko ON ctr_ko.category_id = c.id AND ctr_ko.lang='ko'
            LEFT JOIN {$menuWorkshopsTable} w ON w.id = c.workshop_id
            LEFT JOIN {$menuWorkshopsTrTable} wtr_ru ON wtr_ru.workshop_id = w.id AND wtr_ru.lang='ru'
            LEFT JOIN {$menuWorkshopsTrTable} wtr_en ON wtr_en.workshop_id = w.id AND wtr_en.lang='en'
            LEFT JOIN {$menuWorkshopsTrTable} wtr_vn ON wtr_vn.workshop_id = w.id AND wtr_vn.lang='vn'
            LEFT JOIN {$menuWorkshopsTrTable} wtr_ko ON wtr_ko.workshop_id = w.id AND wtr_ko.lang='ko'
            $whereSql
            ORDER BY {$orderBySql}
            LIMIT {$menuPerPage} OFFSET {$offset}
        ";
        $menuItems = $db->query($sql, $params)->fetchAll();
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/svg+xml" href="/links/favicon.svg">
    <title>УПРАВЛЕНИЕ - Kitchen Analytics</title>
    <link rel="stylesheet" href="assets/app.css">
    <script src="/assets/app.js" defer></script>
      <?php include $_SERVER['DOCUMENT_ROOT'] . '/analytics.php'; ?>
  <link rel="stylesheet" href="/assets/css/common.css">
  <link rel="stylesheet" href="/assets/css/admin.css">
</head>
<body>
    <div class="container">
        <div class="top-nav">
            <div class="nav-left"><div class="nav-title">Управление</div></div>
            <?php require __DIR__ . '/partials/user_menu.php'; ?>
        </div>
        <?php if ($message): ?><div class="success"><?= $message ?></div><?php endif; ?>
        <?php if ($error): ?><div class="error"><?= $error ?></div><?php endif; ?>

        <div class="tab-links">
            <a href="admin.php?tab=sync" class="<?= $tab === 'sync' ? 'active' : '' ?>">Синки</a>
            <a href="admin.php?tab=access" class="<?= $tab === 'access' ? 'active' : '' ?>">Доступы</a>
            <a href="admin.php?tab=telegram" class="<?= $tab === 'telegram' ? 'active' : '' ?>">Telegram</a>
            <a href="admin.php?tab=reservations" class="<?= $tab === 'reservations' ? 'active' : '' ?>">Брони</a>
            <a href="admin.php?tab=menu" class="<?= $tab === 'menu' ? 'active' : '' ?>">Меню</a>
            <a href="admin.php?tab=categories" class="<?= $tab === 'categories' ? 'active' : '' ?>">Категории</a>
            <a href="logs.php">Логи</a>
        </div>
        <?php if ($tab === 'reservations'): ?>
            <div class="card" style="max-width: 1100px; margin: 0 auto;">
                <h2 style="margin:0 0 10px;">Брони — доступные столы</h2>
                <div class="small-muted" style="margin: 0 0 14px;">
                    Здесь выбираются номера столов, которые доступны для бронирования в публичной форме.
                </div>

                <form method="post" action="admin.php?tab=reservations&hall_id=<?= (int)$resHallId ?>&spot_id=<?= (int)$resSpotId ?>" style="display:grid; gap: 12px;">
                    <input type="hidden" name="save_reservation_tables" value="1">
                    <div style="display:flex; gap: 12px; flex-wrap: wrap; align-items: flex-end;">
                        <label style="display:grid; gap:6px;">
                            <div class="small-muted">Spot ID</div>
                            <input type="number" name="spot_id" value="<?= (int)$resSpotId ?>" min="1" style="width: 120px; padding: 8px 10px; border-radius: 10px; border: 1px solid #e5e7eb;">
                        </label>
                        <label style="display:grid; gap:6px;">
                            <div class="small-muted">Hall ID</div>
                            <input type="number" name="hall_id" value="<?= (int)$resHallId ?>" min="1" style="width: 120px; padding: 8px 10px; border-radius: 10px; border: 1px solid #e5e7eb;">
                        </label>
                        <div style="display:flex; gap: 10px; flex-wrap: wrap; align-items:center;">
                            <button type="button" class="pill ok" data-select-all style="border:0; cursor:pointer;">Отметить все</button>
                            <button type="button" class="pill warn" data-select-none style="border:0; cursor:pointer;">Снять все</button>
                        </div>
                        <div style="margin-left:auto;">
                            <button type="submit" class="pill ok" style="border:0; cursor:pointer;">Сохранить</button>
                        </div>
                    </div>

                    <?php if (empty($resTables)): ?>
                        <div class="small-muted">Нет данных по столам (или ошибка Poster API).</div>
                    <?php else: ?>
                        <div style="overflow:auto; border:1px solid #e5e7eb; border-radius: 12px; background:#fff;">
                            <table style="width:100%; border-collapse: collapse; min-width: 860px;">
                                <thead>
                                    <tr style="background:#f8fafc;">
                                        <th style="text-align:left; padding:10px 12px; border-bottom:1px solid #e5e7eb;">Доступен</th>
                                        <th style="text-align:left; padding:10px 12px; border-bottom:1px solid #e5e7eb;">Номер на схеме</th>
                                        <th style="text-align:left; padding:10px 12px; border-bottom:1px solid #e5e7eb;">👤</th>
                                        <th style="text-align:left; padding:10px 12px; border-bottom:1px solid #e5e7eb;">Table ID</th>
                                        <th style="text-align:left; padding:10px 12px; border-bottom:1px solid #e5e7eb;">table_num</th>
                                        <th style="text-align:left; padding:10px 12px; border-bottom:1px solid #e5e7eb;">table_title</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($resTables as $r): ?>
                                        <tr>
                                            <td style="padding:10px 12px; border-bottom:1px solid #f1f5f9;">
                                                <?php if (($r['scheme_num'] ?? '') !== ''): ?>
                                                    <input type="checkbox" name="allowed_nums[]" value="<?= htmlspecialchars((string)$r['scheme_num']) ?>" <?= !empty($r['is_allowed']) ? 'checked' : '' ?>>
                                                <?php else: ?>
                                                    <span class="small-muted">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="padding:10px 12px; border-bottom:1px solid #f1f5f9; font-weight:700;">
                                                <?= htmlspecialchars((string)($r['scheme_num'] ?? '—')) ?>
                                            </td>
                                            <td style="padding:10px 12px; border-bottom:1px solid #f1f5f9;">
                                                <?php if (($r['scheme_num'] ?? '') !== ''): ?>
                                                    <input type="number" name="caps[<?= htmlspecialchars((string)$r['scheme_num']) ?>]" value="<?= (int)($r['cap'] ?? 0) ?>" min="0" max="999" style="width: 56px; padding: 6px 8px; border-radius: 10px; border: 1px solid #e5e7eb;">
                                                <?php else: ?>
                                                    <span class="small-muted">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="padding:10px 12px; border-bottom:1px solid #f1f5f9;">
                                                <?= htmlspecialchars((string)($r['table_id'] ?? '—')) ?>
                                            </td>
                                            <td style="padding:10px 12px; border-bottom:1px solid #f1f5f9;">
                                                <?= htmlspecialchars((string)($r['table_num'] ?? '—')) ?>
                                            </td>
                                            <td style="padding:10px 12px; border-bottom:1px solid #f1f5f9;">
                                                <?= htmlspecialchars((string)($r['table_title'] ?? '—')) ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </form>
            </div>

            <script src="/assets/js/admin.js"></script>
        <?php elseif ($tab === 'sync'): ?>
            <?php
                $syncDefs = [
                    [
                        'label' => 'Kitchen sync',
                        'at_key' => 'kitchen_last_sync_at',
                        'result_key' => 'kitchen_last_sync_result',
                        'error_key' => 'kitchen_last_sync_error',
                        'desc' => 'Синхронизирует чеки/позиции кухни из Poster в kitchen_stats. Используется для Kitchen Online, Rawdata и Dashboard.',
                    ],
                    [
                        'label' => 'Telegram alerts',
                        'at_key' => 'telegram_last_run_at',
                        'result_key' => 'telegram_last_run_result',
                        'error_key' => 'telegram_last_run_error',
                        'desc' => 'Отправляет/обновляет уведомления в Telegram по долгим блюдам (по блюду, не по чеку). Удаляет уведомления при готовности/закрытии/игноре.',
                    ],
                    [
                        'label' => 'Kitchen resync job',
                        'at_key' => 'kitchen_resync_job_last_update_at',
                        'result_key' => 'kitchen_resync_job_progress',
                        'error_key' => 'kitchen_resync_job_error',
                        'desc' => 'Фоновый пересинк кухни за диапазон дат. Нужен для пересчёта статистики за периоды без 504 таймаутов.',
                    ],
                    [
                        'label' => 'Menu sync',
                        'at_key' => 'menu_last_sync_at',
                        'result_key' => 'menu_last_sync_result',
                        'error_key' => 'menu_last_sync_error',
                        'desc' => 'Синхронизирует меню из Poster в poster_menu_items и справочники (цехи/категории/позиции) для сайта и админки.',
                    ],
                ];
                $needKeys = [];
                foreach ($syncDefs as $d) {
                    $needKeys[$d['at_key']] = true;
                    $needKeys[$d['result_key']] = true;
                    $needKeys[$d['error_key']] = true;
                }
                $meta = [];
                foreach (array_keys($needKeys) as $k) {
                    $row = $db->query("SELECT meta_value FROM {$metaTable} WHERE meta_key = ? LIMIT 1", [$k])->fetch();
                    $meta[$k] = $row ? (string)$row['meta_value'] : '';
                }
            ?>
            <div class="card">
                <h3>Статус синков</h3>
                <table class="menu-table">
                    <tr>
                        <th>Синк</th>
                        <th>Последний запуск</th>
                        <th>Результат</th>
                        <th>Ошибка</th>
                        <th>Описание</th>
                    </tr>
                    <?php foreach ($syncDefs as $d): ?>
                        <tr>
                            <td style="font-weight:700;"><?= htmlspecialchars($d['label']) ?></td>
                            <td><?= htmlspecialchars($meta[$d['at_key']] !== '' ? $meta[$d['at_key']] : '—') ?></td>
                            <td><?= htmlspecialchars($meta[$d['result_key']] !== '' ? $meta[$d['result_key']] : '—') ?></td>
                            <td><?= htmlspecialchars($meta[$d['error_key']] !== '' ? $meta[$d['error_key']] : '—') ?></td>
                            <td class="muted"><?= htmlspecialchars($d['desc']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            </div>

            <div class="card">
                <h3>Запуск руками</h3>
                <div class="muted">Запускает серверные скрипты. Рекомендуется использовать редко и осознанно.</div>
                <?php
                    $disabled = strtolower((string)ini_get('disable_functions'));
                    $canExec = function_exists('exec') && ($disabled === '' || strpos($disabled, 'exec') === false);
                    $phpBin = (defined('PHP_BINARY') && is_string(PHP_BINARY) && PHP_BINARY !== '') ? PHP_BINARY : 'php';
                ?>
                <form method="post" style="margin-top: 12px; display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end;">
                    <input type="hidden" name="run_script" value="1">
                    <div class="form-group" style="flex:1; min-width:220px; margin-bottom:0;">
                        <label for="script_name">Скрипт</label>
                        <select name="script_name" id="script_name">
                            <option value="kitchen_cron" data-desc="cron.php — синк кухни за сегодня (kitchen_stats), обновляет Kitchen Online / Dashboard / Rawdata.">Кухня: синк за сегодня</option>
                            <option value="kitchen_resync_range" data-desc="scripts/kitchen/resync_range.php — пересинк кухни за диапазон дат (фоновой запуск, чтобы не ловить 504).">Кухня: пересинк диапазон</option>
                            <option value="kitchen_prob_close" data-desc="scripts/kitchen/backfill_prob_close_at.php — пересчёт логического закрытия (ProbCloseTime).">Пересчёт ВрЛогЗакр</option>
                            <option value="menu_cron" data-desc="menu_cron.php — синк меню из Poster (poster_menu_items + справочники).">Меню: синк из Poster</option>
                            <option value="tg_alerts" data-desc="telegram_alerts.php — отправка/обновление Telegram уведомлений.">Telegram: уведомления</option>
                        </select>
                    </div>
                    <div class="form-group" style="margin-bottom:0;">
                        <label for="date_from">От</label>
                        <input type="date" name="date_from" id="date_from" value="<?= htmlspecialchars(date('Y-m-d')) ?>">
                    </div>
                    <div class="form-group" style="margin-bottom:0;">
                        <label for="date_to">До</label>
                        <input type="date" name="date_to" id="date_to" value="<?= htmlspecialchars(date('Y-m-d')) ?>">
                    </div>
                    <button type="submit" <?= $canExec ? '' : 'disabled' ?>>Запустить</button>
                </form>
                <?php if (!$canExec): ?>
                    <div class="error" style="margin-top:12px;">Запуск недоступен: на сервере отключена функция exec().</div>
                <?php endif; ?>
                <div id="script_desc" class="muted" style="margin-top:10px;"></div>
                <?php
                    if (isset($_POST['run_script'])) {
                        $script = (string)($_POST['script_name'] ?? '');
                        $dateFrom = (string)($_POST['date_from'] ?? date('Y-m-d'));
                        $dateTo = (string)($_POST['date_to'] ?? date('Y-m-d'));
                        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = date('Y-m-d');
                        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) $dateTo = date('Y-m-d');

                        $cmd = null;
                        $isBackground = false;
                        if ($script === 'kitchen_cron') {
                            $cmd = $phpBin . ' ' . escapeshellarg(__DIR__ . '/cron.php');
                        } elseif ($script === 'kitchen_resync_range') {
                            $jobId = date('Ymd_His');
                            $cmd = $phpBin . ' ' . escapeshellarg(__DIR__ . '/scripts/kitchen/resync_range.php') . ' ' . escapeshellarg($dateFrom) . ' ' . escapeshellarg($dateTo) . ' ' . escapeshellarg($jobId);
                            $isBackground = true;
                        } elseif ($script === 'kitchen_prob_close') {
                            $cmd = $phpBin . ' ' . escapeshellarg(__DIR__ . '/scripts/kitchen/backfill_prob_close_at.php');
                        } elseif ($script === 'menu_cron') {
                            $cmd = $phpBin . ' ' . escapeshellarg(__DIR__ . '/menu_cron.php');
                        } elseif ($script === 'tg_alerts') {
                            $cmd = $phpBin . ' ' . escapeshellarg(__DIR__ . '/telegram_alerts.php');
                        }

                        if (!$canExec) {
                            echo '<div class="error" style="margin-top:12px;">exec() отключён — запустить нельзя.</div>';
                        } elseif ($cmd) {
                            $out = [];
                            $code = 0;
                            if ($isBackground) {
                                $logFile = __DIR__ . '/resync_range.log';
                                exec($cmd . ' >> ' . escapeshellarg($logFile) . ' 2>&1 & echo $!', $out, $code);
                            } else {
                                exec($cmd . ' 2>&1', $out, $code);
                            }
                            if (count($out) > 200) $out = array_slice($out, -200);
                            echo '<pre style="margin-top:12px; white-space:pre-wrap; word-break:break-word; background:var(--card); color:var(--text); padding:12px; border-radius:12px; overflow:auto; max-height:360px;">' . htmlspecialchars("exit={$code}\n" . implode("\n", $out)) . '</pre>';
                        } else {
                            echo '<div class="error">Неизвестный скрипт</div>';
                        }
                    }
                ?>
                <script src="/assets/js/admin_2.js"></script>
            </div>

        <?php elseif ($tab === 'telegram'): ?>
        <?php
            $telegramMeta = [];
            foreach (['telegram_last_run_at', 'telegram_last_run_result', 'telegram_last_run_error'] as $k) {
                $row = $db->query("SELECT meta_value FROM {$metaTable} WHERE meta_key = ? LIMIT 1", [$k])->fetch();
                $telegramMeta[$k] = $row ? (string)$row['meta_value'] : '';
            }
        ?>
        <div class="card">
            <div style="display:flex; align-items:flex-start; justify-content: space-between; gap: 16px; flex-wrap: wrap;">
                <div>
                    <h3 style="margin:0 0 6px;">Telegram</h3>
                    <div class="muted">Алерты по просроченным блюдам из Kitchen Online. Сообщение одно на чек.</div>
                </div>
                <div class="muted" style="text-align:right;">
                    <div>Последний запуск: <b><?= htmlspecialchars($telegramMeta['telegram_last_run_at'] !== '' ? $telegramMeta['telegram_last_run_at'] : '—') ?></b></div>
                    <?php if (!empty($telegramMeta['telegram_last_run_error'])): ?>
                        <div style="color:#b91c1c; font-weight:800; margin-top:4px;">Ошибка: <?= htmlspecialchars((string)$telegramMeta['telegram_last_run_error']) ?></div>
                    <?php else: ?>
                        <div style="margin-top:4px;"><?= htmlspecialchars($telegramMeta['telegram_last_run_result'] ?? '') ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <form method="POST" style="margin-top: 14px;">
                <div class="settings-grid" style="grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); max-width: 820px;">
                    <div class="form-group">
                        <label>Тайминг (низк. нагр.), мин</label>
                        <input type="number" name="alert_timing_low_load" value="<?= $settings['alert_timing_low_load'] ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Порог чеков</label>
                        <input type="number" name="alert_load_threshold" value="<?= $settings['alert_load_threshold'] ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Тайминг (выс. нагр.), мин</label>
                        <input type="number" name="alert_timing_high_load" value="<?= $settings['alert_timing_high_load'] ?>" required>
                    </div>
                </div>
                <div style="margin-top: 10px; display:flex; gap: 12px; align-items:center; flex-wrap: wrap;">
                    <label style="display:flex; align-items:center; gap: 8px; font-size: 14px; font-weight: 800;">
                        <input type="hidden" name="exclude_partners_from_load" value="0">
                        <input type="checkbox" name="exclude_partners_from_load" value="1" <?= !empty($settings['exclude_partners_from_load']) ? 'checked' : '' ?>>
                        Не учитывать стол Partners в нагрузке
                    </label>
                    <button type="submit" name="save_settings">Сохранить</button>
                </div>
            </form>

            <details style="margin-top: 14px;">
                <summary style="cursor:pointer; font-weight: 900;">Логика работы</summary>
                <div class="muted" style="margin-top: 10px; line-height: 1.55;">
                    <div><b>Кандидаты</b>: позиции из Kitchen Online, которые обводятся красным (ticket_sent_at старше лимита, чек открыт, позиция не удалена и не в “Игнор” на табло).</div>
                    <div><b>Один алерт = один чек</b>: если в чеке несколько просроченных блюд — всё в одном сообщении.</div>
                    <div><b>Обновление</b>: при изменениях состав/время — бот редактирует сообщение; если просроченных блюд в чеке не осталось — сообщение удаляется.</div>
                    <div><b>Принято</b>: кнопка “Принято” ставит игнор по конкретному блюду до готовности (бессрочно).</div>
                    <div><b>Доступ</b>: нажимать “Принято” можно только при наличии права “Игнор + ✅ Принято (Telegram)” и заполненном Telegram username.</div>
                </div>
            </details>

            <details style="margin-top: 14px;">
                <summary style="cursor:pointer; font-weight: 900;">Формат сообщения</summary>
                <div class="muted" style="margin-top: 10px; white-space: pre-wrap;">
Чек:(номерчека)|Стол(номер стола)
Имя официанта
Название блюда — сколько уже готовится
                </div>
            </details>
        </div>

        <?php elseif ($tab === 'access'): ?>
        <div class="card">
            <h3>Управление доступом</h3>
            <form method="POST" class="form-group">
                <label>Добавить новый email</label>
                <div style="display:flex; gap:10px;">
                    <input type="email" name="email" placeholder="example@gmail.com" required>
                    <button type="submit" name="add_email">Добавить</button>
                </div>
            </form>

            <?php if (empty($users)): ?>
                <div class="error" style="margin: 14px 0;">
                    Список пользователей пуст. Нажмите «Добавить себя», чтобы восстановить доступы.
                    <div class="muted" style="margin-top:6px;">Текущий пользователь: <?= htmlspecialchars((string)($_SESSION['user_email'] ?? '—')) ?></div>
                    <form method="POST" style="margin-top: 10px;">
                        <button type="submit" name="add_self" value="1">Добавить себя</button>
                    </form>
                </div>
            <?php endif; ?>

            <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Имя</th>
                        <th>Email</th>
                        <th>Telegram</th>
                        <th>Дата добавления</th>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): ?>
                    <tr>
                        <td><?= htmlspecialchars((string)($user['name'] ?? '')) ?></td>
                        <td><?= htmlspecialchars($user['email']) ?></td>
                        <td><?= htmlspecialchars((string)($user['telegram_username'] ?? '')) ?></td>
                        <td><?php
                            $ca = (string)($user['created_at'] ?? '');
                            if ($ca !== '' && $ca !== '0000-00-00 00:00:00') {
                                $ts = strtotime($ca);
                                echo $ts !== false ? date('d.m.Y H:i', $ts) : '—';
                            } else {
                                echo '—';
                            }
                        ?></td>
                        <td>
                            <?php
                                $rawPerms = (string)($user['permissions_json'] ?? '');
                                $perms = $rawPerms !== '' ? json_decode($rawPerms, true) : null;
                                if (!is_array($perms)) $perms = null;
                            ?>
                            <button type="button" class="perm-gear"
                                data-email="<?= htmlspecialchars($user['email'], ENT_QUOTES) ?>"
                                data-perms="<?= htmlspecialchars(json_encode($perms, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>"
                                data-tg="<?= htmlspecialchars((string)($user['telegram_username'] ?? ''), ENT_QUOTES) ?>"
                            >Редактировать</button>
                            <?php if ($user['email'] !== $_SESSION['user_email']): ?>
                                <a href="?delete=<?= urlencode($user['email']) ?>" class="delete-btn" onclick="return confirm('Удалить доступ для <?= $user['email'] ?>?')">Удалить</a>
                            <?php else: ?>
                                <span style="color:#999; font-size:12px;">(Это вы)</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <div class="perm-modal" id="permModal" style="display:none;">
                <div class="perm-modal-backdrop"></div>
                <div class="perm-modal-card">
                    <div class="perm-modal-title">Права доступа</div>
                    <form method="POST" id="permForm">
                        <input type="hidden" name="save_user_permissions" value="1">
                        <input type="hidden" name="perm_email" id="permEmail" value="">
                        <div class="form-group" style="margin-bottom: 12px;">
                            <label style="font-size:12px; font-weight:800; text-transform:uppercase; color:var(--muted);">Telegram username</label>
                            <input type="text" name="perm_tg_username" id="permTgUsername" placeholder="например: zapleosoft">
                            <div class="muted" style="margin-top:6px;">Нужен для кнопки «ПРИНЯТО» в Telegram. Пиши без @.</div>
                        </div>
                        <div class="perm-list">
                            <?php foreach ($permissionKeys as $k => $label): ?>
                                <?php if ($k === 'telegram_ack') continue; ?>
                                <label class="perm-row">
                                    <input type="checkbox" name="perm_<?= htmlspecialchars($k) ?>" id="perm_<?= htmlspecialchars($k) ?>" value="1">
                                    <?= htmlspecialchars($label) ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <div class="perm-actions">
                            <button type="button" class="perm-cancel" id="permCancel">Отмена</button>
                            <button type="submit">Сохранить</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <?php else: ?>
        <div class="card">
            <div class="menu-actions">
                <div class="left">
                    <h3 style="margin:0;">Меню</h3>
                    <form method="POST" style="margin:0;">
                        <button type="submit" name="sync_menu" title="Синк из Poster: только обновляет слепок poster_menu_items и справочники по poster_id. Не трогает переводы и ручные привязки/публикацию.">Обновить меню из Poster</button>
                    </form>
                    <form method="POST" style="margin:0;">
                        <button type="submit" name="autofill_menu" title="Разовая привязка по ID: связывает menu_items.category_id и menu_categories.workshop_id из данных Poster там, где сейчас пусто. Не трогает переводы и ручные значения.">Привязать ID (разово)</button>
                    </form>
                    <a href="admin.php?tab=menu&export=csv" style="text-decoration:none; font-weight:600; color:var(--accent);" title="Выгрузка CSV со всеми активными позициями и текущими переводами/категориями.">CSV меню</a>
                    <a href="admin.php?tab=menu&export=categories_csv" style="text-decoration:none; font-weight:600; color:var(--accent);" title="Выгрузка CSV справочников цехов и категорий с переводами.">CSV категорий</a>
                    <?php if (!empty($menuSyncMeta['last_sync_at'])): ?>
                        <span class="muted">Последняя синхронизация: <span class="js-local-dt" data-iso="<?= htmlspecialchars($menuSyncAtIso) ?>"><?= htmlspecialchars($menuSyncMeta['last_sync_at']) ?></span></span>
                    <?php endif; ?>
                </div>
            </div>
            <?php if (!empty($menuSyncMeta['last_sync_error'])): ?>
                <div style="margin-top:12px;" class="error"><?= htmlspecialchars($menuSyncMeta['last_sync_error']) ?></div>
            <?php endif; ?>

            <?php if ($menuView === 'categories'): ?>
                <div style="margin-top: 18px;">
                    <h4 style="margin: 0 0 10px;">Импорт цехов/категорий (CSV)</h4>
                    <div class="muted" style="margin-bottom: 10px;">Формат: Тип;Poster ID;Parent Poster ID;Raw;RU;EN;VN;KO;Отображать;Sort</div>
                    <form method="POST">
                        <textarea name="categories_csv" rows="8" style="width:100%; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace;"><?= htmlspecialchars((string)($_POST['categories_csv'] ?? '')) ?></textarea>
                        <div style="margin-top: 10px;">
                            <button type="submit" name="import_categories_csv">Импортировать категории</button>
                        </div>
                    </form>
                </div>
                <form method="POST" style="margin-top: 18px;">
                    <h4 style="margin: 0 0 10px;">Цехи</h4>
                    <div class="table-wrap">
                    <table class="menu-table">
                        <thead>
                            <tr>
                                <th>Poster ID</th>
                                <th>Raw</th>
                                <th>RU</th>
                                <th>EN</th>
                                <th>VN</th>
                                <th>KO</th>
                                <th>Блюд</th>
                                <th>Отображать</th>
                                <th>Sort</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($menuWorkshops as $c): ?>
                                <tr>
                                    <td><?= (int)$c['poster_id'] ?></td>
                                    <td><?= htmlspecialchars($c['name_raw']) ?></td>
                                    <td><input name="workshop_tr[<?= (int)$c['id'] ?>][ru]" value="<?= htmlspecialchars($c['name_ru'] ?? '') ?>" /></td>
                                    <td><input name="workshop_tr[<?= (int)$c['id'] ?>][en]" value="<?= htmlspecialchars($c['name_en'] ?? '') ?>" /></td>
                                    <td><input name="workshop_tr[<?= (int)$c['id'] ?>][vn]" value="<?= htmlspecialchars($c['name_vn'] ?? '') ?>" /></td>
                                    <td><input name="workshop_tr[<?= (int)$c['id'] ?>][ko]" value="<?= htmlspecialchars($c['name_ko'] ?? '') ?>" /></td>
                                    <?php $cnt = (int)($mainItemCounts[(int)$c['id']] ?? 0); ?>
                                    <td style="width:80px; text-align:right;"><?= $cnt ?></td>
                                    <td style="width:110px; text-align:center;">
                                        <input type="checkbox" name="workshop_show[<?= (int)$c['id'] ?>]" value="1" <?= !empty($c['show_on_site']) ? 'checked' : '' ?>>
                                    </td>
                                    <td style="width:90px;"><input type="number" name="workshop_sort[<?= (int)$c['id'] ?>]" value="<?= (int)$c['sort_order'] ?>" /></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>

                    <h4 style="margin: 18px 0 10px;">Категории</h4>
                    <div class="table-wrap">
                    <table class="menu-table">
                        <thead>
                            <tr>
                                <th>Poster ID</th>
                                <th>Raw</th>
                                <th>Цех</th>
                                <th>RU</th>
                                <th>EN</th>
                                <th>VN</th>
                                <th>KO</th>
                                <th>Отображать</th>
                                <th>Sort</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($menuCategories as $c): ?>
                                <tr>
                                    <td><?= (int)$c['poster_id'] ?></td>
                                    <td><?= htmlspecialchars($c['name_raw']) ?></td>
                                    <td style="min-width: 220px;">
                                        <select name="category_parent[<?= (int)$c['id'] ?>]">
                                            <option value="">—</option>
                                            <?php foreach ($menuWorkshops as $m): ?>
                                                <?php
                                                    $mid = (int)$m['id'];
                                                    $mname = $stripNumberPrefix((string)($m['name_ru'] ?? $m['name_raw']));
                                                ?>
                                                <option value="<?= $mid ?>" <?= (int)($c['workshop_id'] ?? 0) === $mid ? 'selected' : '' ?>><?= htmlspecialchars($mname) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td><input name="category_tr[<?= (int)$c['id'] ?>][ru]" value="<?= htmlspecialchars($c['name_ru'] ?? '') ?>" /></td>
                                    <td><input name="category_tr[<?= (int)$c['id'] ?>][en]" value="<?= htmlspecialchars($c['name_en'] ?? '') ?>" /></td>
                                    <td><input name="category_tr[<?= (int)$c['id'] ?>][vn]" value="<?= htmlspecialchars($c['name_vn'] ?? '') ?>" /></td>
                                    <td><input name="category_tr[<?= (int)$c['id'] ?>][ko]" value="<?= htmlspecialchars($c['name_ko'] ?? '') ?>" /></td>
                                    <td style="width:110px; text-align:center;">
                                        <input type="checkbox" name="category_show[<?= (int)$c['id'] ?>]" value="1" <?= !empty($c['show_on_site']) ? 'checked' : '' ?>>
                                    </td>
                                    <td style="width:90px;"><input type="number" name="category_sort[<?= (int)$c['id'] ?>]" value="<?= (int)$c['sort_order'] ?>" /></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>

                    <div style="margin-top: 14px;">
                        <button type="submit" name="save_categories">Сохранить категории</button>
                    </div>
                </form>
            <?php elseif ($menuView === 'edit' && $menuEdit): ?>
                <div style="margin-top: 14px;">
                    <a href="admin.php?tab=menu&view=list" style="text-decoration:none; font-weight:600; color:var(--accent);">← Назад к списку</a>
                </div>

                <form method="POST" style="margin-top: 14px;">
                    <input type="hidden" name="poster_id" value="<?= (int)$menuEdit['poster_id'] ?>">
                    <?php
                        $posterPrice = $menuEdit['price_raw'] ?? null;
                        $posterCost = $menuEdit['cost_raw'] ?? null;
                        $posterStation = (string)($menuEdit['station_name'] ?? '');
                        $posterCategory = (string)($menuEdit['main_category_name'] ?? '');
                        $posterSubCategory = (string)($menuEdit['sub_category_name'] ?? '');
                        $photo = '';
                        $photoOrigin = '';
                        $raw = $menuEdit['raw_json'] ?? null;
                        if (is_string($raw)) {
                            $decoded = json_decode($raw, true);
                            if (is_array($decoded)) {
                                $photo = (string)($decoded['photo'] ?? '');
                                $photoOrigin = (string)($decoded['photo_origin'] ?? '');
                            }
                        }
                        $formatMoney = function ($v): string {
                            if ($v === null || $v === '') return '—';
                            if (is_numeric($v)) {
                                $n = (float)$v;
                                if (abs($n - round($n)) < 0.00001) {
                                    return number_format((int)round($n), 0, '.', ' ');
                                }
                                return number_format($n, 2, '.', ' ');
                            }
                            return (string)$v;
                        };
                        $selectedCategoryId = (int)($menuEdit['category_id'] ?? 0);
                        $workshopNameById = [];
                        foreach ($menuWorkshops as $w) {
                            $wid = (int)($w['id'] ?? 0);
                            if ($wid <= 0) continue;
                            $workshopNameById[$wid] = $stripNumberPrefix((string)($w['name_ru'] ?? $w['name_raw']));
                        }
                        $categoriesByWorkshop = [];
                        foreach ($menuCategories as $cat) {
                            $wid = (int)($cat['workshop_id'] ?? 0);
                            if (!isset($categoriesByWorkshop[$wid])) {
                                $categoriesByWorkshop[$wid] = [];
                            }
                            $categoriesByWorkshop[$wid][] = $cat;
                        }
                    ?>

                    <div style="border:1px solid #eee; border-radius: 10px; padding: 14px;">
                        <div class="muted">Данные из Poster (read-only)</div>
                        <div style="margin-top: 8px; line-height: 1.8;">
                            <div><b>Poster ID:</b> <?= (int)$menuEdit['poster_id'] ?></div>
                            <div><b>Название Poster:</b> <?= htmlspecialchars((string)$menuEdit['name_raw']) ?></div>
                            <div><b>Станция Poster:</b> <?= htmlspecialchars($posterStation !== '' ? $posterStation : '—') ?></div>
                            <div><b>Категория Poster:</b> <?= htmlspecialchars($posterCategory !== '' ? $posterCategory : '—') ?></div>
                            <div><b>Подкатегория Poster:</b> <?= htmlspecialchars($posterSubCategory !== '' ? $posterSubCategory : '—') ?></div>
                            <div><b>Цена:</b> <?= htmlspecialchars($formatMoney($posterPrice)) ?> <span class="muted">VND</span></div>
                            <div><b>Cost:</b> <?= htmlspecialchars($formatMoney($posterCost)) ?> <span class="muted">VND</span></div>
                            <div><b>Active:</b> <?= (int)$menuEdit['is_active'] === 1 ? 'yes' : 'no' ?></div>
                            <?php if ($photo !== '' || $photoOrigin !== ''): ?>
                                <div><b>Фото Poster:</b> <?= htmlspecialchars($photo !== '' ? $photo : $photoOrigin) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div style="margin-top: 12px; border:1px solid #eee; border-radius: 10px; padding: 14px;">
                        <div class="muted">Общее (не зависит от языка)</div>
                        <div class="settings-grid" style="grid-template-columns: 2fr 2fr 1fr 1fr; margin-top: 10px;">
                            <div class="form-group">
                                <label>Категория</label>
                                <select name="category_id">
                                    <option value="">—</option>
                                    <?php foreach ($menuWorkshops as $w): ?>
                                        <?php
                                            $wid = (int)($w['id'] ?? 0);
                                            $wlabel = $stripNumberPrefix((string)($w['name_ru'] ?? $w['name_raw']));
                                            $cats = $categoriesByWorkshop[$wid] ?? [];
                                            if ($wid <= 0 || empty($cats)) continue;
                                        ?>
                                        <optgroup label="<?= htmlspecialchars($wlabel !== '' ? $wlabel : ('workshop ' . $wid)) ?>">
                                            <?php foreach ($cats as $c): ?>
                                                <?php $cid = (int)($c['id'] ?? 0); $clabel = $stripNumberPrefix((string)($c['name_ru'] ?? $c['name_raw'])); ?>
                                                <option value="<?= $cid ?>" <?= $selectedCategoryId === $cid ? 'selected' : '' ?>><?= htmlspecialchars($clabel !== '' ? $clabel : ('category ' . $cid)) ?></option>
                                            <?php endforeach; ?>
                                        </optgroup>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Картинка (Image URL)</label>
                                <input name="image_url" value="<?= htmlspecialchars((string)($menuEdit['image_url'] ?? '')) ?>" />
                            </div>
                            <div class="form-group">
                                <label>Порядок сортировки</label>
                                <input type="number" name="sort_order" value="<?= (int)($menuEdit['sort_order'] ?? 0) ?>" />
                            </div>
                            <div class="form-group">
                                <label style="display:block;">Опубликовано</label>
                                <label style="display:flex; align-items:center; gap:8px; font-size: 14px; margin-top: 10px;">
                                    <input type="checkbox" name="is_published" value="1" <?= !empty($menuEdit['is_published']) && (int)$menuEdit['is_active'] === 1 ? 'checked' : '' ?> <?= (int)$menuEdit['is_active'] === 1 ? '' : 'disabled' ?>>
                                </label>
                                <?php if ((int)$menuEdit['is_active'] === 0): ?>
                                    <div class="muted" style="margin-top:6px;">Не найдено в Poster: публикация запрещена.</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="settings-grid" style="grid-template-columns: 1fr 1fr 1fr 1fr; margin-top: 12px;">
                        <div>
                            <h4 style="margin: 0 0 10px;">RU</h4>
                            <div class="form-group">
                                <label>Название</label>
                                <input name="ru_title" value="<?= htmlspecialchars((string)($menuEdit['ru_title'] ?? '')) ?>" />
                            </div>
                            <div class="form-group">
                                <label>Описание</label>
                                <textarea name="ru_description" rows="8"><?= htmlspecialchars((string)($menuEdit['ru_description'] ?? '')) ?></textarea>
                            </div>
                        </div>

                        <div>
                            <h4 style="margin: 0 0 10px;">EN</h4>
                            <div class="form-group">
                                <label>Title</label>
                                <input name="en_title" value="<?= htmlspecialchars((string)($menuEdit['en_title'] ?? '')) ?>" />
                            </div>
                            <div class="form-group">
                                <label>Description</label>
                                <textarea name="en_description" rows="8"><?= htmlspecialchars((string)($menuEdit['en_description'] ?? '')) ?></textarea>
                            </div>
                        </div>

                        <div>
                            <h4 style="margin: 0 0 10px;">VN</h4>
                            <div class="form-group">
                                <label>Tên</label>
                                <input name="vn_title" value="<?= htmlspecialchars((string)($menuEdit['vn_title'] ?? '')) ?>" />
                            </div>
                            <div class="form-group">
                                <label>Mô tả</label>
                                <textarea name="vn_description" rows="8"><?= htmlspecialchars((string)($menuEdit['vn_description'] ?? '')) ?></textarea>
                            </div>
                        </div>

                        <div>
                            <h4 style="margin: 0 0 10px;">KO</h4>
                            <div class="form-group">
                                <label>이름</label>
                                <input name="ko_title" value="<?= htmlspecialchars((string)($menuEdit['ko_title'] ?? '')) ?>" />
                            </div>
                            <div class="form-group">
                                <label>설명</label>
                                <textarea name="ko_description" rows="8"><?= htmlspecialchars((string)($menuEdit['ko_description'] ?? '')) ?></textarea>
                            </div>
                        </div>
                    </div>
                    <div style="margin-top: 14px;">
                        <button type="submit" name="save_menu_item">Сохранить блюдо</button>
                    </div>
                </form>
            <?php else: ?>
                <?php
                    $filterWorkshop = ($_GET['workshop_id'] ?? '') !== '' ? (int)$_GET['workshop_id'] : null;
                    $filterCategory = ($_GET['category_id'] ?? '') !== '' ? (int)$_GET['category_id'] : null;
                    $filterQ = trim((string)($_GET['q'] ?? ''));
                    if (array_key_exists('status', $_GET)) {
                        $filterStatus = trim((string)($_GET['status'] ?? ''));
                    } else {
                        $filterStatus = 'published';
                    }
                    $sort = strtolower(trim((string)($_GET['sort'] ?? 'main_sort')));
                    if ($sort === 'station') {
                        $sort = 'poster_station';
                    }
                    if ($sort === 'poster_category') {
                        $sort = 'poster_station';
                    }
                    if ($sort === 'poster_subcategory') {
                        $sort = 'poster_category';
                    }
                    if (preg_match('/^adapted_category_(ru|en|vn|ko)$/', $sort, $m)) {
                        $sort = 'adapted_workshop_' . $m[1];
                    }
                    if (preg_match('/^adapted_subcategory_(ru|en|vn|ko)$/', $sort, $m)) {
                        $sort = 'adapted_category_' . $m[1];
                    }
                    $dir = strtolower(trim((string)($_GET['dir'] ?? 'asc')));
                    if (!in_array($dir, ['asc', 'desc'], true)) {
                        $dir = 'asc';
                    }
                    $colsParam = trim((string)($_GET['cols'] ?? ''));
                    if ($colsParam !== '') {
                        $parts = array_filter(array_map('trim', explode(',', $colsParam)), static fn($v) => $v !== '');
                        $mapped = [];
                        foreach ($parts as $c) {
                            if ($c === 'poster_category') $c = 'poster_station';
                            if ($c === 'poster_subcategory') $c = 'poster_category';
                            if (preg_match('/^adapted_category_(ru|en|vn|ko)$/', $c, $m)) $c = 'adapted_workshop_' . $m[1];
                            if (preg_match('/^adapted_subcategory_(ru|en|vn|ko)$/', $c, $m)) $c = 'adapted_category_' . $m[1];
                            $mapped[$c] = true;
                        }
                        $colsHidden = implode(',', array_keys($mapped));
                    } else {
                        $colsHidden = '';
                    }
                    $buildSortHref = function (string $key) use ($sort, $dir): string {
                        $qs = $_GET;
                        $qs['tab'] = 'menu';
                        $qs['view'] = 'list';
                        $qs['page'] = 1;
                        if ($sort === $key) {
                            $qs['dir'] = $dir === 'asc' ? 'desc' : 'asc';
                        } else {
                            $qs['dir'] = 'asc';
                        }
                        $qs['sort'] = $key;
                        return 'admin.php?' . http_build_query($qs);
                    };
                    $sortArrow = function (string $key) use ($sort, $dir): string {
                        if ($sort !== $key) return '';
                        return $dir === 'asc' ? '▲' : '▼';
                    };
                    $columnDefs = [
                        'poster_id' => ['label' => 'Poster ID', 'default' => true],
                        'title_ru' => ['label' => 'Название RU', 'default' => true],
                        'title_en' => ['label' => 'Название EN', 'default' => false],
                        'title_vn' => ['label' => 'Название VN', 'default' => false],
                        'title_ko' => ['label' => 'Название KO', 'default' => false],
                        'price' => ['label' => 'Цена', 'default' => true],
                        'poster_station' => ['label' => 'Станция Poster', 'default' => true],
                        'poster_workshop' => ['label' => 'Цех Poster', 'default' => false],
                        'poster_category' => ['label' => 'Категория Poster', 'default' => true],
                        'adapted_workshop_ru' => ['label' => 'Цех адапт. RU', 'default' => false],
                        'adapted_workshop_en' => ['label' => 'Цех адапт. EN', 'default' => false],
                        'adapted_workshop_vn' => ['label' => 'Цех адапт. VN', 'default' => false],
                        'adapted_workshop_ko' => ['label' => 'Цех адапт. KO', 'default' => false],
                        'adapted_category_ru' => ['label' => 'Категория адапт. RU', 'default' => false],
                        'adapted_category_en' => ['label' => 'Категория адапт. EN', 'default' => false],
                        'adapted_category_vn' => ['label' => 'Категория адапт. VN', 'default' => false],
                        'adapted_category_ko' => ['label' => 'Категория адапт. KO', 'default' => false],
                        'status' => ['label' => 'Статус', 'default' => true],
                    ];
                    $pages = max(1, (int)ceil($menuTotal / $menuPerPage));
                ?>
                <form method="GET" class="menu-filters">
                    <input type="hidden" name="tab" value="menu">
                    <input type="hidden" name="view" value="list">
                    <input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>">
                    <input type="hidden" name="dir" value="<?= htmlspecialchars($dir) ?>">
                    <input type="hidden" name="cols" value="<?= htmlspecialchars($colsHidden) ?>">
                    <div class="form-group">
                        <label>Цех</label>
                        <select name="workshop_id">
                            <option value="">Все</option>
                            <?php foreach ($menuWorkshops as $w): ?>
                                <?php $id = (int)$w['id']; $name = $stripNumberPrefix((string)($w['name_ru'] ?? $w['name_raw'])); ?>
                                <option value="<?= $id ?>" <?= $filterWorkshop === $id ? 'selected' : '' ?>><?= htmlspecialchars($name) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Категория</label>
                        <select name="category_id">
                            <option value="">Все</option>
                            <?php foreach ($menuCategories as $c): ?>
                                <?php
                                    $id = (int)$c['id'];
                                    $catName = $stripNumberPrefix((string)($c['name_ru'] ?? $c['name_raw']));
                                    $wid = (int)($c['workshop_id'] ?? 0);
                                    $wName = '';
                                    foreach ($menuWorkshops as $w) {
                                        if ((int)($w['id'] ?? 0) === $wid) {
                                            $wName = $stripNumberPrefix((string)($w['name_ru'] ?? $w['name_raw']));
                                            break;
                                        }
                                    }
                                    $label = $wName !== '' ? ($wName . ' / ' . $catName) : $catName;
                                ?>
                                <option value="<?= $id ?>" <?= $filterCategory === $id ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Поиск</label>
                        <input name="q" value="<?= htmlspecialchars($filterQ) ?>" placeholder="name_raw / RU / EN / VN / KO" />
                    </div>
                    <div class="form-group">
                        <label>Статус</label>
                        <select name="status">
                            <option value="" <?= $filterStatus === '' ? 'selected' : '' ?>>Все</option>
                            <option value="published" <?= $filterStatus === 'published' ? 'selected' : '' ?>>Опубликовано</option>
                            <option value="hidden" <?= $filterStatus === 'hidden' ? 'selected' : '' ?>>Скрыто</option>
                            <option value="not_found" <?= $filterStatus === 'not_found' ? 'selected' : '' ?>>Не найдено в Poster</option>
                            <option value="unadapted" <?= $filterStatus === 'unadapted' ? 'selected' : '' ?>>Неадаптировано</option>
                        </select>
                    </div>
                    <div style="grid-column: 1 / -1; display:flex; gap:10px; align-items:center;">
                        <button type="submit">Применить</button>
                        <a href="admin.php?tab=menu&view=list" style="text-decoration:none; color:var(--muted); font-weight:600;">Сбросить</a>
                        <span class="muted">Всего: <?= (int)$menuTotal ?></span>
                    </div>
                </form>

                <details style="margin-top: 12px;">
                    <summary style="cursor:pointer; font-weight:700; color:var(--accent);">Поля таблицы</summary>
                    <div style="margin-top: 10px; display:flex; flex-wrap:wrap; gap: 10px;">
                        <?php foreach ($columnDefs as $key => $def): ?>
                            <label style="display:flex; align-items:center; gap: 8px; font-size: 13px; border:1px solid #eee; padding: 8px 10px; border-radius: 999px; background:#fafafa;">
                                <input type="checkbox" class="col-toggle" data-col="<?= htmlspecialchars($key) ?>" data-default="<?= !empty($def['default']) ? '1' : '0' ?>">
                                <?= htmlspecialchars($def['label']) ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <div class="muted" style="margin-top: 8px;">Выбор сохраняется в браузере и в URL (cols=...).</div>
                </details>

                <div class="table-wrap">
                <table class="menu-table">
                    <thead>
                        <tr>
                            <th data-col="poster_id"><a class="sort-link" href="<?= htmlspecialchars($buildSortHref('poster_id')) ?>">Poster ID <span class="sort-arrow"><?= $sortArrow('poster_id') ?></span></a></th>
                            <th data-col="title_ru"><a class="sort-link" href="<?= htmlspecialchars($buildSortHref('title_ru')) ?>">Название RU <span class="sort-arrow"><?= $sortArrow('title_ru') ?></span></a></th>
                            <th data-col="title_en"><a class="sort-link" href="<?= htmlspecialchars($buildSortHref('title_en')) ?>">EN <span class="sort-arrow"><?= $sortArrow('title_en') ?></span></a></th>
                            <th data-col="title_vn"><a class="sort-link" href="<?= htmlspecialchars($buildSortHref('title_vn')) ?>">VN <span class="sort-arrow"><?= $sortArrow('title_vn') ?></span></a></th>
                            <th data-col="title_ko"><a class="sort-link" href="<?= htmlspecialchars($buildSortHref('title_ko')) ?>">KO <span class="sort-arrow"><?= $sortArrow('title_ko') ?></span></a></th>
                            <th data-col="price"><a class="sort-link" href="<?= htmlspecialchars($buildSortHref('price')) ?>">Цена <span class="sort-arrow"><?= $sortArrow('price') ?></span></a></th>
                            <th data-col="poster_station"><a class="sort-link" href="<?= htmlspecialchars($buildSortHref('poster_station')) ?>">Станция Poster <span class="sort-arrow"><?= $sortArrow('poster_station') ?></span></a></th>
                            <th data-col="poster_workshop"><a class="sort-link" href="<?= htmlspecialchars($buildSortHref('poster_workshop')) ?>">Цех Poster <span class="sort-arrow"><?= $sortArrow('poster_workshop') ?></span></a></th>
                            <th data-col="poster_category"><a class="sort-link" href="<?= htmlspecialchars($buildSortHref('poster_category')) ?>">Категория Poster <span class="sort-arrow"><?= $sortArrow('poster_category') ?></span></a></th>
                            <th data-col="adapted_workshop_ru"><a class="sort-link" href="<?= htmlspecialchars($buildSortHref('adapted_workshop_ru')) ?>">Цех адапт. RU <span class="sort-arrow"><?= $sortArrow('adapted_workshop_ru') ?></span></a></th>
                            <th data-col="adapted_workshop_en"><a class="sort-link" href="<?= htmlspecialchars($buildSortHref('adapted_workshop_en')) ?>">Цех адапт. EN <span class="sort-arrow"><?= $sortArrow('adapted_workshop_en') ?></span></a></th>
                            <th data-col="adapted_workshop_vn"><a class="sort-link" href="<?= htmlspecialchars($buildSortHref('adapted_workshop_vn')) ?>">Цех адапт. VN <span class="sort-arrow"><?= $sortArrow('adapted_workshop_vn') ?></span></a></th>
                            <th data-col="adapted_workshop_ko"><a class="sort-link" href="<?= htmlspecialchars($buildSortHref('adapted_workshop_ko')) ?>">Цех адапт. KO <span class="sort-arrow"><?= $sortArrow('adapted_workshop_ko') ?></span></a></th>
                            <th data-col="adapted_category_ru"><a class="sort-link" href="<?= htmlspecialchars($buildSortHref('adapted_category_ru')) ?>">Категория адапт. RU <span class="sort-arrow"><?= $sortArrow('adapted_category_ru') ?></span></a></th>
                            <th data-col="adapted_category_en"><a class="sort-link" href="<?= htmlspecialchars($buildSortHref('adapted_category_en')) ?>">Категория адапт. EN <span class="sort-arrow"><?= $sortArrow('adapted_category_en') ?></span></a></th>
                            <th data-col="adapted_category_vn"><a class="sort-link" href="<?= htmlspecialchars($buildSortHref('adapted_category_vn')) ?>">Категория адапт. VN <span class="sort-arrow"><?= $sortArrow('adapted_category_vn') ?></span></a></th>
                            <th data-col="adapted_category_ko"><a class="sort-link" href="<?= htmlspecialchars($buildSortHref('adapted_category_ko')) ?>">Категория адапт. KO <span class="sort-arrow"><?= $sortArrow('adapted_category_ko') ?></span></a></th>
                            <th data-col="status"><a class="sort-link" href="<?= htmlspecialchars($buildSortHref('status')) ?>">Статус <span class="sort-arrow"><?= $sortArrow('status') ?></span></a></th>
                            <th>Скрыть</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($menuItems as $it): ?>
                            <?php
                                $isActive = (int)$it['is_active'] === 1;
                                $isPublished = (int)($it['is_published'] ?? 0) === 1;
                                $ruTitle = trim((string)($it['ru_title'] ?? ''));
                                $enTitle = trim((string)($it['en_title'] ?? ''));
                                $vnTitle = trim((string)($it['vn_title'] ?? ''));
                                $koTitle = trim((string)($it['ko_title'] ?? ''));
                                $isUnadapted = ($ruTitle === '' || $enTitle === '' || $vnTitle === '');
                                $posterStation = trim((string)($it['station_name'] ?? ''));
                                if ($posterStation === '' && (int)($it['station_id'] ?? 0) > 0) {
                                    $posterStation = 'workshop ' . (int)$it['station_id'];
                                }
                                $posterWorkshop = $stripNumberPrefix((string)($it['main_category_name'] ?? ''));
                                $posterCategory = $stripNumberPrefix((string)($it['sub_category_name'] ?? ''));
                                $statusPills = [];
                                $statusPills[] = $isActive ? '<span class="pill ok">Poster</span>' : '<span class="pill bad">Не найдено</span>';
                                $statusPills[] = $isPublished && $isActive ? '<span class="pill ok">Опублик.</span>' : '<span class="pill warn">Скрыто</span>';
                                if ($isUnadapted) $statusPills[] = '<span class="pill warn">!</span>';
                                $hideChecked = !$isPublished || !$isActive;
                            ?>
                            <tr>
                                <td data-col="poster_id"><?= (int)$it['poster_id'] ?></td>
                                <td data-col="title_ru">
                                    <div style="font-weight:700;"><?= htmlspecialchars($ruTitle !== '' ? $ruTitle : (string)$it['name_raw']) ?></div>
                                    <div class="muted"><?= htmlspecialchars((string)$it['name_raw']) ?></div>
                                </td>
                                <td data-col="title_en"><?= htmlspecialchars($enTitle) ?></td>
                                <td data-col="title_vn"><?= htmlspecialchars($vnTitle) ?></td>
                                <td data-col="title_ko"><?= htmlspecialchars($koTitle) ?></td>
                                <td data-col="price"><?= htmlspecialchars((string)($it['price_raw'] ?? '')) ?></td>
                                <td data-col="poster_station"><?= htmlspecialchars($posterStation) ?></td>
                                <td data-col="poster_workshop"><?= htmlspecialchars($posterWorkshop) ?></td>
                                <td data-col="poster_category"><?= htmlspecialchars($posterCategory) ?></td>
                                <td data-col="adapted_workshop_ru"><?= htmlspecialchars((string)($it['adapted_workshop_ru'] ?? '')) ?></td>
                                <td data-col="adapted_workshop_en"><?= htmlspecialchars((string)($it['adapted_workshop_en'] ?? '')) ?></td>
                                <td data-col="adapted_workshop_vn"><?= htmlspecialchars((string)($it['adapted_workshop_vn'] ?? '')) ?></td>
                                <td data-col="adapted_workshop_ko"><?= htmlspecialchars((string)($it['adapted_workshop_ko'] ?? '')) ?></td>
                                <td data-col="adapted_category_ru"><?= htmlspecialchars((string)($it['adapted_category_ru'] ?? '')) ?></td>
                                <td data-col="adapted_category_en"><?= htmlspecialchars((string)($it['adapted_category_en'] ?? '')) ?></td>
                                <td data-col="adapted_category_vn"><?= htmlspecialchars((string)($it['adapted_category_vn'] ?? '')) ?></td>
                                <td data-col="adapted_category_ko"><?= htmlspecialchars((string)($it['adapted_category_ko'] ?? '')) ?></td>
                                <td data-col="status"><?= implode(' ', $statusPills) ?></td>
                                <td>
                                    <input type="checkbox"
                                           class="publish-toggle"
                                           data-poster-id="<?= (int)$it['poster_id'] ?>"
                                           <?= $hideChecked ? 'checked' : '' ?>
                                           <?= !$isActive ? 'disabled' : '' ?>>
                                </td>
                                <td>
                                    <a href="admin.php?tab=menu&view=edit&poster_id=<?= (int)$it['poster_id'] ?>" style="text-decoration:none; color:var(--accent); font-weight:800;" title="Редактировать">&#9998;</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>

                <?php if ($pages > 1): ?>
                    <div style="margin-top: 14px; display:flex; gap:10px; flex-wrap:wrap; align-items:center; justify-content:center;">
                        <?php for ($p=1; $p<=$pages; $p++): ?>
                            <?php
                                $qs = $_GET;
                                $qs['tab'] = 'menu';
                                $qs['view'] = 'list';
                                $qs['page'] = $p;
                                $href = 'admin.php?' . http_build_query($qs);
                            ?>
                            <a href="<?= htmlspecialchars($href) ?>" class="<?= $p === $menuPage ? 'pill ok' : 'pill warn' ?>" style="text-decoration:none;"><?= $p ?></a>
                        <?php endfor; ?>
                    </div>
                <?php endif; ?>

                <script>
                    (() => {
                        const toggles = Array.from(document.querySelectorAll('.col-toggle'));
                        const keys = toggles.map((cb) => cb.getAttribute('data-col')).filter(Boolean);
                        const params = new URLSearchParams(window.location.search);
                        const fromUrl = (params.get('cols') || '').trim();
                        const userEmail = <?= json_encode((string)($_SESSION['user_email'] ?? ''), JSON_UNESCAPED_UNICODE) ?>;
                        const storageKey = userEmail ? `menu_cols:${userEmail}` : 'menu_cols';
                        let fromSession = '';
                        let fromStorage = '';
                        try { fromSession = (sessionStorage.getItem(storageKey) || '').trim(); } catch (e) {}
                        try { fromStorage = (localStorage.getItem(storageKey) || '').trim(); } catch (e) {}
                        const initial = fromUrl || fromSession || fromStorage;
                        let selected = new Set();
                        if (initial) {
                            initial.split(',').map(s => s.trim()).filter(Boolean).forEach(k => selected.add(k));
                        } else {
                            toggles.forEach((cb) => {
                                const k = cb.getAttribute('data-col');
                                const isDefault = cb.getAttribute('data-default') === '1';
                                if (k && isDefault) {
                                    selected.add(k);
                                }
                            });
                        }

                        const apply = () => {
                            keys.forEach((k) => {
                                const show = selected.has(k);
                                document.querySelectorAll(`[data-col="${k}"]`).forEach((el) => {
                                    el.style.display = show ? '' : 'none';
                                });
                            });
                            document.querySelectorAll('.col-toggle').forEach((cb) => {
                                const k = cb.getAttribute('data-col');
                                cb.checked = selected.has(k);
                            });
                            params.set('cols', Array.from(selected).join(','));
                            const newUrl = `${window.location.pathname}?${params.toString()}`;
                            window.history.replaceState({}, '', newUrl);
                            const value = Array.from(selected).join(',');
                            try { sessionStorage.setItem(storageKey, value); } catch (e) {}
                            try { localStorage.setItem(storageKey, value); } catch (e) {}
                            const hidden = document.querySelector('input[name="cols"]');
                            if (hidden) hidden.value = Array.from(selected).join(',');
                        };

                        document.querySelectorAll('.col-toggle').forEach((cb) => {
                            cb.addEventListener('change', () => {
                                const k = cb.getAttribute('data-col');
                                if (cb.checked) selected.add(k);
                                else selected.delete(k);
                                apply();
                            });
                        });
                        apply();
                    })();

                    document.querySelectorAll('.publish-toggle').forEach((el) => {
                        el.addEventListener('change', async () => {
                            const posterId = parseInt(el.getAttribute('data-poster-id'), 10);
                            const isHidden = el.checked;
                            const isPublished = !isHidden;
                            const prev = !el.checked;
                            el.disabled = true;
                            try {
                                const res = await fetch('admin.php?ajax=menu_publish', {
                                    method: 'POST',
                                    headers: { 'Content-Type': 'application/json' },
                                    body: JSON.stringify({ poster_id: posterId, is_published: isPublished })
                                });
                                const data = await res.json().catch(() => ({}));
                                if (!res.ok || !data.ok) {
                                    el.checked = !isPublished;
                                    alert((data && data.error) ? data.error : 'Ошибка обновления');
                                }
                                if (data && data.disabled) {
                                    el.disabled = true;
                                } else {
                                    el.disabled = false;
                                }
                            } catch (e) {
                                el.checked = !isPublished;
                                el.disabled = false;
                                alert('Ошибка сети');
                            }
                        });
                    });
                </script>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    <script src="/assets/js/admin_3.js"></script>
    <script src="/assets/user_menu.js" defer></script>
</body>
</html>
