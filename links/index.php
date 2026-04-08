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
    'reserve' => '<svg viewBox="0 0 24 24" aria-hidden="true" fill="currentColor"><path d="M20.25 5.5a1.25 1.25 0 0 0-1.25-1.25H5a1.25 1.25 0 0 0-1.25 1.25v2.25h16.5V5.5Zm-16.5 4v11.25c0 .69.56 1.25 1.25 1.25h14a1.25 1.25 0 0 0 1.25-1.25V9.5H3.75Zm5 3.5h6.5v1.5h-6.5v-1.5Z"/></svg>',
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
            background: radial-gradient(900px 420px at 20% -10%, rgba(184,135,70,0.20), transparent 60%),
                        radial-gradient(900px 420px at 85% 0%, rgba(184,135,70,0.10), transparent 55%),
                        var(--bg);
            color: var(--text);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Inter, Arial, sans-serif;
        }
        .auth-float {
            position: fixed;
            top: 10px;
            right: 10px;
            z-index: 30;
        }
        .wrap { max-width: 760px; margin: 0 auto; padding: 26px 18px 40px; }
        .header { display: flex; align-items: center; justify-content: space-between; gap: 14px; margin-bottom: 18px; }
        .brand { display: flex; align-items: center; gap: 12px; }
        .header-right { display: inline-flex; align-items: center; gap: 10px; }
        .auth-btn {
            width: 34px;
            height: 34px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid rgba(255,255,255,0.14);
            background: rgba(255,255,255,0.03);
            color: rgba(255,255,255,0.75);
            text-decoration: none;
            flex: 0 0 auto;
        }
        .auth-btn svg { width: 18px; height: 18px; fill: currentColor; }
        .auth-btn:hover { border-color: rgba(184,135,70,0.55); color: rgba(255,255,255,0.9); }
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
        .logo {
            width: 42px; height: 42px;
            border-radius: 12px;
            background: linear-gradient(145deg, rgba(184,135,70,0.30), rgba(184,135,70,0.08));
            border: 1px solid rgba(184,135,70,0.35);
            box-shadow: 0 12px 30px rgba(0,0,0,0.35);
            display: grid; place-items: center;
            flex: 0 0 auto;
        }
        .logo span {
            color: var(--accent);
            font-weight: 800;
            letter-spacing: 0.06em;
            font-size: 14px;
        }
        h1 { margin: 0; font-size: 20px; line-height: 1.15; }
        .subtitle { margin-top: 4px; color: var(--muted); font-size: 13px; }
        .grid { display: grid; gap: 12px; margin-top: 14px; }
        .card {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 14px 14px;
            border: 1px solid var(--border);
            background: linear-gradient(180deg, var(--card), rgba(255,255,255,0.03));
            border-radius: 16px;
            text-decoration: none;
            color: inherit;
            transition: transform 120ms ease, border-color 120ms ease, background 120ms ease;
        }
        .card:hover { transform: translateY(-1px); border-color: rgba(184,135,70,0.35); background: linear-gradient(180deg, var(--card2), rgba(255,255,255,0.04)); }
        .icon {
            width: 44px; height: 44px;
            border-radius: 14px;
            border: 1px solid rgba(184,135,70,0.28);
            background: radial-gradient(18px 18px at 30% 25%, rgba(184,135,70,0.35), rgba(184,135,70,0.10));
            box-shadow: 0 10px 24px rgba(0,0,0,0.35);
            display: grid;
            place-items: center;
            flex: 0 0 auto;
        }
        .icon svg { width: 22px; height: 22px; fill: var(--accent); opacity: 0.95; }
        .texts { min-width: 0; flex: 1 1 auto; }
        .title { font-size: 15px; font-weight: 700; letter-spacing: 0.01em; }
        .sub { margin-top: 3px; font-size: 13px; color: var(--muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .arrow {
            width: 34px; height: 34px;
            border-radius: 12px;
            border: 1px solid var(--border);
            background: rgba(255,255,255,0.03);
            display: grid; place-items: center;
            flex: 0 0 auto;
        }
        .arrow svg { width: 16px; height: 16px; fill: rgba(255,255,255,0.70); }
        .footer {
            margin-top: 18px;
            padding-top: 14px;
            border-top: 1px solid rgba(255,255,255,0.08);
            color: rgba(255,255,255,0.55);
            font-size: 12px;
            text-align: center;
        }
        @media (max-width: 420px) {
            .wrap { padding: 20px 14px 34px; }
        }
    </style>
</head>
<body>
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
</body>
</html>
