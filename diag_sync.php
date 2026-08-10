<?php
// ВРЕМЕННЫЙ диагностик синка меню. Read-only. Удалить сразу после снятия.
declare(strict_types=1);
if (($_GET['t'] ?? '') !== 'vrd_sync_5k2p') { http_response_code(404); exit('Not found'); }

require_once __DIR__ . '/src/classes/Database.php';
require_once __DIR__ . '/src/classes/PosterAPI.php';
if (file_exists(__DIR__ . '/.env')) {
    foreach (file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) continue;
        [$k, $v] = explode('=', $line, 2);
        $_ENV[$k] = trim($v);
    }
}

header('Content-Type: text/plain; charset=utf-8');

$db = new \App\Classes\Database(
    $_ENV['DB_HOST'] ?? 'localhost', $_ENV['DB_NAME'] ?? '', $_ENV['DB_USER'] ?? '',
    $_ENV['DB_PASS'] ?? '', (string)($_ENV['DB_TABLE_SUFFIX'] ?? '')
);
$api = new \App\Classes\PosterAPI($_ENV['POSTER_API_TOKEN'] ?? '');

// Та же логика, что в PosterMenuSync::isProductVisible()
$vis = function (array $p): bool {
    if (isset($p['spots']) && is_array($p['spots']) && !empty($p['spots'])) {
        foreach ($p['spots'] as $s) { if (is_array($s) && array_key_exists('visible', $s) && (int)$s['visible'] === 1) return true; }
        return false;
    }
    if (array_key_exists('visible', $p)) return (int)$p['visible'] === 1;
    if (array_key_exists('hidden', $p))  return (int)$p['hidden'] !== 1;
    return true;
};

$prod = $api->request('menu.getProducts');
$prod = is_array($prod) ? $prod : [];
$visible = [];
foreach ($prod as $p) {
    $id = (int)($p['product_id'] ?? $p['id'] ?? 0);
    if ($id > 0 && $vis($p)) $visible[$id] = (string)($p['product_name'] ?? '');
}

$pmi = $db->t('poster_menu_items');
$mi  = $db->t('menu_items');
$active = [];
foreach ($db->query("SELECT poster_id, name_raw FROM {$pmi} WHERE is_active = 1")->fetchAll() as $r) {
    $active[(int)$r['poster_id']] = (string)$r['name_raw'];
}

$missing = array_diff_key($visible, $active); // видим в Poster, нет активного на сайте
$stale   = array_diff_key($active, $visible); // активен на сайте, но невидим/удалён в Poster

echo "Poster: всего товаров = " . count($prod) . ", видимых = " . count($visible) . "\n";
echo "Сайт:   poster_menu_items активных = " . count($active) . "\n\n";

echo "=== НЕ ДОЕХАЛИ (видим в Poster, но нет активного на сайте): " . count($missing) . " ===\n";
foreach ($missing as $id => $n) echo "  $id | $n\n";

echo "\n=== ЛИШНИЕ (активен на сайте, но в Poster невидим/удалён): " . count($stale) . " ===\n";
foreach ($stale as $id => $n) echo "  $id | $n\n";

$noMi = (int)$db->query("SELECT COUNT(*) FROM {$pmi} p LEFT JOIN {$mi} m ON m.poster_item_id = p.id WHERE p.is_active = 1 AND m.id IS NULL")->fetchColumn();
$pubN = (int)$db->query("SELECT COUNT(*) FROM {$mi} WHERE is_published = 1")->fetchColumn();
echo "\n=== menu_items ===\n";
echo "Активных товаров БЕЗ строки menu_items (не опубликуются без ручного шага): $noMi\n";
echo "Опубликовано (is_published=1): $pubN\n";

echo "\n=== system_meta ===\n";
$meta = $db->t('system_meta');
foreach (['menu_last_sync_at', 'menu_last_sync_result', 'menu_last_sync_error'] as $k) {
    $r = $db->query("SELECT meta_value FROM {$meta} WHERE meta_key = ? LIMIT 1", [$k])->fetch();
    echo "  $k = " . ($r['meta_value'] ?? '(нет)') . "\n";
}
