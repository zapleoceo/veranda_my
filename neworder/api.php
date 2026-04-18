<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/../src/classes/Database.php';
require_once __DIR__ . '/../src/classes/PosterAPI.php';

$ajax = $_GET['ajax'] ?? '';
$wantsJson = true;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$respondOk = function(array $data = []) {
    echo json_encode(array_merge(['ok' => true], $data), JSON_UNESCAPED_UNICODE);
    exit;
};

$respondError = function(int $code, string $err) {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $err], JSON_UNESCAPED_UNICODE);
    exit;
};

if (file_exists(__DIR__ . '/../.env')) {
    $lines = file(__DIR__ . '/../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $t = trim($line);
        if ($t === '' || strpos($t, '#') === 0) continue;
        if (strpos($t, '=') === false) continue;
        [$name, $value] = explode('=', $line, 2);
        $_ENV[$name] = trim($value);
    }
}

$dbHost = trim((string)($_ENV['DB_HOST'] ?? ''));
$dbName = trim((string)($_ENV['DB_NAME'] ?? ''));
$dbUser = trim((string)($_ENV['DB_USER'] ?? ''));
$dbPass = (string)($_ENV['DB_PASS'] ?? '');
$dbSuffix = trim((string)($_ENV['DB_TABLE_SUFFIX'] ?? ''));

if ($dbHost === '' || $dbName === '' || $dbUser === '') {
    $respondError(500, 'Database credentials not configured');
}

$db = new \App\Classes\Database($dbHost, $dbName, $dbUser, $dbPass, $dbSuffix);
if (!$db) {
    $respondError(500, 'Database connection failed');
}

if ($ajax === 'get_menu') {
    $menuLang = 'ru';
    $trLang = 'ru';

    try {
        $db->createMenuTables();
    } catch (\Throwable $e) {}

    $metaTable = $db->t('system_meta');
    $pmi = $db->t('poster_menu_items');
    $mw = $db->t('menu_workshops');
    $mwTr = $db->t('menu_workshop_tr');
    $mc = $db->t('menu_categories');
    $mcTr = $db->t('menu_category_tr');
    $mi = $db->t('menu_items');
    $miTr = $db->t('menu_item_tr');

    $lastMenuSyncAt = null;
    try {
        $row = $db->query("SELECT meta_value FROM {$metaTable} WHERE meta_key = 'menu_last_sync_at' LIMIT 1")->fetch();
        if (is_array($row) && !empty($row['meta_value'])) $lastMenuSyncAt = (string)$row['meta_value'];
    } catch (\Throwable $e) {}

    try {
        $rows = $db->query(
            "SELECT
                w.id AS workshop_id,
                COALESCE(NULLIF(wtr.name,''), NULLIF(w.name_raw,''), '') AS main_label,
                c.id AS category_id,
                COALESCE(NULLIF(ctr.name,''), NULLIF(c.name_raw,''), '') AS sub_label,
                mi.id AS menu_item_id,
                p.poster_id,
                p.price_raw,
                COALESCE(NULLIF(itr.title,''), NULLIF(itr_ru.title,''), NULLIF(p.name_raw,''), '') AS title,
                COALESCE(NULLIF(itr_ru.title,''), NULLIF(p.name_raw,''), '') AS ru_title,
                COALESCE(NULLIF(itr.description,''), NULLIF(itr_ru.description,''), '') AS description,
                COALESCE(NULLIF(mi.image_url,''), '') AS image_url,
                COALESCE(mi.sort_order, 0) AS sort_order,
                COALESCE(w.sort_order, 0) AS main_sort,
                COALESCE(c.sort_order, 0) AS sub_sort
             FROM {$mi} mi
             JOIN {$pmi} p ON p.id = mi.poster_item_id AND p.is_active = 1
             JOIN {$mc} c ON c.id = mi.category_id AND c.show_on_site = 1
             JOIN {$mw} w ON w.id = c.workshop_id AND w.show_on_site = 1
             LEFT JOIN {$miTr} itr ON itr.item_id = mi.id AND itr.lang = ?
             LEFT JOIN {$miTr} itr_ru ON itr_ru.item_id = mi.id AND itr_ru.lang = 'ru'
             LEFT JOIN {$mcTr} ctr ON ctr.category_id = c.id AND ctr.lang = ?
             LEFT JOIN {$mwTr} wtr ON wtr.workshop_id = w.id AND wtr.lang = ?
             WHERE mi.is_published = 1
             ORDER BY
                w.sort_order ASC,
                main_label ASC,
                c.sort_order ASC,
                sub_label ASC,
                mi.sort_order ASC,
                title ASC",
            [$trLang, $trLang, $trLang]
        )->fetchAll();
    } catch (\Throwable $e) {
        $respondError(500, 'Menu query failed: ' . $e->getMessage());
    }

    $groups = [];
    foreach ($rows as $it) {
        if (!is_array($it)) continue;
        $mainLabel = trim((string)($it['main_label'] ?? ''));
        $subLabel = trim((string)($it['sub_label'] ?? ''));
        if ($mainLabel === '' || $subLabel === '') continue;
        $workshopId = (int)($it['workshop_id'] ?? 0);
        $categoryId = (int)($it['category_id'] ?? 0);
        $mainSort = (int)($it['main_sort'] ?? 0);
        $subSort = (int)($it['sub_sort'] ?? 0);
        $sortOrder = (int)($it['sort_order'] ?? 0);

        $groupsKey = $workshopId . '|' . $mainLabel;
        if (!isset($groups[$groupsKey])) {
            $groups[$groupsKey] = ['workshop_id' => $workshopId, 'title' => $mainLabel, 'sort' => $mainSort, 'categories' => []];
        }

        $catKey = $categoryId . '|' . $subLabel;
        if (!isset($groups[$groupsKey]['categories'][$catKey])) {
            $groups[$groupsKey]['categories'][$catKey] = ['category_id' => $categoryId, 'title' => $subLabel, 'sort' => $subSort, 'items' => []];
        }

        $title = trim((string)($it['title'] ?? ''));
        if ($title === '') continue;
        $priceRaw = (string)($it['price_raw'] ?? '');
        $price = is_numeric($priceRaw) ? (int)$priceRaw : null;

        $groups[$groupsKey]['categories'][$catKey]['items'][] = [
            'id' => (int)($it['poster_id'] ?? 0),
            'menu_item_id' => (int)($it['menu_item_id'] ?? 0),
            'name' => $title,
            'desc' => trim((string)($it['description'] ?? '')),
            'price' => $price,
            'image_url' => trim((string)($it['image_url'] ?? '')),
            'sort' => $sortOrder,
        ];
    }

    $out = array_values($groups);
    usort($out, fn($a, $b) => ((int)($a['sort'] ?? 0) <=> (int)($b['sort'] ?? 0)) ?: strcmp((string)($a['title'] ?? ''), (string)($b['title'] ?? '')));
    foreach ($out as &$g) {
        $cats = isset($g['categories']) && is_array($g['categories']) ? array_values($g['categories']) : [];
        usort($cats, fn($a, $b) => ((int)($a['sort'] ?? 0) <=> (int)($b['sort'] ?? 0)) ?: strcmp((string)($a['title'] ?? ''), (string)($b['title'] ?? '')));
        foreach ($cats as &$c) {
            $items = isset($c['items']) && is_array($c['items']) ? $c['items'] : [];
            usort($items, fn($a, $b) => ((int)($a['sort'] ?? 0) <=> (int)($b['sort'] ?? 0)) ?: strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? '')));
            $c['items'] = $items;
        }
        unset($c);
        $g['categories'] = $cats;
    }
    unset($g);

    $respondOk(['groups' => $out, 'last_sync_at' => $lastMenuSyncAt]);
}

