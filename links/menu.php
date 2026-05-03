<?php
require_once __DIR__ . '/../src/classes/Database.php';

$supportedLangs = ['ru', 'en', 'vi', 'ko'];
$lang = null;

if (isset($_GET['lang'])) {
    $candidate = strtolower(trim((string)$_GET['lang']));
    if (in_array($candidate, $supportedLangs, true)) {
        $lang = $candidate;
        setcookie('links_lang', $lang, [
            'expires' => time() + 31536000,
            'path' => '/',
            'samesite' => 'Lax'
        ]);
    }
}

if ($lang === null) {
    $cookieLang = strtolower(trim((string)($_COOKIE['links_lang'] ?? '')));
    if (in_array($cookieLang, $supportedLangs, true)) {
        $lang = $cookieLang;
    }
}

if ($lang === null) {
    $accept = (string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
    $parts = preg_split('/\s*,\s*/', $accept);
    foreach ($parts as $part) {
        if ($part === '') {
            continue;
        }
        $code = strtolower(trim(explode(';', $part, 2)[0]));
        $base = explode('-', $code, 2)[0];
        if (in_array($base, $supportedLangs, true)) {
            $lang = $base;
            break;
        }
    }
}

if ($lang === null) {
    $lang = 'ru';
}

if (file_exists(__DIR__ . '/../.env')) {
    $lines = file(__DIR__ . '/../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        [$name, $value] = explode('=', $line, 2);
        $_ENV[$name] = trim($value);
    }
}

$dbHost = $_ENV['DB_HOST'] ?? 'localhost';
$dbName = $_ENV['DB_NAME'] ?? 'veranda_my';
$dbUser = $_ENV['DB_USER'] ?? 'veranda_my';
$dbPass = $_ENV['DB_PASS'] ?? '';
$tableSuffix = (string)($_ENV['DB_TABLE_SUFFIX'] ?? '');

$db = new \App\Classes\Database($dbHost, $dbName, $dbUser, $dbPass, $tableSuffix);
$db->createMenuTables();

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
    if (!empty($row['meta_value'])) {
        $lastMenuSyncAt = $row['meta_value'];
    }
} catch (\Exception $e) {
}
$trLang = $lang === 'vi' ? 'vn' : $lang;

