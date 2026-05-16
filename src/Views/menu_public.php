<?php
/**
 * Public menu view.
 * Expects: $lang, $groups, $seo, $pageTitle, $menuLabel, $lastMenuSyncAt, $explicitLang
 */

$canonicalUrl = $seo['canonical'];
$hreflang     = $seo['hreflang'];
$seoTitle     = $seo['title'];
$seoDesc      = $seo['description'];
$ogImage      = \App\Infrastructure\Config::baseUrl() . '/assets/img/links_bg.png';
$telephone    = '+84396314266';
?>
<!doctype html>
<html lang="<?= htmlspecialchars($lang) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/svg+xml" href="/links/favicon.svg">
    <meta name="description" content="<?= htmlspecialchars($seoDesc) ?>">
    <link rel="canonical" href="<?= htmlspecialchars($canonicalUrl) ?>">
    <?php foreach ($hreflang as $code => $href): ?>
        <link rel="alternate" hreflang="<?= htmlspecialchars((string)$code) ?>" href="<?= htmlspecialchars((string)$href) ?>">
    <?php endforeach; ?>
    <meta property="og:site_name" content="Veranda">
    <meta property="og:type" content="website">
    <meta property="og:title" content="<?= htmlspecialchars($seoTitle) ?>">
    <meta property="og:description" content="<?= htmlspecialchars($seoDesc) ?>">
    <meta property="og:url" content="<?= htmlspecialchars($canonicalUrl) ?>">
    <meta property="og:image" content="<?= htmlspecialchars($ogImage) ?>">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= htmlspecialchars($seoTitle) ?>">
    <meta name="twitter:description" content="<?= htmlspecialchars($seoDesc) ?>">
    <meta name="twitter:image" content="<?= htmlspecialchars($ogImage) ?>">
    <title><?= htmlspecialchars($seoTitle) ?></title>
    <link rel="preload" as="image" href="/assets/img/links_bg.png" fetchpriority="high">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@600;700&family=Montserrat:wght@300;400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/common.css?v=20260516_tokens">
    <link rel="stylesheet" href="/assets/css/links_index.css?v=20260505_0001">
    <link rel="stylesheet" href="/assets/css/menu-beta.css?v=20260504_0040">
</head>
<body class="menu-page">
<main class="links-page menu-page">
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
                <div class="header-left">
                    <a class="menu-back" href="/links/?lang=<?= urlencode($lang) ?>">←</a>
                </div>
                <div class="brand brand--center">
                    <h1 class="brand-title">
                        <span class="brand-name">VERANDA</span>
                        <span class="brand-tagline">— <?= htmlspecialchars($menuLabel) ?></span>
                    </h1>
                </div>
                <div class="header-right">
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
                    <div class="muted"><?= htmlspecialchars(match ($lang) {
                        'vi' => 'Chưa có món được hiển thị.',
                        'ko' => '표시할 메뉴가 없습니다.',
                        'en' => 'No published items yet.',
                        default => 'Пока нет опубликованных позиций.',
                    }) ?></div>
                <?php else: ?>
                    <?php foreach ($groups as $workshopId => $g): ?>
                        <div class="section">
                            <div class="section-title"><?= htmlspecialchars((string)($g['label'] ?? '')) ?></div>
                            <?php foreach (($g['cats'] ?? []) as $categoryId => $cat): ?>
                                <?php $list = is_array($cat['items'] ?? null) ? $cat['items'] : []; ?>
                                <details data-key="<?= htmlspecialchars($workshopId . ':' . $categoryId) ?>">
                                    <summary>
                                        <span><?= htmlspecialchars((string)($cat['label'] ?? '')) ?></span>
                                        <span class="sum-right"><?= count($list) ?></span>
                                    </summary>
                                    <div class="items">
                                        <?php foreach ($list as $it):
                                            $title    = trim((string)($it['title'] ?? ''));
                                            $desc     = trim((string)($it['description'] ?? ''));
                                            $img      = trim((string)($it['image_url'] ?? ''));
                                            $priceRaw = $it['price_raw'];
                                            $priceText = is_numeric($priceRaw)
                                                ? number_format((float)$priceRaw, 0, '.', ' ') . ' ₫'
                                                : (($priceRaw !== '' && $priceRaw !== null) ? $priceRaw . ' ₫' : '—');
                                        ?>
                                        <div class="item <?= $img !== '' ? '' : 'noimg' ?>">
                                            <div>
                                                <div class="item-head">
                                                    <div class="item-title"><?= htmlspecialchars($title ?: '—') ?></div>
                                                    <div class="item-price"><?= htmlspecialchars($priceText) ?></div>
                                                </div>
                                                <?php if ($desc !== ''): ?>
                                                    <div class="item-desc"><?= htmlspecialchars($desc) ?></div>
                                                <?php endif; ?>
                                            </div>
                                            <?php if ($img !== ''): ?>
                                                <div class="thumb">
                                                    <img src="<?= htmlspecialchars($img) ?>" alt="<?= htmlspecialchars($title ?: $pageTitle) ?>">
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
                        <?= htmlspecialchars(match ($lang) {
                            'vi' => 'Cập nhật thực đơn lần cuối:',
                            'ko' => '마지막 메뉴 동기화:',
                            'en' => 'Last menu sync:',
                            default => 'Последнее обновление меню:',
                        }) ?>
                        <?= htmlspecialchars(date('d.m.Y H:i', strtotime($lastMenuSyncAt))) ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </header>
</main>

<script type="application/ld+json"><?php
    $sections = [];
    foreach ($groups as $workshopId => $g) {
        $station = trim((string)($g['label'] ?? ''));
        foreach (($g['cats'] ?? []) as $categoryId => $cat) {
            $catLabel = trim((string)($cat['label'] ?? ''));
            $menuItems = [];
            foreach (($cat['items'] ?? []) as $it) {
                $t = trim((string)($it['title'] ?? ''));
                if ($t === '') continue;
                $obj = ['@type' => 'MenuItem', 'name' => $t];
                $d = trim((string)($it['description'] ?? ''));
                if ($d !== '') $obj['description'] = $d;
                $imgU = trim((string)($it['image_url'] ?? ''));
                if ($imgU !== '') $obj['image'] = $imgU;
                if (is_numeric($it['price_raw'])) {
                    $obj['offers'] = ['@type' => 'Offer', 'priceCurrency' => 'VND', 'price' => (float)$it['price_raw']];
                }
                $menuItems[] = $obj;
            }
            if (!empty($menuItems)) {
                $sections[] = ['@type' => 'MenuSection', 'name' => $station . ' · ' . $catLabel, 'hasMenuItem' => $menuItems];
            }
        }
    }
    echo json_encode([
        '@context' => 'https://schema.org',
        '@type'    => 'Restaurant',
        'name'     => 'Veranda',
        'url'      => $canonicalUrl,
        'image'    => $ogImage,
        'telephone' => $telephone,
        'address'  => ['@type' => 'PostalAddress', 'addressLocality' => 'Nha Trang', 'addressRegion' => 'Khánh Hòa', 'addressCountry' => 'Vietnam'],
        'hasMenu'  => ['@type' => 'Menu', 'name' => $pageTitle, 'hasMenuSection' => $sections],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?></script>

<script src="/links/links_fx.js?v=20260504_0029" defer></script>
<script src="/assets/js/menu-beta.js?v=20260504_0029" defer></script>
</body>
</html>
