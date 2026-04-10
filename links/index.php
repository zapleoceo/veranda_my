<?php
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

$t = [
    'ru' => [
        'subtitle' => 'Быстрые ссылки',
        'items' => [
            ['title' => 'Telegram группа', 'subtitle' => '@gamezone_vietnam'],
            ['title' => 'Instagram', 'subtitle' => '@veranda.my'],
            ['title' => 'Онлайн меню', 'subtitle' => 'Сайт'],
            ['title' => 'Бронирование столика', 'subtitle' => 'Сайт'],
            ['title' => 'Связаться с директором', 'subtitle' => '@Veranda_my'],
            ['title' => 'Google Карта', 'subtitle' => 'Как добраться'],
        ],
    ],
    'en' => [
        'subtitle' => 'Quick links',
        'items' => [
            ['title' => 'Telegram group', 'subtitle' => '@gamezone_vietnam'],
            ['title' => 'Instagram', 'subtitle' => '@veranda.my'],
            ['title' => 'Online menu', 'subtitle' => 'Website'],
            ['title' => 'Table Reservation', 'subtitle' => 'Website'],
            ['title' => 'Contact manager', 'subtitle' => '@Veranda_my'],
            ['title' => 'Google Maps', 'subtitle' => 'Directions'],
        ],
    ],
    'vi' => [
        'subtitle' => 'Liên kết nhanh',
        'items' => [
            ['title' => 'Nhóm Telegram', 'subtitle' => '@gamezone_vietnam'],
            ['title' => 'Instagram', 'subtitle' => '@veranda.my'],
            ['title' => 'Online menu', 'subtitle' => 'Website'],
            ['title' => 'Đặt bàn', 'subtitle' => 'Website'],
            ['title' => 'Liên hệ quản lý', 'subtitle' => '@Veranda_my'],
            ['title' => 'Google Maps', 'subtitle' => 'Chỉ đường'],
        ],
    ],
    'ko' => [
        'subtitle' => '빠른 링크',
        'items' => [
            ['title' => '텔레그램 그룹', 'subtitle' => '@gamezone_vietnam'],
            ['title' => '인스타그램', 'subtitle' => '@veranda.my'],
            ['title' => '온라인 메뉴', 'subtitle' => '웹사이트'],
            ['title' => '테이블 예약', 'subtitle' => '웹사이트'],
            ['title' => '매니저에게 문의', 'subtitle' => '@Veranda_my'],
            ['title' => '구글 지도', 'subtitle' => '길찾기'],
        ],
    ],
];

$links = [
    ['href' => 'https://t.me/gamezone_vietnam', 'icon' => 'telegram'],
    ['href' => 'https://www.instagram.com/veranda.my', 'icon' => 'instagram'],
    ['href' => '/links/menu-beta.php', 'icon' => 'menu'],
    ['href' => '/TableReservation', 'icon' => 'reserve'],
    ['href' => 'https://t.me/Veranda_my', 'icon' => 'manager'],
    ['href' => 'https://maps.app.goo.gl/wM9MMAGJjxUppDgR9', 'icon' => 'map'],
];

$items = [];
foreach ($links as $i => $baseItem) {
    $tr = $t[$lang]['items'][$i] ?? null;
    if (!$tr) {
        continue;
    }
    $items[] = [
        'title' => $tr['title'],
        'subtitle' => $tr['subtitle'],
        'href' => $baseItem['href'],
        'icon' => $baseItem['icon']
    ];
}

