<?php
if (file_exists(__DIR__ . '/.env')) {
    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $t = trim($line);
        if ($t === '' || strpos($t, '#') === 0) continue;
        if (strpos($t, '=') === false) continue;
        [$name, $value] = explode('=', $line, 2);
        $_ENV[$name] = trim($value);
    }
}

require_once __DIR__ . '/src/classes/PosterAPI.php';
require_once __DIR__ . '/src/classes/Database.php';

$posterToken = trim((string)($_ENV['POSTER_API_TOKEN'] ?? ''));
$dbHost = trim((string)($_ENV['DB_HOST'] ?? ''));
$dbName = trim((string)($_ENV['DB_NAME'] ?? ''));
$dbUser = trim((string)($_ENV['DB_USER'] ?? ''));
$dbPass = (string)($_ENV['DB_PASS'] ?? '');
$dbSuffix = trim((string)($_ENV['DB_TABLE_SUFFIX'] ?? ''));

$splitName = function (string $raw): array {
    $s = trim(preg_replace('/\s+/u', ' ', $raw));
    if ($s === '') return ['ru' => '', 'en' => ''];

    $hasCyr = function (string $x): bool { return (bool)preg_match('/\p{Cyrillic}/u', $x); };
    $hasLat = function (string $x): bool { return (bool)preg_match('/[A-Za-z]/', $x); };

    $seps = [' / ', '/', ' | ', '|', ' — ', ' – ', ' - ', '—', '–', '-'];
    foreach ($seps as $sep) {
        if (mb_strpos($s, $sep) === false) continue;
        $parts = array_map('trim', preg_split('/' . preg_quote($sep, '/') . '/u', $s, 2));
        if (count($parts) !== 2) continue;
        [$a, $b] = $parts;
        if ($a === '' || $b === '') continue;
        $aC = $hasCyr($a); $aL = $hasLat($a);
        $bC = $hasCyr($b); $bL = $hasLat($b);
        if (($aC && !$aL) && ($bL && !$bC)) return ['ru' => $a, 'en' => $b];
        if (($bC && !$bL) && ($aL && !$aC)) return ['ru' => $b, 'en' => $a];
    }

    $tokens = preg_split('/\s+/u', $s) ?: [];
    $ru = [];
    $en = [];
    foreach ($tokens as $tok) {
        $t = trim($tok);
        if ($t === '') continue;
        $tC = $hasCyr($t);
        $tL = $hasLat($t);
        if ($tC && !$tL) { $ru[] = $t; continue; }
        if ($tL && !$tC) { $en[] = $t; continue; }
        if (!$en && $ru) { $ru[] = $t; continue; }
        if (!$ru && $en) { $en[] = $t; continue; }
        if ($ru) $ru[] = $t;
        else $en[] = $t;
    }
    $ruS = trim(preg_replace('/\s+/u', ' ', implode(' ', $ru)));
    $enS = trim(preg_replace('/\s+/u', ' ', implode(' ', $en)));
    if ($ruS === '' && $enS === '') return ['ru' => $s, 'en' => ''];
    if ($ruS === '') return ['ru' => $s, 'en' => $enS];
    if ($enS === '') return ['ru' => $ruS, 'en' => ''];
    return ['ru' => $ruS, 'en' => $enS];
};

$cleanName = function (string $raw): string {
    $s = trim((string)$raw);
    if ($s === '') return '';
    $s = str_replace(["\u{00A0}", "\u{2007}", "\u{202F}"], ' ', $s);
    $s = preg_replace('/\s+/u', ' ', $s);
    $s = preg_replace('/^[\-\–\—\·\•\*\#\s]+/u', '', (string)$s);
    $s = preg_replace('/\s*[\-\–\—\·\•\*\#\s]+$/u', '', (string)$s);
    $s = preg_replace('/\s*\([^)]*\b(?:id|код|code|sku|арт|арт\.|№)\b[^)]*\)\s*/iu', ' ', (string)$s);
    $s = preg_replace('/\s*\[[^\]]*\b(?:id|код|code|sku|арт|арт\.|№)\b[^\]]*\]\s*/iu', ' ', (string)$s);
    $s = preg_replace('/\s*\((?:\d{1,4}|#\d{1,6})\)\s*/u', ' ', (string)$s);
    $s = preg_replace('/\s*\[(?:\d{1,4}|#\d{1,6})\]\s*/u', ' ', (string)$s);
    $s = preg_replace('/\s*\b(?:vnd|₫)\b\s*/iu', ' ', (string)$s);
    $s = preg_replace('/\s+/u', ' ', (string)$s);
    $s = trim((string)$s);
    return (string)$s;
};

