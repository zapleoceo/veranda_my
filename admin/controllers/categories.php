<?php
$usersTable = $db->t('users');
$metaTable = $db->t('system_meta');
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
