<?php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/src/classes/PosterAPI.php';
require_once __DIR__ . '/src/classes/PosterMenuSync.php';
require_once __DIR__ . '/src/classes/MenuAutoFill.php';
veranda_require('admin');

$message = '';
$error = '';

$tab = $_GET['tab'] ?? 'main';
if (!in_array($tab, ['main', 'menu'], true)) {
    $tab = 'main';
}

$columnExists = function (\App\Classes\Database $db, string $dbName, string $table, string $column): bool {
    $row = $db->query(
        "SELECT COUNT(*) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?",
        [$dbName, $table, $column]
    )->fetch();
    return (int)($row['c'] ?? 0) > 0;
};
if (!$columnExists($db, $dbName, 'users', 'permissions_json')) {
    $db->query("ALTER TABLE users ADD COLUMN permissions_json TEXT NULL AFTER is_active");
}
if (!$columnExists($db, $dbName, 'users', 'is_active')) {
    $db->query("ALTER TABLE users ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1");
}

$permissionKeys = [
    'dashboard' => 'Дашборд',
    'rawdata' => 'Сырые данные',
    'kitchen_online' => 'КухняOnline',
    'admin' => 'УПРАВЛЕНИЕ',
    'exclude_toggle' => 'Кнопка «Не учитывать»',
];

if (isset($_POST['save_user_permissions'])) {
    $targetEmail = trim((string)($_POST['perm_email'] ?? ''));
    if ($targetEmail !== '') {
        $perms = [];
        foreach ($permissionKeys as $k => $_label) {
            $perms[$k] = isset($_POST['perm_' . $k]) ? 1 : 0;
        }
        $db->query("UPDATE users SET permissions_json = ? WHERE email = ? LIMIT 1", [json_encode($perms, JSON_UNESCAPED_UNICODE), $targetEmail]);
        $message = "Права для $targetEmail сохранены.";
    }
}

// Add user
if (isset($_POST['add_email'])) {
    $email = trim($_POST['email']);
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        try {
            $db->query("INSERT INTO users (email) VALUES (?)", [$email]);
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
        $db->query("DELETE FROM users WHERE email = ?", [$emailToDelete]);
        $message = "Пользователь $emailToDelete удален.";
    } else {
        $error = "Вы не можете удалить свой собственный email.";
    }
}

$users = $db->query("SELECT * FROM users ORDER BY created_at DESC")->fetchAll();

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
        $db->query("INSERT INTO system_meta (meta_key, meta_value) VALUES (?, ?) 
                    ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)", [$key, $val]);
    }
    $message = "Настройки успешно сохранены.";
}

$telegramAckWhitelistKey = 'telegram_ack_whitelist';
if (isset($_POST['save_tg_whitelist'])) {
    $raw = (string)($_POST['telegram_ack_whitelist_text'] ?? '');
    $lines = preg_split('/\R/u', $raw);
    $entries = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $username = $line;
        $comment = '';
        if (strpos($line, ' - ') !== false) {
            [$username, $comment] = explode(' - ', $line, 2);
        } elseif (strpos($line, "\t") !== false) {
            [$username, $comment] = explode("\t", $line, 2);
        } elseif (strpos($line, '|') !== false) {
            [$username, $comment] = explode('|', $line, 2);
        }
        $username = trim($username);
        $comment = trim($comment);
        $username = ltrim($username, '@');
        $username = strtolower($username);
        if ($username === '') {
            continue;
        }
        $entries[$username] = $comment;
    }
    $encoded = json_encode($entries, JSON_UNESCAPED_UNICODE);
    $db->query(
        "INSERT INTO system_meta (meta_key, meta_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)",
        [$telegramAckWhitelistKey, $encoded]
    );
    $message = "Whitelist Telegram успешно сохранён.";
}

$codemealDefaults = [
    'codemeal_client_number' => '',
    'codemeal_auth' => '',
    'codemeal_locale' => 'en',
    'codemeal_timezone' => 'Asia/Ho_Chi_Minh',
];

if (isset($_POST['save_codemeal'])) {
    $client = trim((string)($_POST['codemeal_client_number'] ?? ''));
    $authNew = trim((string)($_POST['codemeal_auth'] ?? ''));
    $locale = strtolower(trim((string)($_POST['codemeal_locale'] ?? 'en')));
    $timezone = trim((string)($_POST['codemeal_timezone'] ?? 'Asia/Ho_Chi_Minh'));

    if ($locale === '') {
        $locale = 'en';
    }
    if ($timezone === '') {
        $timezone = 'Asia/Ho_Chi_Minh';
    }

    $existingAuthRow = $db->query("SELECT meta_value FROM system_meta WHERE meta_key=? LIMIT 1", ['codemeal_auth'])->fetch();
    $existingAuth = $existingAuthRow ? (string)$existingAuthRow['meta_value'] : '';
    $authToSave = $authNew !== '' ? $authNew : $existingAuth;

    $db->query(
        "INSERT INTO system_meta (meta_key, meta_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)",
        ['codemeal_client_number', $client]
    );
    $db->query(
        "INSERT INTO system_meta (meta_key, meta_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)",
        ['codemeal_locale', $locale]
    );
    $db->query(
        "INSERT INTO system_meta (meta_key, meta_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)",
        ['codemeal_timezone', $timezone]
    );
    if ($authToSave !== '') {
        $db->query(
            "INSERT INTO system_meta (meta_key, meta_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)",
            ['codemeal_auth', $authToSave]
        );
    }

    $message = "Настройки Codemeal сохранены.";
}

$settings = [];
foreach ($settingKeys as $key => $default) {
    $row = $db->query("SELECT meta_value FROM system_meta WHERE meta_key = ? LIMIT 1", [$key])->fetch();
    $settings[$key] = $row ? $row['meta_value'] : $default;
    if (is_numeric($default)) {
        $settings[$key] = (int)$settings[$key];
    }
}

$codemealSettings = [];
foreach ($codemealDefaults as $key => $default) {
    $row = $db->query("SELECT meta_value FROM system_meta WHERE meta_key = ? LIMIT 1", [$key])->fetch();
    $codemealSettings[$key] = $row ? (string)$row['meta_value'] : (string)$default;
}
$codemealAuthMasked = '';
$rawAuth = trim((string)($codemealSettings['codemeal_auth'] ?? ''));
if ($rawAuth !== '') {
    $prefix = mb_substr($rawAuth, 0, 6);
    $suffix = mb_substr($rawAuth, max(0, mb_strlen($rawAuth) - 4));
    $codemealAuthMasked = $prefix . '…' . $suffix;
}

$whitelistRow = $db->query("SELECT meta_value FROM system_meta WHERE meta_key = ? LIMIT 1", [$telegramAckWhitelistKey])->fetch();
$whitelistJson = $whitelistRow ? (string)$whitelistRow['meta_value'] : '{}';
$whitelist = json_decode($whitelistJson, true);
if (!is_array($whitelist)) {
    $whitelist = [];
}
$whitelistTextLines = [];
foreach ($whitelist as $username => $comment) {
    $username = ltrim((string)$username, '@');
    $username = strtolower($username);
    if ($username === '') {
        continue;
    }
    $comment = trim((string)$comment);
    $whitelistTextLines[] = $comment !== '' ? "{$username} - {$comment}" : $username;
}
$telegramAckWhitelistText = implode("\n", $whitelistTextLines);

$isMenuAjax = ($_GET['ajax'] ?? '') === 'menu_publish';
if ($isMenuAjax) {
    $db->createMenuTables();
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
        "SELECT p.id poster_item_id, p.is_active,
                ru.title ru_title, ru.is_published ru_is_published,
                en.title en_title, vn.title vn_title
         FROM poster_menu_items p
         LEFT JOIN menu_items_ru ru ON ru.poster_item_id = p.id
         LEFT JOIN menu_items_en en ON en.poster_item_id = p.id
         LEFT JOIN menu_items_vn vn ON vn.poster_item_id = p.id
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
        $db->query(
            "UPDATE menu_items_ru ru
             JOIN poster_menu_items p ON p.id = ru.poster_item_id
             SET ru.is_published = 0
             WHERE p.poster_id = ?",
            [$posterId]
        );
        echo json_encode(['ok' => true, 'is_published' => false, 'disabled' => true, 'reason' => 'not_found'], JSON_UNESCAPED_UNICODE);
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
        "UPDATE menu_items_ru ru
         JOIN poster_menu_items p ON p.id = ru.poster_item_id
         SET ru.is_published = ?
         WHERE p.poster_id = ?",
        [$isPublished ? 1 : 0, $posterId]
    );
    echo json_encode(['ok' => true, 'is_published' => $isPublished], JSON_UNESCAPED_UNICODE);
    exit;
}

$menuView = $_GET['view'] ?? 'list';
if (!in_array($menuView, ['list', 'edit', 'categories'], true)) {
    $menuView = 'list';
}
$menuItems = [];
$menuTotal = 0;
$menuPerPage = 50;
$menuPage = max(1, (int)($_GET['page'] ?? 1));
$menuEdit = null;
$menuCategoriesMain = [];
$menuCategoriesSub = [];
$menuSyncMeta = ['last_sync_at' => null, 'last_sync_result' => null, 'last_sync_error' => null];
$menuSyncAtIso = '';