$translitRuToEn = function (string $s): string {
    $map = [
        'А'=>'A','Б'=>'B','В'=>'V','Г'=>'G','Д'=>'D','Е'=>'E','Ё'=>'E','Ж'=>'Zh','З'=>'Z','И'=>'I','Й'=>'Y','К'=>'K','Л'=>'L','М'=>'M','Н'=>'N','О'=>'O','П'=>'P','Р'=>'R','С'=>'S','Т'=>'T','У'=>'U','Ф'=>'F','Х'=>'Kh','Ц'=>'Ts','Ч'=>'Ch','Ш'=>'Sh','Щ'=>'Shch','Ъ'=>'','Ы'=>'Y','Ь'=>'','Э'=>'E','Ю'=>'Yu','Я'=>'Ya',
        'а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ё'=>'e','ж'=>'zh','з'=>'z','и'=>'i','й'=>'y','к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o','п'=>'p','р'=>'r','с'=>'s','т'=>'t','у'=>'u','ф'=>'f','х'=>'kh','ц'=>'ts','ч'=>'ch','ш'=>'sh','щ'=>'shch','ъ'=>'','ы'=>'y','ь'=>'','э'=>'e','ю'=>'yu','я'=>'ya',
    ];
    return strtr($s, $map);
};

$translitEnToRu = function (string $s): string {
    $repl = [
        'shch' => 'щ', 'yo' => 'ё', 'zh' => 'ж', 'kh' => 'х', 'ts' => 'ц', 'ch' => 'ч', 'sh' => 'ш', 'yu' => 'ю', 'ya' => 'я',
    ];
    $out = '';
    $i = 0;
    $lower = (string)$s;
    while ($i < strlen($lower)) {
        $chunk = substr($lower, $i);
        $matched = false;
        foreach ($repl as $k => $v) {
            if (stripos($chunk, $k) === 0) {
                $out .= $v;
                $i += strlen($k);
                $matched = true;
                break;
            }
        }
        if ($matched) continue;
        $ch = $lower[$i];
        $map = [
            'a'=>'а','b'=>'б','c'=>'к','d'=>'д','e'=>'е','f'=>'ф','g'=>'г','h'=>'х','i'=>'и','j'=>'й','k'=>'к','l'=>'л','m'=>'м','n'=>'н','o'=>'о','p'=>'п','q'=>'к','r'=>'р','s'=>'с','t'=>'т','u'=>'у','v'=>'в','w'=>'в','x'=>'кс','y'=>'и','z'=>'з',
        ];
        $low = strtolower($ch);
        if (isset($map[$low])) {
            $out .= ctype_upper($ch) ? mb_strtoupper($map[$low], 'UTF-8') : $map[$low];
        } else {
            $out .= $ch;
        }
        $i += 1;
    }
    return $out;
};

$enFromRu = function (string $ru) use ($translitRuToEn): string {
    $dict = [
        'салат' => 'Salad',
        'суп' => 'Soup',
        'борщ' => 'Borsch',
        'паста' => 'Pasta',
        'пицца' => 'Pizza',
        'бургер' => 'Burger',
        'стейк' => 'Steak',
        'чай' => 'Tea',
        'кофе' => 'Coffee',
        'капучино' => 'Cappuccino',
        'латте' => 'Latte',
        'эспрессо' => 'Espresso',
        'лимонад' => 'Lemonade',
        'сок' => 'Juice',
        'вода' => 'Water',
        'десерт' => 'Dessert',
        'мороженое' => 'Ice cream',
        'сыр' => 'Cheese',
        'курица' => 'Chicken',
        'говядина' => 'Beef',
        'свинина' => 'Pork',
        'рыба' => 'Fish',
        'креветки' => 'Shrimp',
        'рис' => 'Rice',
        'лапша' => 'Noodles',
        'соус' => 'Sauce',
        'острый' => 'Spicy',
        'острая' => 'Spicy',
        'острое' => 'Spicy',
        'большой' => 'Large',
        'маленький' => 'Small',
    ];
    $ru = trim(preg_replace('/\s+/u', ' ', $ru));
    if ($ru === '') return '';
    $tokens = preg_split('/\s+/u', $ru) ?: [];
    $out = [];
    foreach ($tokens as $tok) {
        $t = trim($tok);
        if ($t === '') continue;
        $tClean = mb_strtolower(preg_replace('/[^\p{L}\p{N}\-]+/u', '', $t), 'UTF-8');
        if ($tClean !== '' && isset($dict[$tClean])) { $out[] = $dict[$tClean]; continue; }
        $out[] = $translitRuToEn($t);
    }
    return trim(preg_replace('/\s+/u', ' ', implode(' ', $out)));
};

