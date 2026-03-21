<?php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/src/classes/PosterAPI.php';
require_once __DIR__ . '/src/classes/PosterMenuSync.php';
require_once __DIR__ . '/src/classes/MenuAutoFill.php';
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
if (!in_array($tab, ['sync', 'access', 'telegram', 'menu', 'categories'], true)) {
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

$permissionKeys = [
    'dashboard' => 'Дашборд',
    'rawdata' => 'Сырые данные',
    'kitchen_online' => 'КухняOnline',
    'admin' => 'УПРАВЛЕНИЕ',
    'exclude_toggle' => 'Кнопка «Игнор»',
    'telegram_ack' => '✅ Принято (Telegram)',
];

if (isset($_POST['save_user_permissions'])) {
    $targetEmail = trim((string)($_POST['perm_email'] ?? ''));
    if ($targetEmail !== '') {
        $perms = [];
        foreach ($permissionKeys as $k => $_label) {
            $perms[$k] = isset($_POST['perm_' . $k]) ? 1 : 0;
        }
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
            $db->query("INSERT INTO {$usersTable} (email) VALUES (?)", [$email]);
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
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f0f2f5; padding: 0; color: #1c1e21; }
        .container { width: 100%; max-width: 1800px; margin: 0 auto; padding: 12px; box-sizing: border-box; }
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
        .top-nav { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; gap: 16px; flex-wrap: wrap; }
        .nav-left { display: flex; gap: 14px; flex-wrap: wrap; align-items: center; min-width: 0; }
        .nav-left a { color: #1a73e8; text-decoration: none; font-weight: 500; }
        .nav-left a:hover { text-decoration: underline; }
        .nav-title { font-weight: 800; color: #2c3e50; }
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
        .info-icon { display:inline-flex; align-items:center; justify-content:center; width:18px; height:18px; border-radius:50%; border:1px solid #cbd5e1; color:#1a73e8; font-weight:800; font-size:12px; cursor:help; background:#fff; }
    </style>
</head>
<body>
    <div class="container">
        <div class="top-nav">
            <div class="nav-left"><div class="nav-title">Управление</div></div>
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
        <?php if ($message): ?><div class="success"><?= $message ?></div><?php endif; ?>
        <?php if ($error): ?><div class="error"><?= $error ?></div><?php endif; ?>

        <div class="tab-links">
            <a href="admin.php?tab=sync" class="<?= $tab === 'sync' ? 'active' : '' ?>">Синки</a>
            <a href="admin.php?tab=access" class="<?= $tab === 'access' ? 'active' : '' ?>">Доступы</a>
            <a href="admin.php?tab=telegram" class="<?= $tab === 'telegram' ? 'active' : '' ?>">Telegram</a>
            <a href="admin.php?tab=menu" class="<?= $tab === 'menu' ? 'active' : '' ?>">Меню</a>
            <a href="admin.php?tab=categories" class="<?= $tab === 'categories' ? 'active' : '' ?>">Категории</a>
            <a href="logs.php">Логи</a>
        </div>
        <?php if ($tab === 'sync'): ?>
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
                        'desc' => 'Отправляет/обновляет уведомления в Telegram по долгим позициям. Снимает уведомления после готовности/закрытия/игнора.',
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
                ?>
                <form method="post" style="margin-top: 12px; display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end;">
                    <input type="hidden" name="run_script" value="1">
                    <div class="form-group" style="flex:1; min-width:220px; margin-bottom:0;">
                        <label for="script_name">Скрипт</label>
                        <select name="script_name" id="script_name">
                            <option value="kitchen_cron" data-desc="cron.php — синк кухни за сегодня (kitchen_stats), обновляет Kitchen Online / Dashboard / Rawdata.">Кухня: синк за сегодня</option>
                            <option value="kitchen_resync_range" data-desc="scripts/kitchen/resync_range.php — пересинк кухни за диапазон дат (аккуратно).">Кухня: пересинк диапазон</option>
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
                        if ($script === 'kitchen_cron') {
                            $cmd = PHP_BINARY . ' ' . escapeshellarg(__DIR__ . '/cron.php');
                        } elseif ($script === 'kitchen_resync_range') {
                            $cmd = PHP_BINARY . ' ' . escapeshellarg(__DIR__ . '/scripts/kitchen/resync_range.php') . ' ' . escapeshellarg($dateFrom) . ' ' . escapeshellarg($dateTo);
                        } elseif ($script === 'kitchen_prob_close') {
                            $cmd = PHP_BINARY . ' ' . escapeshellarg(__DIR__ . '/scripts/kitchen/backfill_prob_close_at.php');
                        } elseif ($script === 'menu_cron') {
                            $cmd = PHP_BINARY . ' ' . escapeshellarg(__DIR__ . '/menu_cron.php');
                        } elseif ($script === 'tg_alerts') {
                            $cmd = PHP_BINARY . ' ' . escapeshellarg(__DIR__ . '/telegram_alerts.php');
                        }

                        if (!$canExec) {
                            echo '<div class="error" style="margin-top:12px;">exec() отключён — запустить нельзя.</div>';
                        } elseif ($cmd) {
                            $out = [];
                            $code = 0;
                            exec($cmd . ' 2>&1', $out, $code);
                            if (count($out) > 200) $out = array_slice($out, -200);
                            echo '<pre style="margin-top:12px; white-space:pre-wrap; word-break:break-word; background:#0b1020; color:#e5e7eb; padding:12px; border-radius:12px; overflow:auto; max-height:360px;">' . htmlspecialchars("exit={$code}\n" . implode("\n", $out)) . '</pre>';
                        } else {
                            echo '<div class="error">Неизвестный скрипт</div>';
                        }
                    }
                ?>
                <script>
                    (() => {
                        const sel = document.getElementById('script_name');
                        const out = document.getElementById('script_desc');
                        const upd = () => {
                            if (!sel || !out) return;
                            const opt = sel.options[sel.selectedIndex];
                            out.textContent = opt && opt.dataset && opt.dataset.desc ? opt.dataset.desc : '';
                        };
                        if (sel) sel.addEventListener('change', upd);
                        upd();
                    })();
                </script>
            </div>

        <?php elseif ($tab === 'telegram'): ?>
        <div class="card">
            <h3>Настройки уведомлений (Telegram)</h3>
            <form method="POST">
                <div class="settings-grid" style="grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); max-width: 760px;">
                    <div class="form-group">
                        <label>Тайминг до выс. нагрузки (мин)</label>
                        <input type="number" name="alert_timing_low_load" value="<?= $settings['alert_timing_low_load'] ?>" required style="max-width: 220px;">
                    </div>
                    <div class="form-group">
                        <label>Порог чеков для выс. нагрузки</label>
                        <input type="number" name="alert_load_threshold" value="<?= $settings['alert_load_threshold'] ?>" required style="max-width: 220px;">
                    </div>
                    <div class="form-group">
                        <label>Тайминг при выс. нагрузке (мин)</label>
                        <input type="number" name="alert_timing_high_load" value="<?= $settings['alert_timing_high_load'] ?>" required style="max-width: 220px;">
                    </div>
                    <div class="form-group">
                        <label>Повтор после "✅ Принято" (мин)</label>
                        <input type="number" name="alert_ack_snooze_minutes" value="<?= $settings['alert_ack_snooze_minutes'] ?>" required style="max-width: 220px;">
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
        </div>

        <div class="description-card">
            <h4>Логика работы уведомлений в Telegram:</h4>
            <ul>
                <li><strong>Каждые 5 минут (cron):</strong> Скрипт сначала очищает старые алерты, затем отправляет актуальные.</li>
                <li><strong>Очистка:</strong> Для всех сообщений с tg_message_id за последние 2 часа (по ticket_sent_at) проверяется актуальность. Если блюдо готово/удалено/позиция исключена/чек закрыт/✅ Принято — бот удаляет сообщение и очищает tg_message_id.</li>
                <li><strong>Динамический тайминг:</strong> Лимит ожидания зависит от нагрузки. Если открыто меньше <b><?= $settings['alert_load_threshold'] ?></b> чеков — <b><?= $settings['alert_timing_low_load'] ?> мин</b>, иначе — <b><?= $settings['alert_timing_high_load'] ?> мин</b>. Если включено "ИСКЛЮЧИТЬ СТОЛ PARTNERS", он не влияет на нагрузку, но отображается в заголовке отдельно (напр. 9+4).</li>
                <li><strong>Кандидаты на алерт:</strong> Берутся только позиции с ticket_sent_at, которые старше лимита, status=1, ready_pressed_at IS NULL и tg_acknowledged=0.</li>
                <li><strong>Проверка через Poster:</strong> Перед отправкой по каждой позиции проверяется, что чек всё ещё открыт, и по истории чека определяется готовность (finishedcooking) и удаление позиции (delete/deleteitem или changeitemcount=0).</li>
                <li><strong>Обновление текста:</strong> Если по позиции уже было сообщение, бот удаляет старое и отправляет новое с актуальным временем ожидания.</li>
                <li><strong>Подтверждение (✅ Принято):</strong> Нажатие временно отключает алерты на <b><?= (int)$settings['alert_ack_snooze_minutes'] ?></b> минут. Подтверждение применяется ко всем дублям этой позиции в чеке (transaction_date + transaction_id + dish_id + station).</li>
                <li><strong>Контроль доступа к ✅ Принято:</strong> Обрабатываются только от пользователей, у которых Telegram username указан в разделе "Доступы" и включено право "✅ Принято (Telegram)".</li>
                <li><strong>Надёжность удаления:</strong> tg_message_id очищается только если Telegram подтвердил удаление (ok=true). Если удалить не удалось — будет повторная попытка в следующих запусках.</li>
            </ul>
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

            <table>
                <thead>
                    <tr>
                        <th>Email</th>
                        <th>Telegram</th>
                        <th>Дата добавления</th>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): ?>
                    <tr>
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
            <div class="perm-modal" id="permModal" style="display:none;">
                <div class="perm-modal-backdrop"></div>
                <div class="perm-modal-card">
                    <div class="perm-modal-title">Права доступа</div>
                    <form method="POST" id="permForm">
                        <input type="hidden" name="save_user_permissions" value="1">
                        <input type="hidden" name="perm_email" id="permEmail" value="">
                        <div class="form-group" style="margin-bottom: 12px;">
                            <label style="font-size:12px; font-weight:800; text-transform:uppercase; color:#6b7280;">Telegram username</label>
                            <input type="text" name="perm_tg_username" id="permTgUsername" placeholder="например: zapleosoft">
                            <div class="muted" style="margin-top:6px;">Нужен для кнопки «ПРИНЯТО» в Telegram. Пиши без @.</div>
                        </div>
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
                    <a href="admin.php?tab=menu&export=csv" style="text-decoration:none; font-weight:600; color:#1a73e8;" title="Выгрузка CSV со всеми активными позициями и текущими переводами/категориями.">CSV меню</a>
                    <a href="admin.php?tab=menu&export=categories_csv" style="text-decoration:none; font-weight:600; color:#1a73e8;" title="Выгрузка CSV справочников цехов и категорий с переводами.">CSV категорий</a>
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
                                    <a href="admin.php?tab=menu&view=edit&poster_id=<?= (int)$it['poster_id'] ?>" style="text-decoration:none; color:#1a73e8; font-weight:800;" title="Редактировать">&#9998;</a>
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
            const fire = () => window.dispatchEvent(new Event('resize'));
            const kick = () => {
                requestAnimationFrame(() => {
                    fire();
                    requestAnimationFrame(fire);
                });
                setTimeout(fire, 200);
                setTimeout(fire, 800);
            };
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', kick, { once: true });
            } else {
                kick();
            }
            window.addEventListener('load', () => {
                fire();
                setTimeout(fire, 300);
            });
        })();

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
            const tgEl = document.getElementById('permTgUsername');
            const cancel = document.getElementById('permCancel');
            if (!modal || !form || !emailEl || !tgEl || !cancel) return;

            const defaultPerms = {
                dashboard: true,
                rawdata: true,
                kitchen_online: true,
                admin: true,
                exclude_toggle: true,
                telegram_ack: false,
            };

            const close = () => { modal.style.display = 'none'; };
            const open = (email, perms, tg) => {
                emailEl.value = email;
                tgEl.value = (tg || '').trim();
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
                const tg = btn.getAttribute('data-tg') || '';
                let perms = null;
                try { perms = JSON.parse(btn.getAttribute('data-perms') || 'null'); } catch (_) { perms = null; }
                open(email, perms, tg);
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
                }, 700);
            };
            menu.addEventListener('mouseenter', open);
            menu.addEventListener('mouseleave', scheduleClose);
        })();
    </script>
</body>
</html>