if ($tab === 'menu') {
    $db->createMenuTables();

    $metaKeys = ['menu_last_sync_at', 'menu_last_sync_result', 'menu_last_sync_error'];
    foreach ($metaKeys as $k) {
        $row = $db->query("SELECT meta_value FROM system_meta WHERE meta_key=? LIMIT 1", [$k])->fetch();
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
            if ($posterToken === '') {
                throw new \Exception('POSTER_API_TOKEN не задан в .env');
            }
            $api = new \App\Classes\PosterAPI($posterToken);
            $sync = new \App\Classes\PosterMenuSync($api, $db);
            $result = $sync->sync();
            $db->query(
                "INSERT INTO system_meta (meta_key, meta_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)",
                ['menu_last_sync_at', (new DateTime('now', new DateTimeZone('Asia/Ho_Chi_Minh')))->format('Y-m-d H:i:s')]
            );
            $db->query(
                "INSERT INTO system_meta (meta_key, meta_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)",
                ['menu_last_sync_result', json_encode($result, JSON_UNESCAPED_UNICODE)]
            );
            $db->query(
                "INSERT INTO system_meta (meta_key, meta_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)",
                ['menu_last_sync_error', '']
            );
            $message = 'Меню обновлено из Poster.';
        } catch (\Exception $e) {
            $db->query(
                "INSERT INTO system_meta (meta_key, meta_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)",
                ['menu_last_sync_error', $e->getMessage()]
            );
            $error = 'Ошибка обновления меню: ' . $e->getMessage();
        }
    }

    if (isset($_POST['autofill_menu'])) {
        try {
            $autofill = new \App\Classes\MenuAutoFill($db);
            $result = $autofill->run();
            $message = 'Автозаполнение меню выполнено: ' . json_encode($result, JSON_UNESCAPED_UNICODE);
        } catch (\Exception $e) {
            $error = 'Ошибка автозаполнения меню: ' . $e->getMessage();
        }
    }

    if (isset($_POST['save_menu_item'])) {
        $posterId = (int)($_POST['poster_id'] ?? 0);
        $posterRow = $db->query("SELECT id, is_active FROM poster_menu_items WHERE poster_id=? LIMIT 1", [$posterId])->fetch();
        $posterItemId = (int)($posterRow['id'] ?? 0);
        if ($posterItemId <= 0) {
            $error = 'Позиция не найдена в Poster-таблице.';
        } else {
            $ruTitle = trim((string)($_POST['ru_title'] ?? ''));
            $ruMainId = ($_POST['ru_main_category_id'] ?? '') !== '' ? (int)$_POST['ru_main_category_id'] : null;
            $ruSubId = ($_POST['ru_sub_category_id'] ?? '') !== '' ? (int)$_POST['ru_sub_category_id'] : null;
            $ruDescription = trim((string)($_POST['ru_description'] ?? ''));
            $ruImage = trim((string)($_POST['ru_image_url'] ?? ''));
            $ruSort = (int)($_POST['ru_sort_order'] ?? 0);
            $ruPublished = isset($_POST['ru_is_published']) ? 1 : 0;

            $enTitle = trim((string)($_POST['en_title'] ?? ''));
            $enMainId = ($_POST['en_main_category_id'] ?? '') !== '' ? (int)$_POST['en_main_category_id'] : $ruMainId;
            $enSubId = ($_POST['en_sub_category_id'] ?? '') !== '' ? (int)$_POST['en_sub_category_id'] : $ruSubId;
            $enDescription = trim((string)($_POST['en_description'] ?? ''));

            $vnTitle = trim((string)($_POST['vn_title'] ?? ''));
            $vnMainId = ($_POST['vn_main_category_id'] ?? '') !== '' ? (int)$_POST['vn_main_category_id'] : $ruMainId;
            $vnSubId = ($_POST['vn_sub_category_id'] ?? '') !== '' ? (int)$_POST['vn_sub_category_id'] : $ruSubId;
            $vnDescription = trim((string)($_POST['vn_description'] ?? ''));

            $koTitle = trim((string)($_POST['ko_title'] ?? ''));
            $koMainId = ($_POST['ko_main_category_id'] ?? '') !== '' ? (int)$_POST['ko_main_category_id'] : $enMainId;
            $koSubId = ($_POST['ko_sub_category_id'] ?? '') !== '' ? (int)$_POST['ko_sub_category_id'] : $enSubId;
            $koDescription = trim((string)($_POST['ko_description'] ?? ''));

            if ((int)($posterRow['is_active'] ?? 1) === 0) {
                $ruPublished = 0;
            }
            if ($ruPublished === 1 && ($ruTitle === '' || $enTitle === '' || $vnTitle === '')) {
                $error = 'Для публикации заполните названия RU/EN/VN.';
            } else {
                $db->query(
                    "INSERT INTO menu_items_ru
                        (poster_item_id, title, main_category_id, sub_category_id, description, image_url, is_published, sort_order)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE
                        title=VALUES(title),
                        main_category_id=VALUES(main_category_id),
                        sub_category_id=VALUES(sub_category_id),
                        description=VALUES(description),
                        image_url=VALUES(image_url),
                        is_published=VALUES(is_published),
                        sort_order=VALUES(sort_order)",
                    [$posterItemId, $ruTitle !== '' ? $ruTitle : null, $ruMainId, $ruSubId, $ruDescription !== '' ? $ruDescription : null, $ruImage !== '' ? $ruImage : null, $ruPublished, $ruSort]
                );

                $db->query(
                    "INSERT INTO menu_items_en
                        (poster_item_id, title, main_category_id, sub_category_id, description)
                     VALUES (?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE
                        title=VALUES(title),
                        main_category_id=VALUES(main_category_id),
                        sub_category_id=VALUES(sub_category_id),
                        description=VALUES(description)",
                    [$posterItemId, $enTitle !== '' ? $enTitle : null, $enMainId, $enSubId, $enDescription !== '' ? $enDescription : null]
                );

                $db->query(
                    "INSERT INTO menu_items_vn
                        (poster_item_id, title, main_category_id, sub_category_id, description)
                     VALUES (?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE
                        title=VALUES(title),
                        main_category_id=VALUES(main_category_id),
                        sub_category_id=VALUES(sub_category_id),
                        description=VALUES(description)",
                    [$posterItemId, $vnTitle !== '' ? $vnTitle : null, $vnMainId, $vnSubId, $vnDescription !== '' ? $vnDescription : null]
                );

                $db->query(
                    "INSERT INTO menu_items_ko
                        (poster_item_id, title, main_category_id, sub_category_id, description)
                     VALUES (?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE
                        title=VALUES(title),
                        main_category_id=VALUES(main_category_id),
                        sub_category_id=VALUES(sub_category_id),
                        description=VALUES(description)",
                    [$posterItemId, $koTitle !== '' ? $koTitle : null, $koMainId, $koSubId, $koDescription !== '' ? $koDescription : null]
                );

                $message = 'Блюдо сохранено.';
                $menuView = 'edit';
                $_GET['poster_id'] = $posterId;
            }
        }
    }

    if (isset($_POST['save_categories'])) {
        $mainSort = $_POST['main_sort'] ?? [];
        if (is_array($mainSort)) {
            foreach ($mainSort as $id => $sort) {
                $show = isset($_POST['main_show'][(int)$id]) ? 1 : 0;
                $db->query("UPDATE menu_categories_main SET sort_order=?, show_in_menu=? WHERE id=?", [(int)$sort, $show, (int)$id]);
            }
        }
        $subSort = $_POST['sub_sort'] ?? [];
        if (is_array($subSort)) {
            foreach ($subSort as $id => $sort) {
                $show = isset($_POST['sub_show'][(int)$id]) ? 1 : 0;
                $parent = $_POST['sub_parent'][(int)$id] ?? null;
                $parent = $parent !== null && $parent !== '' ? (int)$parent : null;
                $db->query("UPDATE menu_categories_sub SET sort_order=?, show_in_menu=?, main_category_id_override=? WHERE id=?", [(int)$sort, $show, $parent, (int)$id]);
            }
        }
        $mainTr = $_POST['main_tr'] ?? [];
        if (is_array($mainTr)) {
            foreach ($mainTr as $id => $langs) {
                if (!is_array($langs)) continue;
                foreach ($langs as $lang => $name) {
                    $lang = strtolower(trim((string)$lang));
                    $name = trim((string)$name);
                    if (!in_array($lang, ['ru', 'en', 'vn', 'ko'], true) || $name === '') {
                        continue;
                    }
                    $db->query(
                        "INSERT INTO menu_categories_main_tr (main_category_id, lang, name) VALUES (?, ?, ?)
                         ON DUPLICATE KEY UPDATE name=VALUES(name)",
                        [(int)$id, $lang, $name]
                    );
                }
            }
        }
        $subTr = $_POST['sub_tr'] ?? [];
        if (is_array($subTr)) {
            foreach ($subTr as $id => $langs) {
                if (!is_array($langs)) continue;
                foreach ($langs as $lang => $name) {
                    $lang = strtolower(trim((string)$lang));
                    $name = trim((string)$name);
                    if (!in_array($lang, ['ru', 'en', 'vn', 'ko'], true) || $name === '') {
                        continue;
                    }
                    $db->query(
                        "INSERT INTO menu_categories_sub_tr (sub_category_id, lang, name) VALUES (?, ?, ?)
                         ON DUPLICATE KEY UPDATE name=VALUES(name)",
                        [(int)$id, $lang, $name]
                    );
                }
            }
        }
        $message = 'Категории сохранены.';
        $menuView = 'categories';
    }

    $menuCategoriesMain = $db->query(
        "SELECT m.id, m.poster_main_category_id, m.name_raw, m.sort_order,
                m.show_in_menu,
                tr_ru.name name_ru, tr_en.name name_en, tr_vn.name name_vn, tr_ko.name name_ko
         FROM menu_categories_main m
         LEFT JOIN menu_categories_main_tr tr_ru ON tr_ru.main_category_id=m.id AND tr_ru.lang='ru'
         LEFT JOIN menu_categories_main_tr tr_en ON tr_en.main_category_id=m.id AND tr_en.lang='en'
         LEFT JOIN menu_categories_main_tr tr_vn ON tr_vn.main_category_id=m.id AND tr_vn.lang='vn'
         LEFT JOIN menu_categories_main_tr tr_ko ON tr_ko.main_category_id=m.id AND tr_ko.lang='ko'
         ORDER BY m.sort_order ASC, m.name_raw ASC"
    )->fetchAll();

    $menuCategoriesSub = $db->query(
        "SELECT s.id, s.poster_sub_category_id, s.main_category_id, s.main_category_id_override, s.name_raw, s.sort_order,
                s.show_in_menu,
                tr_ru.name name_ru, tr_en.name name_en, tr_vn.name name_vn, tr_ko.name name_ko
         FROM menu_categories_sub s
         LEFT JOIN menu_categories_sub_tr tr_ru ON tr_ru.sub_category_id=s.id AND tr_ru.lang='ru'
         LEFT JOIN menu_categories_sub_tr tr_en ON tr_en.sub_category_id=s.id AND tr_en.lang='en'
         LEFT JOIN menu_categories_sub_tr tr_vn ON tr_vn.sub_category_id=s.id AND tr_vn.lang='vn'
         LEFT JOIN menu_categories_sub_tr tr_ko ON tr_ko.sub_category_id=s.id AND tr_ko.lang='ko'
         ORDER BY s.sort_order ASC, s.name_raw ASC"
    )->fetchAll();

    $mainItemCounts = [];
    $rows = $db->query(
        "SELECT ru.main_category_id id, COUNT(*) c
         FROM menu_items_ru ru
         JOIN poster_menu_items p ON p.id = ru.poster_item_id
         WHERE p.is_active = 1 AND ru.main_category_id IS NOT NULL
         GROUP BY ru.main_category_id"
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
                "SELECT p.*, ru.title ru_title, ru.description ru_description, ru.image_url ru_image_url, ru.is_published ru_is_published, ru.sort_order ru_sort_order,
                        ru.main_category_id ru_main_category_id, ru.sub_category_id ru_sub_category_id,
                        en.title en_title, en.description en_description, en.main_category_id en_main_category_id, en.sub_category_id en_sub_category_id,
                        vn.title vn_title, vn.description vn_description, vn.main_category_id vn_main_category_id, vn.sub_category_id vn_sub_category_id,
                        ko.title ko_title, ko.description ko_description, ko.main_category_id ko_main_category_id, ko.sub_category_id ko_sub_category_id
                 FROM poster_menu_items p
                 LEFT JOIN menu_items_ru ru ON ru.poster_item_id = p.id
                 LEFT JOIN menu_items_en en ON en.poster_item_id = p.id
                 LEFT JOIN menu_items_vn vn ON vn.poster_item_id = p.id
                 LEFT JOIN menu_items_ko ko ON ko.poster_item_id = p.id
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
        $filterMain = ($_GET['main_category_id'] ?? '') !== '' ? (int)$_GET['main_category_id'] : null;
        $filterSub = ($_GET['sub_category_id'] ?? '') !== '' ? (int)$_GET['sub_category_id'] : null;
        $filterQ = trim((string)($_GET['q'] ?? ''));
        $filterStatus = trim((string)($_GET['status'] ?? ''));
        $menuSort = strtolower(trim((string)($_GET['sort'] ?? '')));
        $menuDir = strtolower(trim((string)($_GET['dir'] ?? '')));
        if (!in_array($menuDir, ['asc', 'desc'], true)) {
            $menuDir = 'asc';
        }
        $where = [];
        $params = [];

        if ($filterMain !== null) {
            $where[] = "COALESCE(ru.main_category_id, en.main_category_id, vn.main_category_id) = ?";
            $params[] = $filterMain;
        }
        if ($filterSub !== null) {
            $where[] = "COALESCE(ru.sub_category_id, en.sub_category_id, vn.sub_category_id) = ?";
            $params[] = $filterSub;
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
            $where[] = "ru.is_published = 1 AND p.is_active = 1";
        } elseif ($filterStatus === 'hidden') {
            $where[] = "ru.is_published = 0 AND p.is_active = 1";
        } elseif ($filterStatus === 'not_found') {
            $where[] = "p.is_active = 0";
        } elseif ($filterStatus === 'unadapted') {
            $where[] = "(COALESCE(ru.title,'') = '' OR COALESCE(en.title,'') = '' OR COALESCE(vn.title,'') = '')";
        }

        $whereSql = !empty($where) ? ('WHERE ' . implode(' AND ', $where)) : '';
        $countRow = $db->query(
            "SELECT COUNT(1) c
             FROM poster_menu_items p
             LEFT JOIN menu_items_ru ru ON ru.poster_item_id = p.id
             LEFT JOIN menu_items_en en ON en.poster_item_id = p.id
             LEFT JOIN menu_items_vn vn ON vn.poster_item_id = p.id
             LEFT JOIN menu_items_ko ko ON ko.poster_item_id = p.id
             $whereSql",
            $params
        )->fetch();
        $menuTotal = (int)($countRow['c'] ?? 0);

        $offset = ($menuPage - 1) * $menuPerPage;
        if ($menuSort === 'station') {
            $menuSort = 'poster_category';
        }
        $sortMap = [
            'poster_id' => 'p.poster_id',
            'title_ru' => "COALESCE(NULLIF(ru.title,''), p.name_raw)",
            'title_en' => "COALESCE(NULLIF(en.title,''), p.name_raw)",
            'title_vn' => "COALESCE(NULLIF(vn.title,''), p.name_raw)",
            'title_ko' => "COALESCE(NULLIF(ko.title,''), p.name_raw)",
            'price' => 'p.price_raw',
            'poster_category' => "COALESCE(NULLIF(p.station_name,''), CAST(p.station_id AS CHAR), '')",
            'poster_subcategory' => "COALESCE(NULLIF(p.main_category_name,''), '')",
            'adapted_category_ru' => "COALESCE(mtr_ru.name, mc.name_raw, p.main_category_name)",
            'adapted_category_en' => "COALESCE(mtr_en.name, mc.name_raw, p.main_category_name)",
            'adapted_category_vn' => "COALESCE(mtr_vn.name, mc.name_raw, p.main_category_name)",
            'adapted_category_ko' => "COALESCE(mtr_ko.name, mc.name_raw, p.main_category_name)",
            'adapted_subcategory_ru' => "COALESCE(str_ru.name, sc.name_raw, p.sub_category_name)",
            'adapted_subcategory_en' => "COALESCE(str_en.name, sc.name_raw, p.sub_category_name)",
            'adapted_subcategory_vn' => "COALESCE(str_vn.name, sc.name_raw, p.sub_category_name)",
            'adapted_subcategory_ko' => "COALESCE(str_ko.name, sc.name_raw, p.sub_category_name)",
            'active' => 'p.is_active',
            'published' => 'ru.is_published',
            'sort_order' => 'COALESCE(ru.sort_order, 0)',
            'main_sort' => 'COALESCE(mc.sort_order, 0)',
            'status' => "CASE WHEN p.is_active = 0 THEN 3 WHEN ru.is_published = 1 THEN 1 ELSE 2 END",
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
                ru.is_published,
                ru.main_category_id ru_main_category_id,
                ru.sub_category_id ru_sub_category_id,
                ru.sort_order,
                en.title en_title,
                vn.title vn_title,
                ko.title ko_title,
                COALESCE(mtr_ru.name, mc.name_raw, p.main_category_name) adapted_category_ru,
                COALESCE(mtr_en.name, mc.name_raw, p.main_category_name) adapted_category_en,
                COALESCE(mtr_vn.name, mc.name_raw, p.main_category_name) adapted_category_vn,
                COALESCE(mtr_ko.name, mc.name_raw, p.main_category_name) adapted_category_ko,
                COALESCE(str_ru.name, sc.name_raw, p.sub_category_name) adapted_subcategory_ru,
                COALESCE(str_en.name, sc.name_raw, p.sub_category_name) adapted_subcategory_en,
                COALESCE(str_vn.name, sc.name_raw, p.sub_category_name) adapted_subcategory_vn
                ,COALESCE(str_ko.name, sc.name_raw, p.sub_category_name) adapted_subcategory_ko
            FROM poster_menu_items p
            LEFT JOIN menu_items_ru ru ON ru.poster_item_id = p.id
            LEFT JOIN menu_items_en en ON en.poster_item_id = p.id
            LEFT JOIN menu_items_vn vn ON vn.poster_item_id = p.id
            LEFT JOIN menu_items_ko ko ON ko.poster_item_id = p.id
            LEFT JOIN menu_categories_main mc ON mc.id = ru.main_category_id
            LEFT JOIN menu_categories_main_tr mtr_ru ON mtr_ru.main_category_id = mc.id AND mtr_ru.lang='ru'
            LEFT JOIN menu_categories_main_tr mtr_en ON mtr_en.main_category_id = mc.id AND mtr_en.lang='en'
            LEFT JOIN menu_categories_main_tr mtr_vn ON mtr_vn.main_category_id = mc.id AND mtr_vn.lang='vn'
            LEFT JOIN menu_categories_main_tr mtr_ko ON mtr_ko.main_category_id = mc.id AND mtr_ko.lang='ko'
            LEFT JOIN menu_categories_sub sc ON sc.id = ru.sub_category_id
            LEFT JOIN menu_categories_sub_tr str_ru ON str_ru.sub_category_id = sc.id AND str_ru.lang='ru'
            LEFT JOIN menu_categories_sub_tr str_en ON str_en.sub_category_id = sc.id AND str_en.lang='en'
            LEFT JOIN menu_categories_sub_tr str_vn ON str_vn.sub_category_id = sc.id AND str_vn.lang='vn'
            LEFT JOIN menu_categories_sub_tr str_ko ON str_ko.sub_category_id = sc.id AND str_ko.lang='ko'
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
    <link rel="icon" type="image/png" href="favicon.png">
    <title>УПРАВЛЕНИЕ - Kitchen Analytics</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f0f2f5; padding: 20px; color: #1c1e21; }
        .container { max-width: 1300px; margin: 0 auto; }
        h1 { text-align: center; color: #1a73e8; margin-bottom: 30px; }
        .card { background: white; padding: 25px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); margin-bottom: 25px; border: 1px solid #ddd; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; font-weight: 600; margin-bottom: 8px; color: #65676b; font-size: 14px; text-transform: uppercase; }
        .form-group input, .form-group select { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 6px; font-size: 16px; box-sizing: border-box; }
        button { background: #1a73e8; color: white; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer; font-weight: 500; }
        button:hover { background: #1557b0; }
        .success { background: #e8f5e9; color: #2e7d32; padding: 15px; border-radius: 6px; margin-bottom: 20px; border: 1px solid #c8e6c9; }
        .error { background: #fdecea; color: #d32f2f; padding: 15px; border-radius: 6px; margin-bottom: 20px; border: 1px solid #f5c2c7; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { padding: 15px; text-align: left; border-bottom: 1px solid #eee; }
        th { background: #f8f9fa; color: #65676b; font-size: 13px; text-transform: uppercase; font-weight: 600; }
        .delete-btn { color: #d32f2f; text-decoration: none; font-size: 14px; }
        .delete-btn:hover { text-decoration: underline; }
        .perm-gear { border: 0; background: transparent; cursor: pointer; font-size: 16px; color: #546e7a; padding: 4px 8px; }
        .perm-gear:hover { color: #1a73e8; }
        .perm-modal { position: fixed; inset: 0; z-index: 2000; display: flex; align-items: center; justify-content: center; }
        .perm-modal-backdrop { position: absolute; inset: 0; background: rgba(0,0,0,0.45); }
        .perm-modal-card { position: relative; width: 420px; max-width: calc(100vw - 24px); background: #fff; border-radius: 12px; padding: 16px; box-shadow: 0 12px 28px rgba(0,0,0,0.25); }
        .perm-modal-title { font-weight: 800; color: #2c3e50; margin-bottom: 10px; }
        .perm-list { display: grid; gap: 8px; margin: 10px 0 14px; }
        .perm-row { display: flex; align-items: center; gap: 10px; padding: 8px 10px; border: 1px solid #e5e7eb; border-radius: 10px; }
        .perm-actions { display: flex; justify-content: flex-end; gap: 10px; }
        .perm-cancel { background: #eceff1; color: #37474f; }
        .top-nav { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; gap: 16px; }
        .nav-left { display: flex; gap: 14px; flex-wrap: wrap; align-items: center; }
        .nav-left a { color: #1a73e8; text-decoration: none; font-weight: 500; }
        .nav-left a:hover { text-decoration: underline; }
        .user-menu { position: relative; }
        .user-chip { display: inline-flex; align-items: center; gap: 8px; padding: 6px 10px; border: 1px solid #e5e7eb; border-radius: 999px; background: #fff; color: #37474f; font-weight: 600; cursor: default; }
        .user-icon { width: 22px; height: 22px; border-radius: 50%; background: #e3f2fd; display: inline-flex; align-items: center; justify-content: center; color: #1a73e8; font-weight: 800; font-size: 12px; overflow: hidden; }
        .user-icon img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .user-dropdown { position: absolute; right: 0; top: calc(100% + 8px); background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; box-shadow: 0 8px 18px rgba(0,0,0,0.12); padding: 8px; min-width: 160px; display: none; z-index: 1000; }
        .user-menu.open .user-dropdown { display: block; }
        .user-dropdown a { display: block; padding: 8px 10px; border-radius: 8px; color: #37474f; text-decoration: none; font-weight: 600; }
        .user-dropdown a:hover { background: #f5f6fa; }
        .tab-links { text-align: center; margin: -10px 0 24px; }
        .tab-links a { display: inline-block; padding: 8px 14px; border-radius: 999px; margin: 0 6px; text-decoration: none; font-weight: 600; color: #1a73e8; background: rgba(26,115,232,0.08); }
        .tab-links a.active { color: white; background: #1a73e8; }
        .tab-links a:hover { background: rgba(26,115,232,0.14); }
        .description-card { background: #fff; border-left: 4px solid #1a73e8; padding: 20px; border-radius: 4px; font-size: 14px; line-height: 1.6; color: #444; }
        .description-card h4 { margin-top: 0; color: #1a73e8; }
        .description-card ul { padding-left: 20px; }
        .settings-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px; }
        textarea { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 6px; font-size: 14px; box-sizing: border-box; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }
        .pill { display: inline-block; padding: 4px 10px; border-radius: 999px; font-size: 12px; font-weight: 700; }
        .pill.ok { background: #e8f5e9; color: #2e7d32; border: 1px solid #c8e6c9; }
        .pill.bad { background: #fdecea; color: #d32f2f; border: 1px solid #f5c2c7; }
        .pill.warn { background: #fff8e1; color: #8d6e00; border: 1px solid #ffe0b2; }
        .menu-actions { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; justify-content: space-between; }
        .menu-actions .left { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
        .menu-actions .right { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
        .menu-filters { display: grid; grid-template-columns: 1fr 1fr 2fr 1fr; gap: 12px; margin-top: 14px; }
        .menu-filters .form-group { margin-bottom: 0; }
        .menu-table td { vertical-align: top; }
        .table-wrap { width: 100%; overflow-x: auto; }
        .menu-table { width: 100%; max-width: 100%; }
        .menu-table th, .menu-table td { padding: 10px; }
        .menu-table input, .menu-table select, .menu-table textarea { max-width: 100%; }
        .sticky-hscroll-bar { position: fixed; left: 0; right: 0; bottom: 0; padding: 10px 20px; z-index: 1000; pointer-events: none; }
        .sticky-hscroll { max-width: 1300px; margin: 0 auto; border: 1px solid #ddd; border-radius: 10px; background: rgba(255,255,255,0.92); box-shadow: 0 10px 26px rgba(0,0,0,0.16); pointer-events: auto; }
        .sticky-hscroll-viewport { overflow-x: auto; overflow-y: hidden; height: 14px; }
        .sticky-hscroll-content { height: 1px; }
        .sort-link { color: inherit; text-decoration: none; display: inline-flex; gap: 6px; align-items: center; }
        .sort-link:hover { text-decoration: underline; }
        .sort-arrow { color: #1a73e8; font-size: 12px; }
        .muted { color: #777; font-size: 12px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="top-nav">
            <div class="nav-left"></div>
            <div class="user-menu">
                <?php
                    $userLabel = (string)($_SESSION['user_name'] ?? $_SESSION['user_email'] ?? '');
                    $initial = mb_strtoupper(mb_substr($userLabel !== '' ? $userLabel : 'U', 0, 1));
                    $avatar = (string)($_SESSION['user_avatar'] ?? '');
                ?>
                <div class="user-chip">
                    <span class="user-icon"><?php if ($avatar !== ''): ?><img src="<?= htmlspecialchars($avatar) ?>" alt=""><?php else: ?><?= htmlspecialchars($initial) ?><?php endif; ?></span>
                    <span><?= htmlspecialchars($userLabel) ?></span>
                </div>
                <div class="user-dropdown">
                    <?php if (veranda_can('dashboard')): ?><a href="dashboard.php">Дашборд</a><?php endif; ?>
                    <?php if (veranda_can('rawdata')): ?><a href="rawdata.php">Таблица</a><?php endif; ?>
                    <?php if (veranda_can('kitchen_online')): ?><a href="kitchen_online.php">КухняОнлайн</a><?php endif; ?>
                    <?php if (veranda_can('admin')): ?><a href="admin.php">Управление</a><?php endif; ?>
                    <a href="logout.php">Выход</a>
                </div>
            </div>
        </div>
        <h1>Управление</h1>

        <?php if ($message): ?><div class="success"><?= $message ?></div><?php endif; ?>
        <?php if ($error): ?><div class="error"><?= $error ?></div><?php endif; ?>

        <div class="tab-links">
            <a href="admin.php?tab=main" class="<?= $tab === 'main' ? 'active' : '' ?>">Основное</a>
            <a href="admin.php?tab=menu" class="<?= $tab === 'menu' ? 'active' : '' ?>">Меню</a>
        </div>

        <?php if ($tab === 'main'): ?>
        <div class="card">
            <h3>Настройки уведомлений (Telegram)</h3>
            <form method="POST">
                <div class="settings-grid">
                    <div class="form-group">
                        <label>Тайминг до выс. нагрузки (мин)</label>
                        <input type="number" name="alert_timing_low_load" value="<?= $settings['alert_timing_low_load'] ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Порог чеков для выс. нагрузки</label>
                        <input type="number" name="alert_load_threshold" value="<?= $settings['alert_load_threshold'] ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Тайминг при выс. нагрузке (мин)</label>
                        <input type="number" name="alert_timing_high_load" value="<?= $settings['alert_timing_high_load'] ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Повтор после "✅ Принято" (мин)</label>
                        <input type="number" name="alert_ack_snooze_minutes" value="<?= $settings['alert_ack_snooze_minutes'] ?>" required>
                    </div>
                </div>
                <button type="submit" name="save_settings">Сохранить настройки</button>
            </form>
            <form method="POST" style="margin-top: 15px;">
                 <input type="hidden" name="save_settings" value="1">
                 <input type="hidden" name="exclude_partners_from_load" value="0">
                 <label style="display:flex; align-items:center; gap: 8px; font-size: 15px;">
                     <input type="checkbox" name="exclude_partners_from_load" value="1" <?= !empty($settings['exclude_partners_from_load']) ? 'checked' : '' ?> onchange="this.form.submit()">
                     ИСКЛЮЧИТЬ СТОЛ "PARTNERS" ИЗ РАСЧЕТА НАГРУЗКИ
                 </label>
            </form>
            <form method="POST" style="margin-top: 18px;">
                <div class="form-group">
                    <label>Whitelist Telegram для кнопки "✅ Принято"</label>
                    <textarea name="telegram_ack_whitelist_text" rows="6" placeholder="username - комментарий"><?= htmlspecialchars($telegramAckWhitelistText) ?></textarea>
                    <div style="color:#65676b; font-size: 13px; margin-top: 8px;">
                        Формат: <b>username - комментарий</b>. Можно без комментария. Без @.
                    </div>
                </div>
                <button type="submit" name="save_tg_whitelist">Сохранить whitelist</button>
            </form>
        </div>

        <div class="card">
            <h3>Codemeal: доступ к заказам</h3>
            <form method="POST">
                <div class="settings-grid" style="grid-template-columns: 1fr 1fr;">
                    <div class="form-group">
                        <label>Client number</label>
                        <input name="codemeal_client_number" value="<?= htmlspecialchars((string)($codemealSettings['codemeal_client_number'] ?? '')) ?>" placeholder="922371">
                    </div>
                    <div class="form-group">
                        <label>Authorization</label>
                        <input type="password" name="codemeal_auth" value="" placeholder="<?= htmlspecialchars($codemealAuthMasked !== '' ? 'сохранено: ' . $codemealAuthMasked : '922371:...') ?>">
                        <div class="muted" style="margin-top:6px;">Если оставить пустым — сохранённое значение не изменится.</div>
                    </div>
                </div>
                <div class="settings-grid" style="grid-template-columns: 1fr 1fr; margin-top: 10px;">
                    <div class="form-group">
                        <label>Locale</label>
                        <input name="codemeal_locale" value="<?= htmlspecialchars((string)($codemealSettings['codemeal_locale'] ?? 'en')) ?>" placeholder="en">
                    </div>
                    <div class="form-group">
                        <label>Timezone</label>
                        <input name="codemeal_timezone" value="<?= htmlspecialchars((string)($codemealSettings['codemeal_timezone'] ?? 'Asia/Ho_Chi_Minh')) ?>" placeholder="Asia/Ho_Chi_Minh">
                    </div>
                </div>
                <button type="submit" name="save_codemeal">Сохранить Codemeal</button>
            </form>
        </div>

        <div class="card">
            <h3>Управление доступом</h3>
            <form method="POST" class="form-group">
                <label>Добавить новый email</label>
                <div style="display:flex; gap:10px;">
                    <input type="email" name="email" placeholder="example@gmail.com" required>
                    <button type="submit" name="add_email">Добавить</button>
                </div>
            </form>

            <table>
                <thead>
                    <tr>
                        <th>Email</th>
                        <th>Дата добавления</th>
                        <th>Действие</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): ?>
                    <tr>
                        <td><?= htmlspecialchars($user['email']) ?></td>
                        <td><?= date('d.m.Y H:i', strtotime($user['created_at'])) ?></td>
                        <td>
                            <?php
                                $rawPerms = (string)($user['permissions_json'] ?? '');
                                $perms = $rawPerms !== '' ? json_decode($rawPerms, true) : null;
                                if (!is_array($perms)) $perms = null;
                            ?>
                            <button type="button" class="perm-gear" data-email="<?= htmlspecialchars($user['email'], ENT_QUOTES) ?>" data-perms="<?= htmlspecialchars(json_encode($perms, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>">⚙</button>
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
            <div class="perm-modal" id="permModal" style="display:none;">
                <div class="perm-modal-backdrop"></div>
                <div class="perm-modal-card">
                    <div class="perm-modal-title">Права доступа</div>
                    <form method="POST" id="permForm">
                        <input type="hidden" name="save_user_permissions" value="1">
                        <input type="hidden" name="perm_email" id="permEmail" value="">
                        <div class="perm-list">
                            <?php foreach ($permissionKeys as $k => $label): ?>
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

        <div class="description-card">
            <h4>Логика работы уведомлений в Telegram:</h4>
            <ul>
                <li><strong>Каждые 5 минут (cron):</strong> Скрипт сначала очищает старые алерты, затем отправляет актуальные.</li>
                <li><strong>ChefAssistant:</strong> Синхронизация временно отключена, данные по нему не используются.</li>
                <li><strong>Очистка:</strong> Для всех сообщений с tg_message_id за последние 2 часа (по ticket_sent_at) проверяется актуальность. Если блюдо готово/удалено/позиция исключена/чек закрыт/✅ Принято — бот удаляет сообщение и очищает tg_message_id.</li>
                <li><strong>Динамический тайминг:</strong> Лимит ожидания зависит от нагрузки. Если открыто меньше <b><?= $settings['alert_load_threshold'] ?></b> чеков — <b><?= $settings['alert_timing_low_load'] ?> мин</b>, иначе — <b><?= $settings['alert_timing_high_load'] ?> мин</b>. Если включено "ИСКЛЮЧИТЬ СТОЛ PARTNERS", он не влияет на нагрузку, но отображается в заголовке отдельно (напр. 9+4).</li>
                <li><strong>Кандидаты на алерт:</strong> Берутся только позиции с ticket_sent_at, которые старше лимита, status=1, ready_pressed_at IS NULL и tg_acknowledged=0.</li>
                <li><strong>Проверка через Poster:</strong> Перед отправкой по каждой позиции проверяется, что чек всё ещё открыт, и по истории чека определяется готовность (finishedcooking) и удаление позиции (delete/deleteitem или changeitemcount=0).</li>
                <li><strong>Обновление текста:</strong> Если по позиции уже было сообщение, бот удаляет старое и отправляет новое с актуальным временем ожидания.</li>
                <li><strong>Подтверждение (✅ Принято):</strong> Нажатие временно отключает алерты на <b><?= (int)$settings['alert_ack_snooze_minutes'] ?></b> минут. Подтверждение применяется ко всем дублям этой позиции в чеке (transaction_date + transaction_id + dish_id + station).</li>
                <li><strong>Контроль доступа к ✅ Принято:</strong> Нажатия обрабатываются только от Telegram username из whitelist выше. Если username не в списке — показывается ответ "Эта кнопка только для уважаемых людей" и ничего в БД не меняется.</li>
                <li><strong>Надёжность удаления:</strong> tg_message_id очищается только если Telegram подтвердил удаление (ok=true). Если удалить не удалось — будет повторная попытка в следующих запусках.</li>
            </ul>
        </div>
        <?php else: ?>
        <div class="card">
            <div class="menu-actions">
                <div class="left">
                    <h3 style="margin:0;">Меню</h3>
                    <form method="POST" style="margin:0;">
                        <button type="submit" name="sync_menu" title="Загружает блюда и категории из Poster, обновляет цены/станции/категории и помечает отсутствующие позиции как неактивные.">Обновить меню из Poster</button>
                    </form>
                    <form method="POST" style="margin:0;">
                        <button type="submit" name="autofill_menu" title="Автоматически заполняет/исправляет названия и описания RU/EN/VN по правилам и шаблонам. Не меняет данные Poster.">Автозаполнить названия/описания</button>
                    </form>
                    <?php if (!empty($menuSyncMeta['last_sync_at'])): ?>
                        <span class="muted">Последняя синхронизация: <span class="js-local-dt" data-iso="<?= htmlspecialchars($menuSyncAtIso) ?>"><?= htmlspecialchars($menuSyncMeta['last_sync_at']) ?></span></span>
                    <?php endif; ?>
                </div>
                <div class="right">
                    <a href="admin.php?tab=menu&view=list" style="text-decoration:none; font-weight:600; color:#1a73e8;">Список блюд</a>
                    <a href="admin.php?tab=menu&view=categories" style="text-decoration:none; font-weight:600; color:#1a73e8;">Категории</a>
                </div>
            </div>
            <?php if (!empty($menuSyncMeta['last_sync_error'])): ?>
                <div style="margin-top:12px;" class="error"><?= htmlspecialchars($menuSyncMeta['last_sync_error']) ?></div>
            <?php endif; ?>

            <?php if ($menuView === 'categories'): ?>
                <form method="POST" style="margin-top: 18px;">
                    <h4 style="margin: 0 0 10px;">Основные категории</h4>
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
                            <?php foreach ($menuCategoriesMain as $c): ?>
                                <tr>
                                    <td><?= (int)$c['poster_main_category_id'] ?></td>
                                    <td><?= htmlspecialchars($c['name_raw']) ?></td>
                                    <td><input name="main_tr[<?= (int)$c['id'] ?>][ru]" value="<?= htmlspecialchars($c['name_ru'] ?? '') ?>" /></td>
                                    <td><input name="main_tr[<?= (int)$c['id'] ?>][en]" value="<?= htmlspecialchars($c['name_en'] ?? '') ?>" /></td>
                                    <td><input name="main_tr[<?= (int)$c['id'] ?>][vn]" value="<?= htmlspecialchars($c['name_vn'] ?? '') ?>" /></td>
                                    <td><input name="main_tr[<?= (int)$c['id'] ?>][ko]" value="<?= htmlspecialchars($c['name_ko'] ?? '') ?>" /></td>
                                    <?php $cnt = (int)($mainItemCounts[(int)$c['id']] ?? 0); ?>
                                    <td style="width:80px; text-align:right;"><?= $cnt ?></td>
                                    <td style="width:110px; text-align:center;">
                                        <input type="checkbox" name="main_show[<?= (int)$c['id'] ?>]" value="1" <?= !empty($c['show_in_menu']) ? 'checked' : '' ?>>
                                    </td>
                                    <td style="width:90px;"><input type="number" name="main_sort[<?= (int)$c['id'] ?>]" value="<?= (int)$c['sort_order'] ?>" /></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>

                    <h4 style="margin: 18px 0 10px;">Подкатегории</h4>
                    <div class="table-wrap">
                    <table class="menu-table">
                        <thead>
                            <tr>
                                <th>Poster ID</th>
                                <th>Raw</th>
                                <th>Категория</th>
                                <th>RU</th>
                                <th>EN</th>
                                <th>VN</th>
                                <th>KO</th>
                                <th>Отображать</th>
                                <th>Sort</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($menuCategoriesSub as $c): ?>
                                <tr>
                                    <td><?= (int)$c['poster_sub_category_id'] ?></td>
                                    <td><?= htmlspecialchars($c['name_raw']) ?></td>
                                    <td style="min-width: 220px;">
                                        <select name="sub_parent[<?= (int)$c['id'] ?>]">
                                            <option value="">—</option>
                                            <?php foreach ($menuCategoriesMain as $m): ?>
                                                <?php
                                                    $mid = (int)$m['id'];
                                                    $mname = $stripNumberPrefix((string)($m['name_ru'] ?? $m['name_raw']));
                                                ?>
                                                <option value="<?= $mid ?>" <?= (int)($c['main_category_id_override'] ?? 0) === $mid ? 'selected' : '' ?>><?= htmlspecialchars($mname) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td><input name="sub_tr[<?= (int)$c['id'] ?>][ru]" value="<?= htmlspecialchars($c['name_ru'] ?? '') ?>" /></td>
                                    <td><input name="sub_tr[<?= (int)$c['id'] ?>][en]" value="<?= htmlspecialchars($c['name_en'] ?? '') ?>" /></td>
                                    <td><input name="sub_tr[<?= (int)$c['id'] ?>][vn]" value="<?= htmlspecialchars($c['name_vn'] ?? '') ?>" /></td>
                                    <td><input name="sub_tr[<?= (int)$c['id'] ?>][ko]" value="<?= htmlspecialchars($c['name_ko'] ?? '') ?>" /></td>
                                    <td style="width:110px; text-align:center;">
                                        <input type="checkbox" name="sub_show[<?= (int)$c['id'] ?>]" value="1" <?= !empty($c['show_in_menu']) ? 'checked' : '' ?>>
                                    </td>
                                    <td style="width:90px;"><input type="number" name="sub_sort[<?= (int)$c['id'] ?>]" value="<?= (int)$c['sort_order'] ?>" /></td>
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
                    <a href="admin.php?tab=menu&view=list" style="text-decoration:none; font-weight:600; color:#1a73e8;">← Назад к списку</a>
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
                        $ruMainSelected = (int)($menuEdit['ru_main_category_id'] ?? 0);
                        $ruSubSelected = (int)($menuEdit['ru_sub_category_id'] ?? 0);
                        $enMainSelected = (int)($menuEdit['en_main_category_id'] ?? $ruMainSelected);
                        $enSubSelected = (int)($menuEdit['en_sub_category_id'] ?? $ruSubSelected);
                        $vnMainSelected = (int)($menuEdit['vn_main_category_id'] ?? $ruMainSelected);
                        $vnSubSelected = (int)($menuEdit['vn_sub_category_id'] ?? $ruSubSelected);
                        $koMainSelected = (int)($menuEdit['ko_main_category_id'] ?? $enMainSelected);
                        $koSubSelected = (int)($menuEdit['ko_sub_category_id'] ?? $enSubSelected);
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
                        <div class="settings-grid" style="grid-template-columns: 2fr 1fr 1fr; margin-top: 10px;">
                            <div class="form-group">
                                <label>Картинка (Image URL)</label>
                                <input name="ru_image_url" value="<?= htmlspecialchars((string)($menuEdit['ru_image_url'] ?? '')) ?>" />
                            </div>
                            <div class="form-group">
                                <label>Порядок сортировки</label>
                                <input type="number" name="ru_sort_order" value="<?= (int)($menuEdit['ru_sort_order'] ?? 0) ?>" />
                            </div>
                            <div class="form-group">
                                <label style="display:block;">Опубликовано</label>
                                <label style="display:flex; align-items:center; gap:8px; font-size: 14px; margin-top: 10px;">
                                    <input type="checkbox" name="ru_is_published" value="1" <?= !empty($menuEdit['ru_is_published']) && (int)$menuEdit['is_active'] === 1 ? 'checked' : '' ?> <?= (int)$menuEdit['is_active'] === 1 ? '' : 'disabled' ?>>
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
                                <label>Категория</label>
                                <select name="ru_main_category_id" class="main-cat" data-lang="ru">
                                    <option value="">—</option>
                                    <?php foreach ($menuCategoriesMain as $c): ?>
                                        <?php $id = (int)$c['id']; $name = $stripNumberPrefix((string)($c['name_ru'] ?? $c['name_raw'])); ?>
                                        <option value="<?= $id ?>" <?= $ruMainSelected === $id ? 'selected' : '' ?>><?= htmlspecialchars($name) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Подкатегория</label>
                                <select name="ru_sub_category_id" class="sub-cat" data-lang="ru">
                                    <option value="">—</option>
                                    <?php foreach ($menuCategoriesSub as $c): ?>
                                        <?php $id = (int)$c['id']; $name = $stripNumberPrefix((string)($c['name_ru'] ?? $c['name_raw'])); $mainId = (int)($c['main_category_id_override'] ?? 0); if ($mainId <= 0) $mainId = (int)($c['main_category_id'] ?? 0); ?>
                                        <option value="<?= $id ?>" data-main="<?= $mainId ?>" <?= $ruSubSelected === $id ? 'selected' : '' ?>><?= htmlspecialchars($name) ?></option>
                                    <?php endforeach; ?>
                                </select>
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
                                <label>Category</label>
                                <select name="en_main_category_id" class="main-cat" data-lang="en">
                                    <option value="">—</option>
                                    <?php foreach ($menuCategoriesMain as $c): ?>
                                        <?php $id = (int)$c['id']; $name = $stripNumberPrefix((string)($c['name_en'] ?? $c['name_raw'])); ?>
                                        <option value="<?= $id ?>" <?= $enMainSelected === $id ? 'selected' : '' ?>><?= htmlspecialchars($name) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Subcategory</label>
                                <select name="en_sub_category_id" class="sub-cat" data-lang="en">
                                    <option value="">—</option>
                                    <?php foreach ($menuCategoriesSub as $c): ?>
                                        <?php $id = (int)$c['id']; $name = $stripNumberPrefix((string)($c['name_en'] ?? $c['name_raw'])); $mainId = (int)($c['main_category_id_override'] ?? 0); if ($mainId <= 0) $mainId = (int)($c['main_category_id'] ?? 0); ?>
                                        <option value="<?= $id ?>" data-main="<?= $mainId ?>" <?= $enSubSelected === $id ? 'selected' : '' ?>><?= htmlspecialchars($name) ?></option>
                                    <?php endforeach; ?>
                                </select>
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
                                <label>Danh mục</label>
                                <select name="vn_main_category_id" class="main-cat" data-lang="vn">
                                    <option value="">—</option>
                                    <?php foreach ($menuCategoriesMain as $c): ?>
                                        <?php $id = (int)$c['id']; $name = $stripNumberPrefix((string)($c['name_vn'] ?? $c['name_raw'])); ?>
                                        <option value="<?= $id ?>" <?= $vnMainSelected === $id ? 'selected' : '' ?>><?= htmlspecialchars($name) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Danh mục con</label>
                                <select name="vn_sub_category_id" class="sub-cat" data-lang="vn">
                                    <option value="">—</option>
                                    <?php foreach ($menuCategoriesSub as $c): ?>
                                        <?php $id = (int)$c['id']; $name = $stripNumberPrefix((string)($c['name_vn'] ?? $c['name_raw'])); $mainId = (int)($c['main_category_id_override'] ?? 0); if ($mainId <= 0) $mainId = (int)($c['main_category_id'] ?? 0); ?>
                                        <option value="<?= $id ?>" data-main="<?= $mainId ?>" <?= $vnSubSelected === $id ? 'selected' : '' ?>><?= htmlspecialchars($name) ?></option>
                                    <?php endforeach; ?>
                                </select>
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
                                <label>카테고리</label>
                                <select name="ko_main_category_id" class="main-cat" data-lang="ko">
                                    <option value="">—</option>
                                    <?php foreach ($menuCategoriesMain as $c): ?>
                                        <?php $id = (int)$c['id']; $name = $stripNumberPrefix((string)($c['name_ko'] ?? $c['name_en'] ?? $c['name_raw'])); ?>
                                        <option value="<?= $id ?>" <?= $koMainSelected === $id ? 'selected' : '' ?>><?= htmlspecialchars($name) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>하위 카테고리</label>
                                <select name="ko_sub_category_id" class="sub-cat" data-lang="ko">
                                    <option value="">—</option>
                                    <?php foreach ($menuCategoriesSub as $c): ?>
                                        <?php $id = (int)$c['id']; $name = $stripNumberPrefix((string)($c['name_ko'] ?? $c['name_en'] ?? $c['name_raw'])); $mainId = (int)($c['main_category_id_override'] ?? 0); if ($mainId <= 0) $mainId = (int)($c['main_category_id'] ?? 0); ?>
                                        <option value="<?= $id ?>" data-main="<?= $mainId ?>" <?= $koSubSelected === $id ? 'selected' : '' ?>><?= htmlspecialchars($name) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>설명</label>
                                <textarea name="ko_description" rows="8"><?= htmlspecialchars((string)($menuEdit['ko_description'] ?? '')) ?></textarea>
                            </div>
                        </div>
                    </div>

                    <script>
                        (() => {
                            const filterSub = (lang) => {
                                const main = document.querySelector(`select.main-cat[data-lang="${lang}"]`);
                                const sub = document.querySelector(`select.sub-cat[data-lang="${lang}"]`);
                                if (!main || !sub) return;
                                const mainId = parseInt(main.value || '0', 10);
                                const prev = sub.value;
                                let hasPrev = false;
                                Array.from(sub.options).forEach((opt) => {
                                    if (!opt.value) return;
                                    const optMain = parseInt(opt.getAttribute('data-main') || '0', 10);
                                    const show = mainId === 0 || optMain === 0 || optMain === mainId;
                                    opt.hidden = !show;
                                    if (opt.value === prev && show) hasPrev = true;
                                });
                                if (prev && !hasPrev) sub.value = '';
                            };
                            ['ru','en','vn','ko'].forEach((lang) => {
                                const main = document.querySelector(`select.main-cat[data-lang="${lang}"]`);
                                if (main) {
                                    main.addEventListener('change', () => filterSub(lang));
                                    filterSub(lang);
                                }
                            });
                        })();
                    </script>
                    <div style="margin-top: 14px;">
                        <button type="submit" name="save_menu_item">Сохранить блюдо</button>
                    </div>
                </form>
            <?php else: ?>
                <?php
                    $filterMain = ($_GET['main_category_id'] ?? '') !== '' ? (int)$_GET['main_category_id'] : null;
                    $filterSub = ($_GET['sub_category_id'] ?? '') !== '' ? (int)$_GET['sub_category_id'] : null;
                    $filterQ = trim((string)($_GET['q'] ?? ''));
                    $filterStatus = trim((string)($_GET['status'] ?? ''));
                    $sort = strtolower(trim((string)($_GET['sort'] ?? 'main_sort')));
                    if ($sort === 'station') {
                        $sort = 'poster_category';
                    }
                    $dir = strtolower(trim((string)($_GET['dir'] ?? 'asc')));
                    if (!in_array($dir, ['asc', 'desc'], true)) {
                        $dir = 'asc';
                    }
                    $colsParam = trim((string)($_GET['cols'] ?? ''));
                    $colsHidden = $colsParam !== '' ? $colsParam : '';
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
                        'poster_category' => ['label' => 'Станция Poster', 'default' => true],
                        'poster_subcategory' => ['label' => 'Категория Poster', 'default' => true],
                        'adapted_category_ru' => ['label' => 'Категория адапт. RU', 'default' => false],
                        'adapted_category_en' => ['label' => 'Категория адапт. EN', 'default' => false],
                        'adapted_category_vn' => ['label' => 'Категория адапт. VN', 'default' => false],
                        'adapted_category_ko' => ['label' => 'Категория адапт. KO', 'default' => false],
                        'adapted_subcategory_ru' => ['label' => 'Подкатегория адапт. RU', 'default' => false],
                        'adapted_subcategory_en' => ['label' => 'Подкатегория адапт. EN', 'default' => false],
                        'adapted_subcategory_vn' => ['label' => 'Подкатегория адапт. VN', 'default' => false],
                        'adapted_subcategory_ko' => ['label' => 'Подкатегория адапт. KO', 'default' => false],
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
                        <label>Категория</label>
                        <select name="main_category_id">
                            <option value="">Все</option>
                            <?php foreach ($menuCategoriesMain as $c): ?>
                                <?php $id = (int)$c['id']; $name = $stripNumberPrefix((string)($c['name_ru'] ?? $c['name_raw'])); ?>
                                <option value="<?= $id ?>" <?= $filterMain === $id ? 'selected' : '' ?>><?= htmlspecialchars($name) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Подкатегория</label>
                        <select name="sub_category_id">
                            <option value="">Все</option>
                            <?php foreach ($menuCategoriesSub as $c): ?>
                                <?php $id = (int)$c['id']; $name = $stripNumberPrefix((string)($c['name_ru'] ?? $c['name_raw'])); ?>
                                <option value="<?= $id ?>" <?= $filterSub === $id ? 'selected' : '' ?>><?= htmlspecialchars($name) ?></option>
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
                        <a href="admin.php?tab=menu&view=list" style="text-decoration:none; color:#666; font-weight:600;">Сбросить</a>
                        <span class="muted">Всего: <?= (int)$menuTotal ?></span>
                    </div>
                </form>

                <details style="margin-top: 12px;">
                    <summary style="cursor:pointer; font-weight:700; color:#1a73e8;">Поля таблицы</summary>
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
                            <th data-col="poster_category"><a class="sort-link" href="<?= htmlspecialchars($buildSortHref('poster_category')) ?>">Станция Poster <span class="sort-arrow"><?= $sortArrow('poster_category') ?></span></a></th>
                            <th data-col="poster_subcategory"><a class="sort-link" href="<?= htmlspecialchars($buildSortHref('poster_subcategory')) ?>">Категория Poster <span class="sort-arrow"><?= $sortArrow('poster_subcategory') ?></span></a></th>
                            <th data-col="adapted_category_ru"><a class="sort-link" href="<?= htmlspecialchars($buildSortHref('adapted_category_ru')) ?>">Категория адапт. RU <span class="sort-arrow"><?= $sortArrow('adapted_category_ru') ?></span></a></th>
                            <th data-col="adapted_category_en"><a class="sort-link" href="<?= htmlspecialchars($buildSortHref('adapted_category_en')) ?>">Категория адапт. EN <span class="sort-arrow"><?= $sortArrow('adapted_category_en') ?></span></a></th>
                            <th data-col="adapted_category_vn"><a class="sort-link" href="<?= htmlspecialchars($buildSortHref('adapted_category_vn')) ?>">Категория адапт. VN <span class="sort-arrow"><?= $sortArrow('adapted_category_vn') ?></span></a></th>
                            <th data-col="adapted_category_ko"><a class="sort-link" href="<?= htmlspecialchars($buildSortHref('adapted_category_ko')) ?>">Категория адапт. KO <span class="sort-arrow"><?= $sortArrow('adapted_category_ko') ?></span></a></th>
                            <th data-col="adapted_subcategory_ru"><a class="sort-link" href="<?= htmlspecialchars($buildSortHref('adapted_subcategory_ru')) ?>">Подкатегория адапт. RU <span class="sort-arrow"><?= $sortArrow('adapted_subcategory_ru') ?></span></a></th>
                            <th data-col="adapted_subcategory_en"><a class="sort-link" href="<?= htmlspecialchars($buildSortHref('adapted_subcategory_en')) ?>">Подкатегория адапт. EN <span class="sort-arrow"><?= $sortArrow('adapted_subcategory_en') ?></span></a></th>
                            <th data-col="adapted_subcategory_vn"><a class="sort-link" href="<?= htmlspecialchars($buildSortHref('adapted_subcategory_vn')) ?>">Подкатегория адапт. VN <span class="sort-arrow"><?= $sortArrow('adapted_subcategory_vn') ?></span></a></th>
                            <th data-col="adapted_subcategory_ko"><a class="sort-link" href="<?= htmlspecialchars($buildSortHref('adapted_subcategory_ko')) ?>">Подкатегория адапт. KO <span class="sort-arrow"><?= $sortArrow('adapted_subcategory_ko') ?></span></a></th>
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
                                $posterSubCategory = $stripNumberPrefix((string)($it['main_category_name'] ?? ''));
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
                                <td data-col="poster_category"><?= htmlspecialchars($posterStation) ?></td>
                                <td data-col="poster_subcategory"><?= htmlspecialchars($posterSubCategory) ?></td>
                                <td data-col="adapted_category_ru"><?= htmlspecialchars((string)($it['adapted_category_ru'] ?? '')) ?></td>
                                <td data-col="adapted_category_en"><?= htmlspecialchars((string)($it['adapted_category_en'] ?? '')) ?></td>
                                <td data-col="adapted_category_vn"><?= htmlspecialchars((string)($it['adapted_category_vn'] ?? '')) ?></td>
                                <td data-col="adapted_category_ko"><?= htmlspecialchars((string)($it['adapted_category_ko'] ?? '')) ?></td>
                                <td data-col="adapted_subcategory_ru"><?= htmlspecialchars((string)($it['adapted_subcategory_ru'] ?? '')) ?></td>
                                <td data-col="adapted_subcategory_en"><?= htmlspecialchars((string)($it['adapted_subcategory_en'] ?? '')) ?></td>
                                <td data-col="adapted_subcategory_vn"><?= htmlspecialchars((string)($it['adapted_subcategory_vn'] ?? '')) ?></td>
                                <td data-col="adapted_subcategory_ko"><?= htmlspecialchars((string)($it['adapted_subcategory_ko'] ?? '')) ?></td>
                                <td data-col="status"><?= implode(' ', $statusPills) ?></td>
                                <td>
                                    <input type="checkbox"
                                           class="publish-toggle"
                                           data-poster-id="<?= (int)$it['poster_id'] ?>"
                                           <?= $hideChecked ? 'checked' : '' ?>
                                           <?= !$isActive ? 'disabled' : '' ?>>
                                </td>
                                <td>
                                    <a href="admin.php?tab=menu&view=edit&poster_id=<?= (int)$it['poster_id'] ?>" style="text-decoration:none; color:#1a73e8; font-weight:600;">Редактировать</a>
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
    <script>
        (() => {
            const bar = document.createElement('div');
            bar.className = 'sticky-hscroll-bar';
            bar.style.display = 'none';
            bar.innerHTML = '<div class="sticky-hscroll"><div class="sticky-hscroll-viewport"><div class="sticky-hscroll-content"></div></div></div>';
            document.body.appendChild(bar);

            const viewport = bar.querySelector('.sticky-hscroll-viewport');
            const content = bar.querySelector('.sticky-hscroll-content');

            let target = null;
            let syncingFromViewport = false;
            let syncingFromTarget = false;
            let ro = null;
            let raf = 0;

            const pickTarget = () => {
                const wraps = Array.from(document.querySelectorAll('.table-wrap'));
                let best = null;
                let bestW = 0;
                for (const w of wraps) {
                    const sw = w.scrollWidth || 0;
                    if (sw > bestW) {
                        bestW = sw;
                        best = w;
                    }
                }
                return best;
            };

            const update = () => {
                if (!target) {
                    bar.style.display = 'none';
                    document.body.style.paddingBottom = '';
                    return;
                }
                const needs = (target.scrollWidth - target.clientWidth) > 2;
                bar.style.display = needs ? '' : 'none';
                document.body.style.paddingBottom = needs ? '46px' : '';
                if (!needs) return;
                content.style.width = target.scrollWidth + 'px';
                viewport.scrollLeft = target.scrollLeft;
            };

            const scheduleUpdate = () => {
                if (raf) cancelAnimationFrame(raf);
                raf = requestAnimationFrame(update);
            };

            const attach = () => {
                const next = pickTarget();
                if (next === target) {
                    scheduleUpdate();
                    return;
                }
                if (target) {
                    target.removeEventListener('scroll', onTargetScroll);
                }
                if (ro) {
                    ro.disconnect();
                    ro = null;
                }
                target = next;
                if (!target) {
                    update();
                    return;
                }
                target.addEventListener('scroll', onTargetScroll, { passive: true });
                if (window.ResizeObserver) {
                    ro = new ResizeObserver(() => scheduleUpdate());
                    ro.observe(target);
                    const table = target.querySelector('table');
                    if (table) ro.observe(table);
                }
                scheduleUpdate();
            };

            const onTargetScroll = () => {
                if (syncingFromViewport) return;
                syncingFromTarget = true;
                viewport.scrollLeft = target ? target.scrollLeft : 0;
                syncingFromTarget = false;
            };

            viewport.addEventListener('scroll', () => {
                if (!target) return;
                if (syncingFromTarget) return;
                syncingFromViewport = true;
                target.scrollLeft = viewport.scrollLeft;
                syncingFromViewport = false;
            }, { passive: true });

            window.addEventListener('resize', scheduleUpdate);

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', attach);
            } else {
                attach();
            }
            setTimeout(attach, 0);
        })();

        (() => {
            const els = Array.from(document.querySelectorAll('.js-local-dt'));
            if (els.length === 0) return;
            els.forEach((el) => {
                const iso = (el.getAttribute('data-iso') || '').trim();
                if (!iso) return;
                const d = new Date(iso);
                if (Number.isNaN(d.getTime())) return;
                el.textContent = d.toLocaleString();
            });
        })();

        (() => {
            const modal = document.getElementById('permModal');
            const form = document.getElementById('permForm');
            const emailEl = document.getElementById('permEmail');
            const cancel = document.getElementById('permCancel');
            if (!modal || !form || !emailEl || !cancel) return;

            const defaultPerms = {
                dashboard: true,
                rawdata: true,
                kitchen_online: true,
                admin: true,
                exclude_toggle: true,
            };

            const close = () => { modal.style.display = 'none'; };
            const open = (email, perms) => {
                emailEl.value = email;
                const p = Object.assign({}, defaultPerms, perms || {});
                Object.keys(defaultPerms).forEach((k) => {
                    const cb = document.getElementById('perm_' + k);
                    if (cb) cb.checked = !!p[k];
                });
                modal.style.display = 'flex';
            };

            document.addEventListener('click', (e) => {
                const btn = e.target.closest('.perm-gear');
                if (!btn) return;
                const email = btn.getAttribute('data-email') || '';
                let perms = null;
                try { perms = JSON.parse(btn.getAttribute('data-perms') || 'null'); } catch (_) { perms = null; }
                open(email, perms);
            });
            modal.addEventListener('click', (e) => {
                if (e.target.classList.contains('perm-modal-backdrop')) close();
            });
            cancel.addEventListener('click', close);
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') close();
            });
        })();

        (() => {
            const menu = document.querySelector('.user-menu');
            if (!menu) return;
            let t = null;
            const open = () => {
                if (t) { clearTimeout(t); t = null; }
                menu.classList.add('open');
            };
            const scheduleClose = () => {
                if (t) clearTimeout(t);
                t = setTimeout(() => {
                    menu.classList.remove('open');
                    t = null;
                }, 1000);
            };
            menu.addEventListener('mouseenter', open);
            menu.addEventListener('mouseleave', scheduleClose);
        })();
    </script>
</body>
</html>