$processPair = function (string $raw) use ($cleanName, $splitName, $translitRuToEn, $translitEnToRu, $enFromRu): array {
    $clean = $cleanName($raw);
    if ($clean === '') return ['ru' => '', 'en' => ''];
    $pair = $splitName($clean);
    $ru = trim((string)($pair['ru'] ?? ''));
    $en = trim((string)($pair['en'] ?? ''));

    $ru = $cleanName($ru);
    $en = $cleanName($en);

    $ru = preg_replace('/\s+\d{1,3}\s*$/u', '', (string)$ru);
    $en = preg_replace('/\s+\d{1,3}\s*$/u', '', (string)$en);
    $ru = trim(preg_replace('/\s+/u', ' ', (string)$ru));
    $en = trim(preg_replace('/\s+/u', ' ', (string)$en));

    if ($ru === '' && $en !== '') $ru = $translitEnToRu($en);
    if ($en === '' && $ru !== '') $en = $enFromRu($ru);

    if ($ru === '' && $en === '') { $ru = $clean; $en = $translitRuToEn($clean); }
    if ($ru === '') $ru = $clean;
    if ($en === '') $en = $translitRuToEn($ru);

    if (mb_strlen($ru, 'UTF-8') > 255) $ru = mb_substr($ru, 0, 255, 'UTF-8');
    if (mb_strlen($en, 'UTF-8') > 255) $en = mb_substr($en, 0, 255, 'UTF-8');
    return ['ru' => $ru, 'en' => $en];
};