if ($ajax === 'create_order') {
    $payloadJson = file_get_contents('php://input');
    $payload = json_decode($payloadJson, true);
    if (!is_array($payload)) $respondError(400, 'Bad request');

    // Phone validation
    $phone = trim((string)($payload['phone'] ?? ''));
    $phoneNorm = preg_replace('/\D+/', '', $phone);
    if ($phoneNorm === '' || !preg_match('/^[1-9]\d{6,15}$/', $phoneNorm)) {
        $respondError(400, 'Проверьте корректность номера телефона');
    }
    $phoneNorm = '+' . $phoneNorm;

    $name = trim((string)($payload['name'] ?? ''));
    if ($name === '') $respondError(400, 'Введите имя');

    $products = $payload['products'] ?? [];
    if (!is_array($products) || count($products) === 0) {
        $respondError(400, 'Корзина пуста');
    }

    $serviceMode = (int)($payload['service_mode'] ?? 2); // 2 - Takeaway, 3 - In place
    if (!in_array($serviceMode, [1, 2, 3], true)) $serviceMode = 2;

    $spotId = (int)($_ENV['POSTER_SPOT_ID'] ?? 1);
    if ($spotId <= 0) $spotId = 1;

    $orderProducts = [];
    foreach ($products as $p) {
        $orderProducts[] = [
            'product_id' => (int)($p['product_id'] ?? 0),
            'count' => (int)($p['count'] ?? 1)
        ];
    }

    $apiToken = trim((string)($_ENV['POSTER_API_TOKEN'] ?? ''));
    if ($apiToken === '') $respondError(500, 'Poster API Token not set');

    $api = new \App\Classes\PosterAPI($apiToken);
    
    $orderData = [
        'spot_id' => $spotId,
        'phone' => $phoneNorm,
        'first_name' => $name,
        'service_mode' => $serviceMode,
        'products' => $orderProducts
    ];

    try {
        // According to API https://dev.joinposter.com/docs/v3/web/incomingOrders/createIncomingOrder
        $resp = $api->request('incomingOrders.createIncomingOrder', $orderData, 'POST', true);
    } catch (\Throwable $e) {
        $respondError(500, 'Poster Error: ' . $e->getMessage());
    }

    $orderId = $resp['incoming_order_id'] ?? $resp['id'] ?? 0;
    if ($orderId <= 0) {
        $respondError(500, 'Не удалось создать заказ в Poster. Ответ: ' . json_encode($resp, JSON_UNESCAPED_UNICODE));
    }

    $respondOk(['order_id' => $orderId]);
}

$respondError(404, 'Unknown ajax action');
