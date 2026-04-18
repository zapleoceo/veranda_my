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
    // Borrowed logic from tr3/api.php?ajax=menu_preorder
    $menuLang = 'ru';
    $pmi = $db->t('poster_menu_items');
    $mw = $db->t('menu_workshops');
    $mwTr = $db->t('menu_workshop_tr');
    $mc = $db->t('menu_categories');
    $mcTr = $db->t('menu_category_tr');
    $mi = $db->t('menu_items');
    $miTr = $db->t('menu_item_tr');

    try {
        $sql = "
          SELECT 
          mi.poster_item_id AS item_id,
          mi.price,
          COALESCE(mitr.name, mi.name) AS item_name,
          COALESCE(mitr.description, mi.description) AS item_desc,
          mi.category_id AS cat_id,
          mc.poster_id AS poster_cat_id,
          COALESCE(mctr.name, mc.name) AS cat_name,
          mw.poster_id AS poster_workshop_id,
          COALESCE(mwtr.name, mw.name) AS workshop_name,
          mi.id AS menu_item_id,
          mc.sort_order AS cat_sort,
          mi.sort_order AS item_sort
          FROM {$mi} mi
          LEFT JOIN {$miTr} mitr ON mi.id = mitr.item_id AND mitr.lang = :lang
          JOIN {$mc} mc ON mi.category_id = mc.id
          LEFT JOIN {$mcTr} mctr ON mc.id = mctr.category_id AND mctr.lang = :lang
          JOIN {$mw} mw ON mc.workshop_id = mw.id
          LEFT JOIN {$mwTr} mwtr ON mw.id = mwtr.workshop_id AND mwtr.lang = :lang
          WHERE mi.is_published = 1 AND mc.show_on_site = 1 AND mw.show_on_site = 1
          ORDER BY mw.sort_order ASC, mc.sort_order ASC, mi.sort_order ASC
        ";

        $stmt = $db->getPdo()->prepare($sql);
        $stmt->execute(['lang' => $menuLang]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $groups = [];
        $groupIndexMap = [];

        foreach ($rows as $it) {
            $catId = (int)($it['cat_id'] ?? 0);
            if (!isset($groupIndexMap[$catId])) {
                $groups[] = [
                    'id' => $catId,
                    'title' => trim((string)($it['cat_name'] ?? '')),
                    'items' => []
                ];
                $groupIndexMap[$catId] = count($groups) - 1;
            }

            $idx = $groupIndexMap[$catId];
            $groups[$idx]['items'][] = [
                'id' => (int)($it['item_id'] ?? 0),
                'menu_item_id' => (int)($it['menu_item_id'] ?? 0),
                'name' => trim((string)($it['item_name'] ?? '')),
                'desc' => trim((string)($it['item_desc'] ?? '')),
                'price' => (int)($it['price'] ?? 0),
            ];
        }

        $respondOk(['groups' => $groups]);

    } catch (\Throwable $e) {
        $respondError(500, 'Menu Error: ' . $e->getMessage());
    }
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

    $spotId = 1; // Assuming default spot ID is 1

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
        $resp = $api->request('incomingOrders.createIncomingOrder', $orderData, 'POST');
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