if (($_GET['ajax'] ?? '') !== '') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    if ($dbHost === '' || $dbName === '' || $dbUser === '') {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'DB не настроена'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $db = new \App\Classes\Database($dbHost, $dbName, $dbUser, $dbPass, $dbSuffix);
    $t = $db->t('temp_names_products');
    $pdo = $db->getPdo();
    $pdo->exec("CREATE TABLE IF NOT EXISTS {$t} (
        product_id BIGINT PRIMARY KEY,
        name_raw VARCHAR(255) NOT NULL,
        name_ru VARCHAR(255) NOT NULL,
        name_en VARCHAR(255) NOT NULL,
        edit_ru VARCHAR(255) NOT NULL,
        edit_en VARCHAR(255) NOT NULL,
        edit_full VARCHAR(255) NOT NULL DEFAULT '',
        raw_json MEDIUMTEXT NULL,
        menu_category_id INT NOT NULL DEFAULT 0,
        category_name VARCHAR(255) NOT NULL DEFAULT '',
        workshop_id INT NOT NULL DEFAULT 0,
        weight_flag TINYINT NOT NULL DEFAULT 0,
        color VARCHAR(32) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        KEY idx_name_ru (name_ru),
        KEY idx_name_en (name_en)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    try { $pdo->exec("ALTER TABLE {$t} ADD COLUMN raw_json MEDIUMTEXT NULL"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE {$t} ADD COLUMN menu_category_id INT NOT NULL DEFAULT 0"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE {$t} ADD COLUMN workshop_id INT NOT NULL DEFAULT 0"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE {$t} ADD COLUMN weight_flag TINYINT NOT NULL DEFAULT 0"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE {$t} ADD COLUMN color VARCHAR(32) NOT NULL DEFAULT ''"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE {$t} ADD COLUMN edit_full VARCHAR(255) NOT NULL DEFAULT ''"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE {$t} ADD COLUMN category_name VARCHAR(255) NOT NULL DEFAULT ''"); } catch (\Throwable $e) {}

    $ajax = (string)($_GET['ajax'] ?? '');
    if ($ajax === 'list') {
        $rows = $db->query("SELECT product_id, name_raw, name_ru, name_en, edit_full, menu_category_id, category_name, updated_at FROM {$t} ORDER BY name_ru, name_raw")->fetchAll();
        echo json_encode(['ok' => true, 'items' => is_array($rows) ? $rows : []], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($ajax === 'update') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $payload = json_decode(file_get_contents('php://input') ?: '[]', true);
        if (!is_array($payload)) $payload = [];
        $pid = (int)($payload['product_id'] ?? 0);
        $value = trim((string)($payload['value'] ?? ''));
        if ($pid <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Bad request'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $full = $value;
        if (mb_strlen($full, 'UTF-8') > 255) $full = mb_substr($full, 0, 255, 'UTF-8');
        $ru = '';
        $en = '';
        if ($full !== '') {
            $parts = preg_split('/\s*\/\s*/u', $full, 2);
            if (is_array($parts) && count($parts) === 2) {
                $ru = trim((string)($parts[0] ?? ''));
                $en = trim((string)($parts[1] ?? ''));
            } else {
                $ru = $full;
                $en = '';
            }
        }
        if (mb_strlen($ru, 'UTF-8') > 255) $ru = mb_substr($ru, 0, 255, 'UTF-8');
        if (mb_strlen($en, 'UTF-8') > 255) $en = mb_substr($en, 0, 255, 'UTF-8');
        $now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $db->query("UPDATE {$t} SET edit_full = ?, edit_ru = ?, edit_en = ?, updated_at = ? WHERE product_id = ? LIMIT 1", [$full, $ru, $en, $now, $pid]);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($ajax === 'poster_update') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($posterToken === '') {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'POSTER_API_TOKEN не задан'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $payload = json_decode(file_get_contents('php://input') ?: '[]', true);
        if (!is_array($payload)) $payload = [];
        $pid = (int)($payload['product_id'] ?? 0);
        if ($pid <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Bad request'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $row = $db->query(
            "SELECT product_id, name_raw, name_ru, name_en, edit_full, menu_category_id, workshop_id, weight_flag, color
             FROM {$t}
             WHERE product_id = ?
             LIMIT 1",
            [$pid]
        )->fetch();
        if (!is_array($row)) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Not found'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $newName = trim((string)($row['edit_full'] ?? ''));
        if ($newName === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Пустое название'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (mb_strlen($newName, 'UTF-8') > 255) $newName = mb_substr($newName, 0, 255, 'UTF-8');

        $menuCategoryId = (int)($row['menu_category_id'] ?? 0);
        $workshopId = (int)($row['workshop_id'] ?? 0);
        $weightFlag = (int)($row['weight_flag'] ?? 0);
        $color = trim((string)($row['color'] ?? ''));
        if ($color === '') $color = 'white';

        $api = new \App\Classes\PosterAPI($posterToken);
        $okMethod = '';
        try {
            $api->request('menu.updateDish', [
                'dish_id' => $pid,
                'product_name' => $newName,
                'menu_category_id' => $menuCategoryId,
                'workshop' => $workshopId,
                'weight_flag' => $weightFlag,
                'product_color' => $color,
            ], 'POST');
            $okMethod = 'updateDish';
        } catch (\Throwable $e) {
            try {
                $api->request('menu.updateProduct', [
                    'id' => $pid,
                    'product_name' => $newName,
                    'menu_category_id' => $menuCategoryId,
                    'workshop' => $workshopId,
                    'weight_flag' => $weightFlag,
                    'color' => $color,
                ], 'POST');
                $okMethod = 'updateProduct';
            } catch (\Throwable $e2) {
                http_response_code(500);
                echo json_encode(['ok' => false, 'error' => $e2->getMessage()], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }

        $updatedNameRaw = (string)($row['name_raw'] ?? '');
        $updatedRu = (string)($row['name_ru'] ?? '');
        $updatedEn = (string)($row['name_en'] ?? '');
        $updatedCatName = trim((string)($row['category_name'] ?? ''));
        try {
            $prod = $api->request('menu.getProduct', ['product_id' => $pid], 'GET');
            if (is_array($prod)) {
                $pname = trim((string)($prod['product_name'] ?? ''));
                if ($pname !== '') {
                    if (mb_strlen($pname, 'UTF-8') > 255) $pname = mb_substr($pname, 0, 255, 'UTF-8');
                    $pair = $processPair($pname);
                    $updatedNameRaw = $pname;
                    $updatedRu = (string)($pair['ru'] ?? '');
                    $updatedEn = (string)($pair['en'] ?? '');
                }
                $menuCategoryId = (int)($prod['menu_category_id'] ?? $prod['category_id'] ?? $menuCategoryId);
                $updatedCatName = trim((string)($prod['category_name'] ?? $updatedCatName));
                $workshopId = (int)($prod['workshop'] ?? $prod['workshop_id'] ?? $workshopId);
                $weightFlag = (int)($prod['weight_flag'] ?? $weightFlag);
                $color = trim((string)($prod['color'] ?? $prod['product_color'] ?? $color));
                if ($color === '') $color = 'white';
            }
        } catch (\Throwable $e) {
        }
        if (mb_strlen($updatedCatName, 'UTF-8') > 255) $updatedCatName = mb_substr($updatedCatName, 0, 255, 'UTF-8');

        $now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $db->query(
            "UPDATE {$t}
             SET name_raw = ?,
                 name_ru = ?,
                 name_en = ?,
                 menu_category_id = ?,
                 category_name = ?,
                 workshop_id = ?,
                 weight_flag = ?,
                 color = ?,
                 updated_at = ?
             WHERE product_id = ?
             LIMIT 1",
            [$updatedNameRaw, $updatedRu, $updatedEn, $menuCategoryId, $updatedCatName, $workshopId, $weightFlag, $color, $now, $pid]
        );
        echo json_encode(['ok' => true, 'method' => $okMethod, 'product_id' => $pid], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($ajax === 'sync') {
        if ($posterToken === '') {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'POSTER_API_TOKEN не задан'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $api = new \App\Classes\PosterAPI($posterToken);
        $products = $api->request('menu.getProducts', [], 'GET');
        if (!is_array($products)) $products = [];

        $now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $stmt = $pdo->prepare(
            "INSERT INTO {$t} (product_id, name_raw, name_ru, name_en, edit_ru, edit_en, edit_full, raw_json, menu_category_id, category_name, workshop_id, weight_flag, color, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               name_raw = VALUES(name_raw),
               name_ru = VALUES(name_ru),
               name_en = VALUES(name_en),
               edit_full = IF(TRIM(edit_full)='', VALUES(edit_full), edit_full),
               raw_json = VALUES(raw_json),
               menu_category_id = VALUES(menu_category_id),
               category_name = VALUES(category_name),
               workshop_id = VALUES(workshop_id),
               weight_flag = VALUES(weight_flag),
               color = VALUES(color),
               updated_at = VALUES(updated_at)"
        );

        $count = 0;
        foreach ($products as $p) {
            if (!is_array($p)) continue;
            $pid = (int)($p['product_id'] ?? $p['id'] ?? 0);
            if ($pid <= 0) continue;
            $rawName = trim((string)($p['product_name'] ?? $p['name'] ?? ''));
            if ($rawName === '') continue;
            if (mb_strlen($rawName, 'UTF-8') > 255) $rawName = mb_substr($rawName, 0, 255, 'UTF-8');
            $sp = $splitName($rawName);
            $ru = (string)($sp['ru'] ?? '');
            $en = (string)($sp['en'] ?? '');
            if (mb_strlen($ru, 'UTF-8') > 255) $ru = mb_substr($ru, 0, 255, 'UTF-8');
            if (mb_strlen($en, 'UTF-8') > 255) $en = mb_substr($en, 0, 255, 'UTF-8');

            $exists = $db->query("SELECT edit_ru, edit_en FROM {$t} WHERE product_id = ? LIMIT 1", [$pid])->fetch();
            $editRu = '';
            $editEn = '';
            if (is_array($exists)) {
                $editRu = (string)($exists['edit_ru'] ?? '');
                $editEn = (string)($exists['edit_en'] ?? '');
            }
            $hasEdit = trim($editRu . $editEn) !== '';
            if (!$hasEdit) { $editRu = $ru; $editEn = $en; }
            if (mb_strlen($editRu, 'UTF-8') > 255) $editRu = mb_substr($editRu, 0, 255, 'UTF-8');
            if (mb_strlen($editEn, 'UTF-8') > 255) $editEn = mb_substr($editEn, 0, 255, 'UTF-8');
            $editFull = trim($editRu) !== '' && trim($editEn) !== '' ? (trim($editRu) . ' / ' . trim($editEn)) : (trim($editRu) !== '' ? trim($editRu) : trim($editEn));
            if ($editFull === '') $editFull = $rawName;
            if (mb_strlen($editFull, 'UTF-8') > 255) $editFull = mb_substr($editFull, 0, 255, 'UTF-8');

            $rawJson = json_encode($p, JSON_UNESCAPED_UNICODE);
            if (!is_string($rawJson)) $rawJson = null;
            if (is_string($rawJson) && mb_strlen($rawJson, 'UTF-8') > 2000000) $rawJson = mb_substr($rawJson, 0, 2000000, 'UTF-8');
            $menuCategoryId = (int)($p['menu_category_id'] ?? $p['category_id'] ?? $p['main_category_id'] ?? 0);
            $catName = trim((string)($p['category_name'] ?? $p['menu_category_name'] ?? ''));
            if (mb_strlen($catName, 'UTF-8') > 255) $catName = mb_substr($catName, 0, 255, 'UTF-8');
            $workshopId = (int)($p['workshop'] ?? $p['workshop_id'] ?? 0);
            $weightFlag = (int)($p['weight_flag'] ?? 0);
            $color = trim((string)($p['color'] ?? $p['product_color'] ?? ''));
            $stmt->execute([$pid, $rawName, $ru, $en, $editRu, $editEn, $editFull, $rawJson, $menuCategoryId, $catName, $workshopId, $weightFlag, $color, $now, $now]);
            $count++;
        }
        echo json_encode(['ok' => true, 'count' => $count], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($ajax === 'process_all') {
        $rows = $db->query("SELECT product_id, name_raw, name_ru, name_en, edit_ru, edit_en FROM {$t}")->fetchAll();
        if (!is_array($rows)) $rows = [];
        $now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $stmt = $pdo->prepare("UPDATE {$t} SET name_ru = ?, name_en = ?, edit_ru = IF(TRIM(edit_ru)='', ?, edit_ru), edit_en = IF(TRIM(edit_en)='', ?, edit_en), edit_full = IF(TRIM(edit_full)='', ?, edit_full), updated_at = ? WHERE product_id = ? LIMIT 1");
        $upd = 0;
        foreach ($rows as $r) {
            if (!is_array($r)) continue;
            $pid = (int)($r['product_id'] ?? 0);
            if ($pid <= 0) continue;
            $rawName = (string)($r['name_raw'] ?? '');
            $pair = $processPair($rawName);
            $ru = (string)($pair['ru'] ?? '');
            $en = (string)($pair['en'] ?? '');
            if ($ru === '' || $en === '') continue;
            $full = $ru !== '' && $en !== '' ? ($ru . ' / ' . $en) : ($ru !== '' ? $ru : $en);
            if ($full === '') $full = $rawName;
            if (mb_strlen($full, 'UTF-8') > 255) $full = mb_substr($full, 0, 255, 'UTF-8');
            $stmt->execute([$ru, $en, $ru, $en, $full, $now, $pid]);
            $upd++;
        }
        echo json_encode(['ok' => true, 'updated' => $upd], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Bad request'], JSON_UNESCAPED_UNICODE);
    exit;
}

?><!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>TEMP_NAMES</title>
    <link rel="icon" type="image/svg+xml" href="/links/favicon.svg">
    <style>
        body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; margin: 0; background: #f5f5f5; color:#111827; }
        .wrap { max-width: 1200px; margin: 0 auto; padding: 18px; }
        .top { display:flex; align-items:center; gap: 10px; flex-wrap: wrap; }
        .top h1 { margin: 0; font-size: 18px; font-weight: 900; }
        button { padding: 10px 14px; border-radius: 10px; border: 1px solid rgba(0,0,0,0.12); background: #fff; font-weight: 900; cursor: pointer; }
        button.primary { background: #1a73e8; color: #fff; border-color:#1a73e8; }
        button:disabled { opacity: 0.5; cursor: default; }
        .status { font-size: 12px; color:#6b7280; font-weight: 800; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; background: #fff; border: 1px solid rgba(0,0,0,0.10); border-radius: 12px; overflow:hidden; table-layout: fixed; }
        th, td { padding: 10px 12px; border-bottom: 1px solid rgba(0,0,0,0.06); vertical-align: top; }
        th { text-align:left; font-size: 12px; color:#6b7280; font-weight: 900; background: rgba(0,0,0,0.03); position: sticky; top: 0; }
        td { font-size: 13px; font-weight: 800; }
        .muted { color:#6b7280; font-weight: 800; }
        .edit { width: 100%; padding: 8px 10px; border-radius: 10px; border: 1px solid rgba(0,0,0,0.12); font-weight: 800; font-size: 13px; }
        .pid { font-variant-numeric: tabular-nums; font-size: 11px; }
        .row-saving { outline: 2px solid rgba(26,115,232,0.25); outline-offset: -2px; }
        .clip { overflow:hidden; text-overflow: ellipsis; white-space: nowrap; }
        .upd { width: 36px; height: 36px; border-radius: 10px; border: 1px solid rgba(0,0,0,0.12); background: #fff; cursor: pointer; display:inline-flex; align-items:center; justify-content:center; padding: 0; }
        .upd svg { width: 18px; height: 18px; }
        .upd:disabled { opacity: 0.35; cursor: default; }
        @media (max-width: 800px) {
            th:nth-child(1), td:nth-child(1) { display:none; }
        }
    </style>
</head>
<body>
<div class="wrap">
    <div class="top">
        <h1>TEMP_NAMES</h1>
        <button class="primary" id="syncBtn">Синхронизировать из Poster</button>
        <button id="processBtn">Обработать названия</button>
        <label class="status" style="display:flex; align-items:center; gap: 8px;">
            Категория
            <select id="catFilter" style="padding: 9px 10px; border-radius: 10px; border: 1px solid rgba(0,0,0,0.12); font-weight: 800; background:#fff;">
                <option value="">Все</option>
            </select>
        </label>
        <span class="status" id="status"></span>
    </div>
    <table>
        <thead>
        <tr>
            <th style="width:90px;">ID</th>
            <th style="width: 24%;">Оригинальное</th>
            <th style="width: 20%;">RU</th>
            <th style="width: 20%;">EN</th>
            <th style="width: 28%;">Новое название</th>
            <th style="width: 52px;"></th>
        </tr>
        </thead>
        <tbody id="tbody"></tbody>
    </table>
</div>

<script>
(() => {
    const tbody = document.getElementById('tbody');
    const syncBtn = document.getElementById('syncBtn');
    const processBtn = document.getElementById('processBtn');
    const catFilter = document.getElementById('catFilter');
    const statusEl = document.getElementById('status');
    const setStatus = (t) => { if (statusEl) statusEl.textContent = String(t || ''); };

    const apiUrl = (ajax) => {
        const u = new URL(location.href);
        u.searchParams.set('ajax', ajax);
        return u.toString();
    };

    const esc = (s) => String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');

    const rowValue = (r) => {
        return String(r.edit_full || '').trim();
    };

    let allItems = [];
    const buildCategories = (items) => {
        const map = new Map();
        items.forEach((r) => {
            const id = String(r.menu_category_id ?? '');
            if (!id || id === '0') return;
            const name = String(r.category_name || '').trim() || ('#' + id);
            map.set(id, name);
        });
        const arr = Array.from(map.entries()).map(([id, name]) => ({ id, name }));
        arr.sort((a, b) => a.name.localeCompare(b.name, 'ru'));
        return arr;
    };
    const renderList = () => {
        const catId = catFilter ? String(catFilter.value || '') : '';
        const items = catId ? allItems.filter((r) => String(r.menu_category_id ?? '') === catId) : allItems.slice();
        if (tbody) tbody.innerHTML = '';
        items.forEach((r) => {
            const pid = String(r.product_id || '');
            const tr = document.createElement('tr');
            tr.dataset.pid = pid;
            const canUpdate = String(rowValue(r)).trim() !== '';
            tr.innerHTML = `
                <td class="pid muted">${esc(pid)}</td>
                <td class="clip" title="${esc(r.name_raw || '')}">${esc(r.name_raw || '')}</td>
                <td class="clip" title="${esc(r.name_ru || '')}">${esc(r.name_ru || '')}</td>
                <td class="clip" title="${esc(r.name_en || '')}">${esc(r.name_en || '')}</td>
                <td><input class="edit" data-pid="${esc(pid)}" value="${esc(rowValue(r))}"></td>
                <td style="text-align:right;">
                    <button type="button" class="upd" data-pid="${esc(pid)}" ${canUpdate ? '' : 'disabled'} title="Обновить в Poster" aria-label="Обновить">
                        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path d="M20 12a8 8 0 1 1-2.2-5.5" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                            <path d="M20 4v6h-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </button>
                </td>
            `;
            tbody.appendChild(tr);
        });
        setStatus('Готово: ' + String(items.length) + (catId ? (' (фильтр)') : ''));
    };

    const loadList = async () => {
        setStatus('Загрузка…');
        const res = await fetch(apiUrl('list'), { headers: { 'Accept': 'application/json' } });
        const j = await res.json().catch(() => null);
        if (!res.ok || !j || !j.ok) throw new Error((j && j.error) ? j.error : 'Ошибка');
        allItems = Array.isArray(j.items) ? j.items : [];
        if (catFilter) {
            const prev = String(catFilter.value || '');
            const cats = buildCategories(allItems);
            catFilter.innerHTML = '<option value="">Все</option>';
            cats.forEach((c) => {
                const opt = document.createElement('option');
                opt.value = String(c.id);
                opt.textContent = String(c.name);
                catFilter.appendChild(opt);
            });
            if (prev && Array.from(catFilter.options).some((o) => o.value === prev)) catFilter.value = prev;
        }
        renderList();
    };

    const saveValue = async (pid, value, tr) => {
        if (!pid) return;
        if (tr) tr.classList.add('row-saving');
        try {
            const res = await fetch(apiUrl('update'), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({ product_id: Number(pid), value: String(value || '') }),
            });
            const j = await res.json().catch(() => null);
            if (!res.ok || !j || !j.ok) throw new Error((j && j.error) ? j.error : 'Ошибка');
        } finally {
            if (tr) tr.classList.remove('row-saving');
        }
    };

    if (tbody) {
        tbody.addEventListener('click', (e) => {
            const t = e.target;
            const btn = (t instanceof Element) ? t.closest('button.upd') : null;
            if (!(btn instanceof HTMLButtonElement)) return;
            if (btn.disabled) return;
            const pid = String(btn.dataset.pid || '');
            const tr = btn.closest('tr');
            btn.disabled = true;
            if (tr) tr.classList.add('row-saving');
            setStatus('Обновление в Poster…');
            fetch(apiUrl('poster_update'), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({ product_id: Number(pid) }),
            })
                .then((r) => r.json().catch(() => null).then((j) => ({ r, j })))
                .then(({ r, j }) => {
                    if (!r.ok || !j || !j.ok) throw new Error((j && j.error) ? j.error : 'Ошибка');
                    setStatus('Обновлено в Poster: ' + String(j.method || 'ok') + ' · ID ' + String(pid));
                    return loadList();
                })
                .catch((err) => setStatus(String(err && err.message ? err.message : err)))
                .finally(() => {
                    btn.disabled = false;
                    if (tr) tr.classList.remove('row-saving');
                });
        });
        tbody.addEventListener('change', (e) => {
            const t = e.target;
            if (!(t instanceof HTMLInputElement)) return;
            if (!t.classList.contains('edit')) return;
            const pid = String(t.dataset.pid || '');
            const tr = t.closest('tr');
            saveValue(pid, t.value, tr).catch((err) => setStatus(String(err && err.message ? err.message : err)));
        });
    }
    if (catFilter) catFilter.addEventListener('change', renderList);

    if (syncBtn) {
        syncBtn.addEventListener('click', async () => {
            if (syncBtn.disabled) return;
            syncBtn.disabled = true;
            setStatus('Синхронизация…');
            try {
                const res = await fetch(apiUrl('sync'), { headers: { 'Accept': 'application/json' } });
                const j = await res.json().catch(() => null);
                if (!res.ok || !j || !j.ok) throw new Error((j && j.error) ? j.error : 'Ошибка');
                setStatus('Синхронизировано: ' + String(j.count || 0));
                await loadList();
            } catch (err) {
                setStatus(String(err && err.message ? err.message : err));
            } finally {
                syncBtn.disabled = false;
            }
        });
    }

    if (processBtn) {
        processBtn.addEventListener('click', async () => {
            if (processBtn.disabled) return;
            processBtn.disabled = true;
            setStatus('Обработка…');
            try {
                const res = await fetch(apiUrl('process_all'), { headers: { 'Accept': 'application/json' } });
                const j = await res.json().catch(() => null);
                if (!res.ok || !j || !j.ok) throw new Error((j && j.error) ? j.error : 'Ошибка');
                setStatus('Обработано: ' + String(j.updated || 0));
                await loadList();
            } catch (err) {
                setStatus(String(err && err.message ? err.message : err));
            } finally {
                processBtn.disabled = false;
            }
        });
    }

    loadList().catch(async () => {
        try {
            if (syncBtn) {
                syncBtn.disabled = true;
                setStatus('Первичная синхронизация…');
                const res = await fetch(apiUrl('sync'), { headers: { 'Accept': 'application/json' } });
                const j = await res.json().catch(() => null);
                if (!res.ok || !j || !j.ok) throw new Error((j && j.error) ? j.error : 'Ошибка');
                await loadList();
            }
        } catch (e) {
            setStatus(String(e && e.message ? e.message : e));
        } finally {
            if (syncBtn) syncBtn.disabled = false;
        }
    });
})();
</script>
</body>
</html>