$items = $db->query(
    "SELECT
        w.id AS workshop_id,
        COALESCE(NULLIF(wtr.name,''), NULLIF(w.name_raw,''), '') AS main_label,
        c.id AS category_id,
        COALESCE(NULLIF(ctr.name,''), NULLIF(c.name_raw,''), '') AS sub_label,
        p.poster_id,
        p.price_raw,
        COALESCE(NULLIF(itr.title,''), NULLIF(itr_ru.title,''), NULLIF(p.name_raw,''), '') AS title,
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

$groups = [];
foreach ($items as $it) {
    $mainLabel = trim((string)($it['main_label'] ?? ''));
    $subLabel = trim((string)($it['sub_label'] ?? ''));
    if ($mainLabel === '') {
        $mainLabel = $lang === 'ru' ? 'Без категории' : ($lang === 'vi' ? 'Không danh mục' : ($lang === 'ko' ? '미분류' : 'Uncategorized'));
    }
    if ($subLabel === '') {
        $subLabel = $lang === 'ru' ? 'Без подкатегории' : ($lang === 'vi' ? 'Không danh mục con' : ($lang === 'ko' ? '하위 분류 없음' : 'No subcategory'));
    }
    if (!isset($groups[$mainLabel])) {
        $groups[$mainLabel] = [];
    }
    if (!isset($groups[$mainLabel][$subLabel])) {
        $groups[$mainLabel][$subLabel] = [];
    }
    $groups[$mainLabel][$subLabel][] = $it;
}

$groups = array_filter($groups, function ($cats) {
    if (!is_array($cats)) return false;
    $cats = array_filter($cats, fn($list) => is_array($list) && count($list) > 0);
    return count($cats) > 0;
});
foreach ($groups as $station => $cats) {
    $groups[$station] = array_filter($cats, fn($list) => is_array($list) && count($list) > 0);
}

$pageTitle = $lang === 'ru' ? 'Online меню' : ($lang === 'vi' ? 'Thực đơn online' : ($lang === 'ko' ? '온라인 메뉴' : 'Online menu'));
$menuLabel = $lang === 'ru' ? 'МЕНЮ' : ($lang === 'vi' ? 'THỰC ĐƠN' : ($lang === 'ko' ? '메뉴' : 'MENU'));
?>
<!doctype html>
<html lang="<?= htmlspecialchars($lang) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/svg+xml" href="/links/favicon.svg">
    <meta name="description" content="<?= htmlspecialchars($lang === 'ru' ? 'Онлайн меню Veranda по категориям.' : ($lang === 'vi' ? 'Thực đơn online của Veranda theo danh mục.' : ($lang === 'ko' ? 'Veranda 온라인 메뉴(카테고리별).' : 'Veranda online menu by categories.'))) ?>">
    <link rel="canonical" href="https://veranda.my/links/menu.php?lang=<?= urlencode($lang) ?>">
    <meta property="og:site_name" content="Veranda">
    <meta property="og:type" content="website">
    <meta property="og:title" content="<?= htmlspecialchars($pageTitle) ?> | Veranda">
    <meta property="og:description" content="<?= htmlspecialchars($lang === 'ru' ? 'Онлайн меню по категориям.' : ($lang === 'vi' ? 'Thực đơn theo danh mục.' : ($lang === 'ko' ? '카테고리별 메뉴.' : 'Menu by categories.'))) ?>">
    <meta property="og:url" content="https://veranda.my/links/menu.php?lang=<?= urlencode($lang) ?>">
    <meta property="og:image" content="https://veranda.my/tr3/assets/og-image.svg">
    <meta name="twitter:card" content="summary_large_image">
    <title><?= htmlspecialchars($pageTitle) ?> | Veranda</title>
        <?php include $_SERVER['DOCUMENT_ROOT'] . '/analytics.php'; ?>
    <link rel="preload" as="image" href="/assets/img/links_bg.png" fetchpriority="high">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@600;700&family=Montserrat:wght@300;400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/common.css?v=20260425_0001">
    <link rel="stylesheet" href="/assets/css/links_index.css?v=20260504_0025">
    <link rel="stylesheet" href="/assets/css/menu-beta.css?v=20260504_0026">
</head>
<body>
    <main class="links-page">
        <header class="links-hero">
            <div class="links-hero__bg" aria-hidden="true">
                <div class="links-mesh" aria-hidden="true">
                    <div class="blob b1"></div>
                    <div class="blob b2"></div>
                    <div class="blob b3"></div>
                </div>
                <div class="links-spotlight" aria-hidden="true"></div>
            </div>
            <div class="links-hero__inner">
                <div class="links-header">
                    <div class="brand brand--center">
                        <h1 class="brand-title">
                            <span class="brand-name">VERANDA</span>
                            <span class="brand-tagline">— <?= htmlspecialchars($menuLabel) ?></span>
                        </h1>
                    </div>
                    <div class="header-right">
                        <a class="menu-back" href="/links/?lang=<?= urlencode($lang) ?>">←</a>
                        <details class="lang-menu">
                            <summary aria-label="Language">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a10 10 0 1 0 10 10A10 10 0 0 0 12 2Zm7.93 9h-3.2a15.7 15.7 0 0 0-1.47-5 8.05 8.05 0 0 1 4.67 5ZM12 4c1.1 0 2.7 2.2 3.4 7H8.6C9.3 6.2 10.9 4 12 4ZM4.07 11a8.05 8.05 0 0 1 4.67-5 15.7 15.7 0 0 0-1.47 5Zm0 2h3.2a15.7 15.7 0 0 0 1.47 5 8.05 8.05 0 0 1-4.67-5ZM12 20c-1.1 0-2.7-2.2-3.4-7h6.8c-.7 4.8-2.3 7-3.4 7Zm3.26-2a15.7 15.7 0 0 0 1.47-5h3.2a8.05 8.05 0 0 1-4.67 5Z"/></svg>
                            </summary>
                            <div class="lang-panel">
                                <a href="?lang=ru" class="<?= $lang === 'ru' ? 'active' : '' ?>" aria-label="Русский">Русский</a>
                                <a href="?lang=en" class="<?= $lang === 'en' ? 'active' : '' ?>" aria-label="English">English</a>
                                <a href="?lang=vi" class="<?= $lang === 'vi' ? 'active' : '' ?>" aria-label="Tiếng Việt">Tiếng Việt</a>
                                <a href="?lang=ko" class="<?= $lang === 'ko' ? 'active' : '' ?>" aria-label="한국어">한국어</a>
                            </div>
                        </details>
                    </div>
                </div>

                <div class="menu-content" id="menuContent">
                    <?php if (empty($groups)): ?>
                        <div class="muted"><?= htmlspecialchars($lang === 'ru' ? 'Пока нет опубликованных позиций.' : ($lang === 'vi' ? 'Chưa có món được hiển thị.' : ($lang === 'ko' ? '표시할 메뉴가 없습니다.' : 'No published items yet.'))) ?></div>
                    <?php else: ?>
                        <?php foreach ($groups as $mainLabel => $cats): ?>
                            <div class="section">
                                <div class="section-title"><?= htmlspecialchars($mainLabel) ?></div>
                                <?php foreach ($cats as $catLabel => $list): ?>
                                    <details>
                                        <summary>
                                            <span><?= htmlspecialchars($catLabel) ?></span>
                                            <span class="sum-right"><?= count($list) ?></span>
                                        </summary>
                                        <div class="items">
                                            <?php foreach ($list as $it): ?>
                                                <?php
                                                    $title = trim((string)($it['title'] ?? ''));
                                                    $desc = trim((string)($it['description'] ?? ''));
                                                    $img = trim((string)($it['image_url'] ?? ''));
                                                    $price = $it['price_raw'];
                                                    $priceText = '—';
                                                    if (is_numeric($price)) {
                                                        $priceText = number_format((float)$price, 0, '.', ' ') . ' ₫';
                                                    } elseif (is_string($price) && $price !== '') {
                                                        $priceText = $price . ' ₫';
                                                    }
                                                    $hasImg = $img !== '';
                                                ?>
                                                <div class="item <?= $hasImg ? '' : 'noimg' ?>">
                                                    <div>
                                                        <div class="item-head">
                                                            <div class="item-title"><?= htmlspecialchars($title !== '' ? $title : '—') ?></div>
                                                            <div class="item-price"><?= htmlspecialchars($priceText) ?></div>
                                                        </div>
                                                        <?php if ($desc !== ''): ?>
                                                            <div class="item-desc"><?= htmlspecialchars($desc) ?></div>
                                                        <?php endif; ?>
                                                    </div>
                                                    <?php if ($hasImg): ?>
                                                        <div class="thumb">
                                                            <img src="<?= htmlspecialchars($img) ?>" alt="<?= htmlspecialchars($title !== '' ? $title : $pageTitle) ?>">
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </details>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <?php if ($lastMenuSyncAt !== null): ?>
                        <div class="muted menu-sync">
                            <?= htmlspecialchars($lang === 'ru' ? 'Последнее обновление меню:' : ($lang === 'vi' ? 'Cập nhật thực đơn lần cuối:' : ($lang === 'ko' ? '마지막 메뉴 동기화:' : 'Last menu sync:'))) ?>
                            <?= htmlspecialchars(date('d.m.Y H:i', strtotime($lastMenuSyncAt))) ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </header>
    </main>
    <script type="application/ld+json"><?php
        $sections = [];
        foreach ($groups as $station => $cats) {
            if (!is_array($cats) || empty($cats)) continue;
            foreach ($cats as $catLabel => $list) {
                if (!is_array($list) || empty($list)) continue;
                $items = [];
                foreach ($list as $it) {
                    if (!is_array($it)) continue;
                    $title = trim((string)($it['title'] ?? ''));
                    if ($title === '') continue;
                    $desc = trim((string)($it['description'] ?? ''));
                    $price = $it['price_raw'] ?? null;
                    $img = trim((string)($it['image_url'] ?? ''));
                    $obj = [
                        '@type' => 'MenuItem',
                        'name' => $title,
                    ];
                    if ($desc !== '') $obj['description'] = $desc;
                    if ($img !== '') $obj['image'] = $img;
                    if (is_numeric($price)) {
                        $obj['offers'] = [
                            '@type' => 'Offer',
                            'priceCurrency' => 'VND',
                            'price' => (float)$price,
                        ];
                    }
                    $items[] = $obj;
                }
                if (empty($items)) continue;
                $sections[] = [
                    '@type' => 'MenuSection',
                    'name' => trim((string)$station) . ' · ' . trim((string)$catLabel),
                    'hasMenuItem' => $items,
                ];
            }
        }
        echo json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'Restaurant',
            'name' => 'Veranda',
            'url' => 'https://veranda.my/links/menu.php?lang=' . $lang,
            'hasMenu' => [
                '@type' => 'Menu',
                'name' => $pageTitle,
                'hasMenuSection' => $sections,
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    ?></script>
    <script src="/links/links_fx.js?v=20260504_0025" defer></script>
    <script src="/assets/js/menu-beta.js?v=20260504_0026" defer></script>
</body>
</html>
