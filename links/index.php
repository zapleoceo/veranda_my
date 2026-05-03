<?php
require_once __DIR__ . '/links_data.php';

$lang = null;
$explicitLang = null;
if (isset($_GET['lang'])) {
    $candidate = strtolower(trim((string)$_GET['lang']));
    if (in_array($candidate, $supportedLangs, true)) {
        $lang = $candidate;
        $explicitLang = $lang;
        setcookie('links_lang', $lang, [
            'expires' => time() + 31536000,
            'path' => '/',
            'samesite' => 'Lax'
        ]);
    }
}
if ($lang === null) {
    $cookieLang = strtolower(trim((string)($_COOKIE['links_lang'] ?? '')));
    if (in_array($cookieLang, $supportedLangs, true)) $lang = $cookieLang;
}
if ($lang === null) {
    $accept = (string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
    $parts = preg_split('/\s*,\s*/', $accept);
    foreach ($parts as $part) {
        if ($part === '') continue;
        $code = strtolower(trim(explode(';', $part, 2)[0]));
        $base = explode('-', $code, 2)[0];
        if (in_array($base, $supportedLangs, true)) { $lang = $base; break; }
    }
}
if ($lang === null) $lang = 'ru';
if (!isset($i18n[$lang])) $lang = 'ru';

$langMenu = [
    ['code' => 'ru', 'label' => 'Русский'],
    ['code' => 'en', 'label' => 'English'],
    ['code' => 'vi', 'label' => 'Tiếng Việt'],
    ['code' => 'ko', 'label' => '한국어'],
];

$subtitle = (string)($i18n[$lang]['subtitle'] ?? 'Быстрые ссылки');
$hoursTitle = (string)($i18n[$lang]['hours']['title'] ?? '');
$hoursLine1 = (string)($i18n[$lang]['hours']['line1'] ?? '');
$hoursLine2 = (string)($i18n[$lang]['hours']['line2'] ?? '');

$baseUrl = 'https://veranda.my/links/';
$langUrl = static fn(string $code): string => $baseUrl . '?lang=' . urlencode($code);

$seoTitles = [
    'ru' => 'Veranda — ресторан и бар, Нячанг, Вьетнам',
    'en' => 'Veranda - restaurant and bar. Nha Trang, Vietnam',
    'vi' => 'Veranda - nhà hàng & quầy bar. Nha Trang, Việt Nam',
    'ko' => 'Veranda — 레스토랑 & 바, 나트랑, 베트남',
];
$metaDescriptions = [
    'ru' => 'Veranda — ресторан и бар в Нячанге, Вьетнам. Меню, бронирование столика и контакты: Telegram, WhatsApp, звонок.',
    'en' => 'Veranda is a restaurant & bar in Nha Trang, Vietnam. Menu, table reservations and contacts: Telegram, WhatsApp, phone call.',
    'vi' => 'Veranda là nhà hàng & quầy bar tại Nha Trang, Việt Nam. Thực đơn, đặt bàn và liên hệ: Telegram, WhatsApp, gọi điện.',
    'ko' => '베트남 나트랑의 Veranda 레스토랑 & 바. 메뉴, 테이블 예약, 문의: Telegram, WhatsApp, 전화.',
];

$seoTitle = (string)($seoTitles[$lang] ?? $seoTitles['en']);
$metaDescription = (string)($metaDescriptions[$lang] ?? $metaDescriptions['en']);
$metaOgDescription = $metaDescription;

$canonicalUrl = $explicitLang === null ? $baseUrl : $langUrl($lang);

$hreflang = [
    'x-default' => $baseUrl,
    'ru' => $langUrl('ru'),
    'en' => $langUrl('en'),
    'vi' => $langUrl('vi'),
    'ko' => $langUrl('ko'),
];

$icons = [
    'telegram' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9.6 15.6 9.2 19c.6 0 .9-.2 1.3-.6l3.1-3 6.4 4.6c1.2.7 2 .3 2.3-1.1l4.1-19.1c.4-1.7-.7-2.4-1.9-1.9L1.2 9.2c-1.6.6-1.6 1.5-.3 1.9l6 1.9L20.2 4c.7-.4 1.3-.2.8.3Z"/></svg>',
    'whatsapp' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20.1 3.9A11.8 11.8 0 0 0 12 0 12 12 0 0 0 1.6 17.6L0 24l6.6-1.6A12 12 0 0 0 24 12a11.8 11.8 0 0 0-3.9-8.1ZM12 21.9a10 10 0 0 1-5.1-1.4l-.4-.2-3.9 1 1-3.8-.2-.4A10 10 0 1 1 12 21.9Zm5.8-7.4c-.3-.2-1.8-.9-2.1-1s-.5-.2-.7.2-.8 1-.9 1.2-.3.2-.6.1a8.2 8.2 0 0 1-2.4-1.5 9 9 0 0 1-1.7-2.1c-.2-.3 0-.5.1-.6l.5-.6a2.3 2.3 0 0 0 .3-.5.6.6 0 0 0 0-.5c0-.2-.7-1.7-1-2.3s-.5-.5-.7-.5h-.6a1.2 1.2 0 0 0-.9.4 3.8 3.8 0 0 0-1.2 2.8 6.5 6.5 0 0 0 1.4 3.4 14.8 14.8 0 0 0 5.7 5 6.8 6.8 0 0 0 3.3.9 3.2 3.2 0 0 0 2.1-.9 2.6 2.6 0 0 0 .6-1.9c0-.2-.2-.3-.5-.4Z"/></svg>',
    'phone' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6.6 10.8c1.5 3 4 5.4 7.1 6.9l2.4-2.4c.3-.3.8-.4 1.2-.2 1.3.5 2.8.8 4.3.8.7 0 1.4.6 1.4 1.4V21c0 .7-.6 1.4-1.4 1.4C10.7 22.4 1.6 13.3 1.6 2.4 1.6 1.6 2.2 1 2.9 1h3.6c.7 0 1.4.6 1.4 1.4 0 1.5.3 3 .8 4.3.1.4 0 .9-.3 1.2Z"/></svg>',
    'facebook' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M22 12a10 10 0 1 0-11.6 9.9v-7H8v-3h2.4V9.7c0-2.4 1.4-3.7 3.6-3.7 1 0 2 .2 2 .2v2.3h-1.1c-1.1 0-1.4.7-1.4 1.4V12H18l-.5 3h-2.4v7A10 10 0 0 0 22 12Z"/></svg>',
    'menu' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 2h9l3 3v17a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2Zm8 1.5V6h2.5ZM7 9h8v2H7Zm0 4h10v2H7Zm0 4h10v2H7Z"/></svg>',
    'reserve' => '<svg viewBox="0 0 24 24" aria-hidden="true" fill="currentColor"><path d="M1 5h2v8h3v8H4v-6H3v6H1Zm20 0h2v16h-2v-6h-1v6h-2v-8h3V5ZM8 12h8a1 1 0 0 1 1 1v1H7v-1a1 1 0 0 1 1-1Zm3 2h2v6h-2Zm-2 6h6v1H9Zm-1-8c0-2.21 1.79-4 4-4s4 1.79 4 4Zm3.5-6h1v2h-1Z"/></svg>',
    'director' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 12a4 4 0 1 0-4-4 4 4 0 0 0 4 4Zm0 2c-4.4 0-8 2.2-8 5v1h16v-1c0-2.8-3.6-5-8-5Z"/><path d="M20 8h2v6h-2zM2 8h2v6H2z"/></svg>',
    'map' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a7 7 0 0 0-7 7c0 5.2 7 13 7 13s7-7.8 7-13a7 7 0 0 0-7-7Zm0 9.5A2.5 2.5 0 1 1 14.5 9 2.5 2.5 0 0 1 12 11.5Z"/></svg>',
];

$sectionView = [];
foreach ($sections as $sectionKey => $keys) {
    $secTitle = (string)($i18n[$lang]['sections'][$sectionKey] ?? $sectionKey);
    $secItems = [];
    foreach ($keys as $k) {
        $tr = $i18n[$lang]['items'][$k] ?? null;
        $def = $linkDefs[$k] ?? null;
        if (!$tr || !$def) continue;
        $secItems[] = [
            'key' => $k,
            'title' => $tr['title'],
            'subtitle' => $tr['subtitle'],
            'href' => $def['href'],
            'icon' => $def['icon'],
        ];
    }
    if ($secItems) $sectionView[] = ['key' => $sectionKey, 'title' => $secTitle, 'items' => $secItems];
}

$telephone = '+84396314266';
$ogImage = 'https://veranda.my/assets/img/links_bg.png';

$jsonLd = [
    '@context' => 'https://schema.org',
    '@type' => 'Restaurant',
    'name' => 'Veranda',
    'url' => $canonicalUrl,
    'image' => $ogImage,
    'telephone' => $telephone,
    'address' => [
        '@type' => 'PostalAddress',
        'addressLocality' => 'Nha Trang',
        'addressRegion' => 'Khánh Hòa',
        'addressCountry' => 'Vietnam',
    ],
    'hasMenu' => 'https://veranda.my/links/menu.php',
    'potentialAction' => [
        [
            '@type' => 'ReserveAction',
            'target' => 'https://veranda.my/tr3',
        ],
    ],
    'sameAs' => [
        $linkDefs['tg_group']['href'],
        $linkDefs['tg_veranda']['href'],
        $linkDefs['whatsapp']['href'],
        $linkDefs['facebook']['href'],
        $linkDefs['map']['href'],
    ],
];

require_once __DIR__ . '/view.php';
