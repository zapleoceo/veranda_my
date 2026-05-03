<?php
?>
<!doctype html>
<html lang="<?= htmlspecialchars($lang) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/svg+xml" href="/links/favicon.svg">
    <meta name="description" content="<?= htmlspecialchars($metaDescription) ?>">
    <link rel="canonical" href="<?= htmlspecialchars($canonicalUrl) ?>">
    <meta property="og:site_name" content="Veranda">
    <meta property="og:type" content="website">
    <meta property="og:title" content="Links | Veranda">
    <meta property="og:description" content="<?= htmlspecialchars($metaOgDescription) ?>">
    <meta property="og:url" content="<?= htmlspecialchars($canonicalUrl) ?>">
    <meta property="og:image" content="https://veranda.my/tr3/assets/og-image.svg">
    <meta name="twitter:card" content="summary_large_image">
    <title>Links | Veranda</title>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/analytics.php'; ?>
    <link rel="stylesheet" href="/assets/css/common.css">
    <link rel="stylesheet" href="/assets/css/links_index.css?v=20260504_0002">
</head>
<body>
    <div class="auth-float">
        <a class="auth-btn" href="/dashboard.php" title="Войти" aria-label="Войти">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 14a4 4 0 1 1 3.9-5H22v3h-2v2h-3v-2h-2v2h-3.1A4 4 0 0 1 7 14Zm0-6a2 2 0 1 0 2 2 2 2 0 0 0-2-2ZM2 20v-2h20v2Z"/></svg>
        </a>
    </div>

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
                    <div class="brand">
                        <div class="logo"><span>V</span></div>
                        <div>
                            <h1>Veranda</h1>
                            <div class="subtitle"><?= htmlspecialchars($subtitle) ?></div>
                            <?php if ($hoursTitle !== '' && ($hoursLine1 !== '' || $hoursLine2 !== '')): ?>
                                <div class="hours">
                                    <div class="hours__title"><?= htmlspecialchars($hoursTitle) ?></div>
                                    <div class="hours__lines">
                                        <?php if ($hoursLine1 !== ''): ?><div><?= htmlspecialchars($hoursLine1) ?></div><?php endif; ?>
                                        <?php if ($hoursLine2 !== ''): ?><div><?= htmlspecialchars($hoursLine2) ?></div><?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="header-right">
                        <details class="lang-menu">
                            <summary aria-label="Language">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a10 10 0 1 0 10 10A10 10 0 0 0 12 2Zm7.93 9h-3.2a15.7 15.7 0 0 0-1.47-5 8.05 8.05 0 0 1 4.67 5ZM12 4c1.1 0 2.7 2.2 3.4 7H8.6C9.3 6.2 10.9 4 12 4ZM4.07 11a8.05 8.05 0 0 1 4.67-5 15.7 15.7 0 0 0-1.47 5Zm0 2h3.2a15.7 15.7 0 0 0 1.47 5 8.05 8.05 0 0 1-4.67-5ZM12 20c-1.1 0-2.7-2.2-3.4-7h6.8c-.7 4.8-2.3 7-3.4 7Zm3.26-2a15.7 15.7 0 0 0 1.47-5h3.2a8.05 8.05 0 0 1-4.67 5Z"/></svg>
                            </summary>
                            <div class="lang-panel">
                                <?php foreach ($langMenu as $li): ?>
                                    <a href="?lang=<?= htmlspecialchars($li['code']) ?>" class="<?= $lang === $li['code'] ? 'active' : '' ?>" aria-label="<?= htmlspecialchars($li['label']) ?>"><?= htmlspecialchars($li['label']) ?></a>
                                <?php endforeach; ?>
                            </div>
                        </details>
                    </div>
                </div>

                <div class="links-layout">
                    <?php foreach ($sectionView as $section): ?>
                        <?php if (($section['key'] ?? '') === 'primary'): ?>
                            <section class="primary">
                                <div class="primary-actions">
                                    <?php foreach ($section['items'] as $item): ?>
                                        <?php $external = (string)($item['href'] ?? '') !== '' && (string)($item['href'])[0] !== '/'; ?>
                                        <a class="primary-btn" href="<?= htmlspecialchars($item['href']) ?>" <?= $external ? 'target="_blank" rel="noopener noreferrer"' : '' ?>>
                                            <div class="primary-icon"><?= $icons[$item['icon']] ?? '' ?></div>
                                            <div class="primary-text">
                                                <div class="primary-title"><?= htmlspecialchars($item['title']) ?></div>
                                                <?php if (!empty($item['subtitle'])): ?>
                                                    <div class="primary-sub"><?= htmlspecialchars($item['subtitle']) ?></div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="primary-arrow" aria-hidden="true">
                                                <svg viewBox="0 0 24 24"><path d="M13.2 5 11.8 6.4 16.4 11H4v2h12.4l-4.6 4.6L13.2 19l8-8Z"/></svg>
                                            </div>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            </section>
                        <?php else: ?>
                            <section class="links-section">
                                <h2 class="links-section__title"><?= htmlspecialchars($section['title']) ?></h2>
                                <div class="links-cards">
                                    <?php foreach ($section['items'] as $item): ?>
                                        <?php $external = (string)($item['href'] ?? '') !== '' && (string)($item['href'])[0] !== '/'; ?>
                                        <a class="card" href="<?= htmlspecialchars($item['href']) ?>" <?= $external ? 'target="_blank" rel="noopener noreferrer"' : '' ?>>
                                            <div class="icon"><?= $icons[$item['icon']] ?? '' ?></div>
                                            <div class="texts">
                                                <div class="title"><?= htmlspecialchars($item['title']) ?></div>
                                                <?php if (!empty($item['subtitle'])): ?>
                                                    <div class="sub"><?= htmlspecialchars($item['subtitle']) ?></div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="arrow" aria-hidden="true">
                                                <svg viewBox="0 0 24 24"><path d="M13.2 5 11.8 6.4 16.4 11H4v2h12.4l-4.6 4.6L13.2 19l8-8Z"/></svg>
                                            </div>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            </section>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>

                <footer class="footer">
                    <div>© <?= date('Y') ?> Veranda</div>
                </footer>
            </div>
        </header>
    </main>

    <script type="application/ld+json"><?= json_encode($jsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
    <script src="/links/links_fx.js?v=20260504_0002" defer></script>
</body>
</html>