$icons = [
    'telegram' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9.6 15.6 9.2 19c.6 0 .9-.2 1.3-.6l3.1-3 6.4 4.6c1.2.7 2 .3 2.3-1.1l4.1-19.1c.4-1.7-.7-2.4-1.9-1.9L1.2 9.2c-1.6.6-1.6 1.5-.3 1.9l6 1.9L20.2 4c.7-.4 1.3-.2.8.3Z"/></svg>',
    'instagram' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7.2 2h9.6A5.2 5.2 0 0 1 22 7.2v9.6A5.2 5.2 0 0 1 16.8 22H7.2A5.2 5.2 0 0 1 2 16.8V7.2A5.2 5.2 0 0 1 7.2 2Zm0 2A3.2 3.2 0 0 0 4 7.2v9.6A3.2 3.2 0 0 0 7.2 20h9.6A3.2 3.2 0 0 0 20 16.8V7.2A3.2 3.2 0 0 0 16.8 4Zm4.8 3.5A4.5 4.5 0 1 1 7.5 12 4.5 4.5 0 0 1 12 7.5Zm0 2A2.5 2.5 0 1 0 14.5 12 2.5 2.5 0 0 0 12 9.5ZM17.3 6.7a1 1 0 1 1-1 1 1 1 0 0 1 1-1Z"/></svg>',
    'menu' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 2h9l3 3v17a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2Zm8 1.5V6h2.5ZM7 9h8v2H7Zm0 4h10v2H7Zm0 4h10v2H7Z"/></svg>',
    'reserve' => '<svg viewBox="0 0 24 24" aria-hidden="true" fill="currentColor"><path d="M1 5h2v8h3v8H4v-6H3v6H1Zm20 0h2v16h-2v-6h-1v6h-2v-8h3V5ZM8 12h8a1 1 0 0 1 1 1v1H7v-1a1 1 0 0 1 1-1Zm3 2h2v6h-2Zm-2 6h6v1H9Zm-1-8c0-2.21 1.79-4 4-4s4 1.79 4 4Zm3.5-6h1v2h-1Z"/></svg>',
    'manager' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 12a4 4 0 1 0-4-4 4 4 0 0 0 4 4Zm0 2c-4.4 0-8 2.2-8 5v1h16v-1c0-2.8-3.6-5-8-5Z"/><path d="M20 8h2v6h-2zM2 8h2v6H2z"/></svg>',
    'map' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a7 7 0 0 0-7 7c0 5.2 7 13 7 13s7-7.8 7-13a7 7 0 0 0-7-7Zm0 9.5A2.5 2.5 0 1 1 14.5 9 2.5 2.5 0 0 1 12 11.5Z"/></svg>',
];
?>
<!doctype html>
<html lang="<?= htmlspecialchars($lang) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/svg+xml" href="/links/favicon.svg">
    <title>Links | Veranda</title>
        <?php include $_SERVER['DOCUMENT_ROOT'] . '/analytics.php'; ?>
  <link rel="stylesheet" href="/assets/css/common.css?v=20260410_0125">
  <link rel="stylesheet" href="/assets/css/links_index.css?v=20260410_0125">
</head>
<body>
    <div class="parallax-bg" aria-hidden="true"></div>
    <div class="parallax-vignette" aria-hidden="true"></div>
    <div class="auth-float">
        <a class="auth-btn" href="/dashboard.php" title="Войти" aria-label="Войти">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 14a4 4 0 1 1 3.9-5H22v3h-2v2h-3v-2h-2v2h-3.1A4 4 0 0 1 7 14Zm0-6a2 2 0 1 0 2 2 2 2 0 0 0-2-2ZM2 20v-2h20v2Z"/></svg>
        </a>
    </div>
    <div class="wrap">
        <div class="header">
            <div class="brand">
                <div class="logo"><span>V</span></div>
                <div>
                    <h1>Veranda</h1>
                    <div class="subtitle"><?= htmlspecialchars($t[$lang]['subtitle'] ?? 'Быстрые ссылки') ?></div>
                </div>
            </div>
            <div class="header-right">
                <div class="lang" aria-label="Language">
                    <a href="?lang=ru" class="<?= $lang === 'ru' ? 'active' : '' ?>">RU</a>
                    <a href="?lang=en" class="<?= $lang === 'en' ? 'active' : '' ?>">EN</a>
                    <a href="?lang=vi" class="<?= $lang === 'vi' ? 'active' : '' ?>">VI</a>
                    <a href="?lang=ko" class="<?= $lang === 'ko' ? 'active' : '' ?>">KO</a>
                </div>
            </div>
        </div>

        <div class="grid">
            <?php foreach ($items as $item): ?>
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

        <div class="footer">
            <div>© <?= date('Y') ?> Veranda</div>
        </div>
    </div>
    <script src="/assets/js/links_index.js?v=20260410_0125"></script>
</body>
</html>
