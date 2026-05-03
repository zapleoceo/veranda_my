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
    <link rel="stylesheet" href="/assets/css/links_index.css">
</head>
<body>
    <div class="auth-float">
        <a class="auth-btn" href="/dashboard.php" title="Войти" aria-label="Войти">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 14a4 4 0 1 1 3.9-5H22v3h-2v2h-3v-2h-2v2h-3.1A4 4 0 0 1 7 14Zm0-6a2 2 0 1 0 2 2 2 2 0 0 0-2-2ZM2 20v-2h20v2Z"/></svg>
        </a>
    </div>

    <main class="links-page">
        <header class="links-hero">
            <div class="links-hero__bg" aria-hidden="true"></div>
            <div class="links-hero__inner">
                <div class="links-header">
                    <div class="brand">
                        <div class="logo"><span>V</span></div>
                        <div>
                            <h1>Veranda</h1>
                            <div class="subtitle"><?= htmlspecialchars($subtitle) ?></div>
                        </div>
                    </div>
                    <div class="header-right">
                        <nav class="lang" aria-label="Language">
                            <a href="?lang=ru" class="<?= $lang === 'ru' ? 'active' : '' ?>">RU</a>
                            <a href="?lang=en" class="<?= $lang === 'en' ? 'active' : '' ?>">EN</a>
                            <a href="?lang=vi" class="<?= $lang === 'vi' ? 'active' : '' ?>">VI</a>
                            <a href="?lang=ko" class="<?= $lang === 'ko' ? 'active' : '' ?>">KO</a>
                        </nav>
                    </div>
                </div>

                <div class="links-grid">
                    <?php foreach ($sectionView as $section): ?>
                        <section class="links-section">
                            <h2 class="links-section__title"><?= htmlspecialchars($section['title']) ?></h2>
                            <div class="links-cards">
                                <?php foreach ($section['items'] as $item): ?>
                                    <a class="card" href="<?= htmlspecialchars($item['href']) ?>" target="_blank" rel="noopener noreferrer">
                                        <div class="icon"><?= $icons[$item['icon']] ?? '' ?></div>
                                        <div class="texts">
                                            <div class="title"><?= htmlspecialchars($item['title']) ?></div>
                                            <div class="sub"><?= htmlspecialchars($item['subtitle']) ?></div>
                                        </div>
                                        <div class="arrow">
                                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M13.2 5 11.8 6.4 16.4 11H4v2h12.4l-4.6 4.6L13.2 19l8-8Z"/></svg>
                                        </div>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </section>
                    <?php endforeach; ?>
                </div>

                <footer class="footer">
                    <div>© <?= date('Y') ?> Veranda</div>
                </footer>
            </div>
        </header>
    </main>

    <script type="application/ld+json"><?= json_encode($jsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
</body>
</html>

