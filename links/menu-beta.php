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
$ruTable = $db->t('menu_items_ru');
$enTable = $db->t('menu_items_en');
$vnTable = $db->t('menu_items_vn');
$koTable = $db->t('menu_items_ko');
$mcm = $db->t('menu_categories_main');
$mcmTr = $db->t('menu_categories_main_tr');
$mcs = $db->t('menu_categories_sub');
$mcsTr = $db->t('menu_categories_sub_tr');

$lastMenuSyncAt = null;
try {
    $row = $db->query("SELECT meta_value FROM {$metaTable} WHERE meta_key = 'menu_last_sync_at' LIMIT 1")->fetch();
    if (!empty($row['meta_value'])) {
        $lastMenuSyncAt = $row['meta_value'];
    }
} catch (\Exception $e) {
}
$langTable = $lang === 'ru' ? $ruTable : ($lang === 'en' ? $enTable : ($lang === 'ko' ? $koTable : $vnTable));
$trLang = $lang === 'vi' ? 'vn' : $lang;

$items = $db->query(
    "SELECT
        p.poster_id,
        p.price_raw,
        COALESCE(NULLIF(mi.title,''), NULLIF(en.title,''), NULLIF(ru.title,''), p.name_raw) AS title,
        COALESCE(NULLIF(mi.description,''), NULLIF(en.description,''), NULLIF(ru.description,''), '') AS description,
        COALESCE(NULLIF(ru.image_url,''), '') AS image_url,
        COALESCE(ru.sort_order, 0) AS sort_order,
        COALESCE(NULLIF(mit_main.name, ''), NULLIF(mm.name_raw, ''), '') AS main_label,
        COALESCE(NULLIF(mit_sub.name, ''), NULLIF(ms.name_raw, ''), '') AS sub_label,
        COALESCE(mm.sort_order, 0) AS main_sort,
        COALESCE(ms.sort_order, 0) AS sub_sort
     FROM {$pmi} p
     JOIN {$ruTable} ru ON ru.poster_item_id = p.id
     LEFT JOIN {$enTable} en ON en.poster_item_id = p.id
     LEFT JOIN {$langTable} mi ON mi.poster_item_id = p.id
     LEFT JOIN {$mcm} mm ON mm.id = COALESCE(mi.main_category_id, ru.main_category_id)
     LEFT JOIN {$mcmTr} mit_main ON mit_main.main_category_id = mm.id AND mit_main.lang = ?
     LEFT JOIN {$mcs} ms ON ms.id = COALESCE(mi.sub_category_id, ru.sub_category_id)
     LEFT JOIN {$mcsTr} mit_sub ON mit_sub.sub_category_id = ms.id AND mit_sub.lang = ?
     WHERE p.is_active = 1
       AND ru.is_published = 1
       AND (mm.id IS NULL OR mm.show_in_menu = 1)
       AND (ms.id IS NULL OR ms.show_in_menu = 1)
     ORDER BY
        main_sort ASC,
        main_label ASC,
        sub_sort ASC,
        sub_label ASC,
        COALESCE(ru.sort_order, 0) ASC,
        title ASC",
    [$trLang, $trLang]
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
$bgImageUrl = 'https://images.pexels.com/photos/958545/pexels-photo-958545.jpeg';
?>
<!doctype html>
<html lang="<?= htmlspecialchars($lang) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
    <title><?= htmlspecialchars($pageTitle) ?> | Veranda</title>
    <style>
        :root {
            --bg: #000;
            --card: rgba(255,255,255,0.06);
            --card2: rgba(255,255,255,0.09);
            --text: rgba(255,255,255,0.92);
            --muted: rgba(255,255,255,0.62);
            --accent: #B88746;
            --accent2: rgba(184,135,70,0.22);
            --border: rgba(255,255,255,0.10);
        }
        * { box-sizing: border-box; }
        html, body { height: 100%; }
        body {
            margin: 0;
            background: #05060a;
            color: var(--text);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Inter, Arial, sans-serif;
        }
        .parallax-bg {
            position: fixed;
            inset: 0;
            background-image: url("<?= htmlspecialchars($bgImageUrl) ?>");
            background-size: cover;
            background-position: center 0;
            filter: brightness(0.22) blur(1px);
            transform: translate3d(0,0,0);
            will-change: background-position;
            z-index: -1;
        }
        .wrap { max-width: 920px; margin: 0 auto; padding: 26px 18px 40px; }
        .header { display: flex; align-items: center; justify-content: space-between; gap: 14px; margin-bottom: 18px; }
        .brand { display: flex; align-items: center; gap: 12px; min-width: 0; }
        .logo {
            width: 42px; height: 42px;
            border-radius: 12px;
            background: linear-gradient(145deg, rgba(184,135,70,0.30), rgba(184,135,70,0.08));
            border: 1px solid rgba(184,135,70,0.35);
            box-shadow: 0 12px 30px rgba(0,0,0,0.35);
            display: grid; place-items: center;
            flex: 0 0 auto;
        }
        .logo span { color: var(--accent); font-weight: 800; letter-spacing: 0.06em; font-size: 14px; }
        h1 { margin: 0; font-size: 20px; line-height: 1.15; }
        .subtitle { margin-top: 4px; color: var(--muted); font-size: 13px; }
        .lang {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px;
            border-radius: 999px;
            border: 1px solid rgba(255,255,255,0.10);
            background: rgba(255,255,255,0.03);
            flex: 0 0 auto;
        }
        .lang a {
            text-decoration: none;
            color: rgba(255,255,255,0.65);
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.06em;
            padding: 6px 10px;
            border-radius: 999px;
            border: 1px solid transparent;
            line-height: 1;
        }
        .lang a.active {
            color: rgba(0,0,0,0.95);
            background: rgba(184,135,70,0.95);
            border-color: rgba(184,135,70,0.55);
        }
        .lang a:not(.active):hover {
            color: rgba(255,255,255,0.85);
            border-color: rgba(184,135,70,0.35);
        }
        .back {
            text-decoration: none;
            color: rgba(255,255,255,0.75);
            border: 1px solid rgba(255,255,255,0.10);
            background: rgba(255,255,255,0.03);
            padding: 8px 12px;
            border-radius: 999px;
            font-size: 13px;
            font-weight: 700;
        }
        .back:hover { border-color: rgba(184,135,70,0.35); color: rgba(255,255,255,0.92); }
        .section { margin-top: 14px; }
        .section-title {
            font-size: 16px;
            font-weight: 800;
            letter-spacing: 0.02em;
            margin: 18px 0 10px;
        }
        details {
            border: 1px solid var(--border);
            background: rgba(10,10,15,0.72);
            border-radius: 16px;
            overflow: hidden;
            margin-top: 10px;
            backdrop-filter: blur(8px);
        }
        summary {
            cursor: pointer;
            padding: 14px 14px;
            font-weight: 800;
            list-style: none;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }
        summary::-webkit-details-marker { display: none; }
        .sum-right { color: var(--muted); font-size: 12px; font-weight: 700; }
        .items { padding: 4px 12px 12px; display: grid; gap: 10px; }
        .item {
            border: 1px solid rgba(255,255,255,0.08);
            background: rgba(255,255,255,0.03);
            border-radius: 14px;
            padding: 12px;
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 12px;
            align-items: start;
        }
        .item.noimg { grid-template-columns: 1fr; }
        .item-head { display: flex; align-items: baseline; justify-content: space-between; gap: 10px; }
        .item-title { font-weight: 800; font-size: 14px; line-height: 1.25; }
        .item-price { font-weight: 800; color: rgba(255,255,255,0.90); font-size: 14px; white-space: nowrap; }
        .item-desc { margin-top: 6px; color: var(--muted); font-size: 12px; line-height: 1.35; }
        .thumb {
            width: 78px; height: 78px;
            border-radius: 14px;
            border: 1px solid rgba(184,135,70,0.28);
            background: rgba(255,255,255,0.03);
            overflow: hidden;
            flex: 0 0 auto;
        }
        .thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .muted { color: var(--muted); font-size: 12px; }
    </style>
</head>
<body>
    <div class="parallax-bg" aria-hidden="true"></div>
    <div class="wrap">
        <div class="header">
            <div class="brand">
                <a class="back" href="/links/?lang=<?= urlencode($lang) ?>">←</a>
                <div class="logo"><span>V</span></div>
                <div style="min-width:0;">
                    <h1><?= htmlspecialchars($pageTitle) ?></h1>
                    <div class="subtitle"><?= htmlspecialchars($lang === 'ru' ? 'Меню по категориям' : ($lang === 'vi' ? 'Thực đơn theo danh mục' : ($lang === 'ko' ? '카테고리별 메뉴' : 'Menu by categories'))) ?></div>
                </div>
            </div>
            <div class="lang" aria-label="Language">
                <a href="?lang=ru" class="<?= $lang === 'ru' ? 'active' : '' ?>">RU</a>
                <a href="?lang=en" class="<?= $lang === 'en' ? 'active' : '' ?>">EN</a>
                <a href="?lang=vi" class="<?= $lang === 'vi' ? 'active' : '' ?>">VI</a>
                <a href="?lang=ko" class="<?= $lang === 'ko' ? 'active' : '' ?>">KO</a>
            </div>
        </div>

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
                                                <img src="<?= htmlspecialchars($img) ?>" alt="">
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
            <div class="muted" style="margin-top:14px; text-align:center;">
                <?= htmlspecialchars($lang === 'ru' ? 'Последнее обновление меню:' : ($lang === 'vi' ? 'Cập nhật thực đơn lần cuối:' : ($lang === 'ko' ? '마지막 메뉴 동기화:' : 'Last menu sync:'))) ?>
                <?= htmlspecialchars(date('d.m.Y H:i', strtotime($lastMenuSyncAt))) ?>
            </div>
        <?php endif; ?>
    </div>
    <script>
        (() => {
            const bg = document.querySelector('.parallax-bg');
            if (!bg) return;
            const speed = 0.3;
            let ticking = false;
            const update = () => {
                const y = window.pageYOffset * speed;
                bg.style.backgroundPosition = 'center ' + (-y) + 'px';
                ticking = false;
            };
            window.addEventListener('scroll', () => {
                if (ticking) return;
                window.requestAnimationFrame(update);
                ticking = true;
            });
            update();
        })();
    </script>
</body>
</html>
