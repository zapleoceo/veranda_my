<?php
// /home — главная страница veranda.my. Mobile-first, фото-первая
// концепция: «дневная тропическая терраса в горах Нячанга».
// Не темный nightclub vibe (как в первой версии), а светлая тёплая
// палитра, реальные фото из съёмки 2026 года.
//
// Архитектурно: один PHP-файл, всё CSS inline, JS минимальный.
// Все изображения — WebP в двух размерах (1400w + 700w) с srcset.
// Hero подгружается eager, остальное lazy через native loading="lazy".

if (!class_exists(\App\Infrastructure\Config::class, false)) {
    require_once __DIR__ . '/../vendor/autoload.php';
    \App\Infrastructure\Config::load(__DIR__ . '/../.env');
}

$lang         = 'ru';
$siteBase     = \App\Infrastructure\Config::baseUrl();
$canonicalUrl = $siteBase . '/home';
$reserveUrl   = $siteBase . '/tr3/';
$menuUrl      = $siteBase . '/links/menu';
$ogImage      = $siteBase . '/assets/img/home/hero-terrace-1400.webp';

$telephone     = '+84792070707';
$telDisplay    = '+84 792 070 707';
$banyaPhone    = '+84395959140';
$banyaDisplay  = '+84 39 5959 140';
$whatsappUrl   = 'https://wa.me/' . preg_replace('/\D+/', '', $telephone);
$telegramUrl   = 'https://t.me/gamezone_vietnam';
$instagramUrl  = 'https://www.instagram.com/veranda.my/';
$mapsUrl       = 'https://maps.app.goo.gl/';
$banyaUrl      = 'https://sila-duha.com/';
$gamezoneUrl   = 'https://ru.vn-gamezone.com/';

$h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

/**
 * <img> с responsive srcset для двух WebP размеров и LQIP fallback.
 * Используется везде в макете — задаёт ровно одну точку правды.
 *
 * @param string $name   базовое имя файла (например 'hero-terrace')
 * @param string $alt    alt-текст
 * @param string $sizes  CSS sizes attribute (например '(min-width: 740px) 50vw, 100vw')
 * @param bool   $eager  true для hero, false для остальных
 * @param string $extra  доп. классы / атрибуты для <img>
 */
function v_img(string $name, string $alt, string $sizes = '100vw', bool $eager = false, string $extra = ''): string {
    $base = '/assets/img/home/' . $name;
    $loading = $eager ? 'eager' : 'lazy';
    $fetch   = $eager ? 'high' : 'auto';
    return sprintf(
        '<img src="%s-700.webp" srcset="%s-700.webp 700w, %s-1400.webp 1400w" sizes="%s" loading="%s" fetchpriority="%s" alt="%s" %s>',
        $base, $base, $base, htmlspecialchars($sizes, ENT_QUOTES),
        $loading, $fetch, htmlspecialchars($alt, ENT_QUOTES), $extra
    );
}

// Афиша. day: 0=Sun…6=Sat (соответствует JS Date.getDay()).
$events = [
    1 => ['title' => 'Мафия в беседке',     'time' => '19:00',         'note' => 'Командная игра под гирляндами'],
    2 => ['title' => 'Кино под звёздами',   'time' => '18:00 · 20:00', 'note' => 'Детский и взрослый сеансы'],
    3 => ['title' => 'Live Music',          'time' => '19:00',         'note' => 'Авторская и кавер-программа'],
    4 => ['title' => 'Кино под звёздами',   'time' => '18:00 · 20:00', 'note' => 'Детский и взрослый сеансы'],
    5 => ['title' => 'Live Music',          'time' => '19:00',         'note' => 'BiBi Duo / MRV / TN Band'],
    6 => ['title' => 'Живая музыка',        'time' => '19:00',         'note' => 'The Pennywort, Рядновы и др.'],
    0 => ['title' => 'Вечер живой музыки',  'time' => '19:00',         'note' => 'Уютный воскресный вечер'],
];
$dayNames = [0 => 'Вс', 1 => 'Пн', 2 => 'Вт', 3 => 'Ср', 4 => 'Чт', 5 => 'Пт', 6 => 'Сб'];

$icon = [
    'wa'      => '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M20.1 3.9A11.8 11.8 0 0 0 12 0 12 12 0 0 0 1.6 17.6L0 24l6.6-1.6A12 12 0 0 0 24 12a11.8 11.8 0 0 0-3.9-8.1Zm-2.3 10.6c-.3-.2-1.8-.9-2.1-1s-.5-.2-.7.2-.8 1-.9 1.2-.3.2-.6.1a8.2 8.2 0 0 1-2.4-1.5 9 9 0 0 1-1.7-2.1c-.2-.3 0-.5.1-.6l.5-.6.3-.5a.6.6 0 0 0 0-.5c0-.2-.7-1.7-1-2.3s-.5-.5-.7-.5h-.6a1.2 1.2 0 0 0-.9.4 3.8 3.8 0 0 0-1.2 2.8 6.5 6.5 0 0 0 1.4 3.4 14.8 14.8 0 0 0 5.7 5 6.8 6.8 0 0 0 3.3.9 3.2 3.2 0 0 0 2.1-.9 2.6 2.6 0 0 0 .6-1.9c0-.2-.2-.3-.5-.4z"/></svg>',
    'tg'      => '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M9.6 15.6 9.2 19c.6 0 .9-.2 1.3-.6l3.1-3 6.4 4.6c1.2.7 2 .3 2.3-1.1l4.1-19.1c.4-1.7-.7-2.4-1.9-1.9L1.2 9.2c-1.6.6-1.6 1.5-.3 1.9l6 1.9L20.2 4c.7-.4 1.3-.2.8.3z"/></svg>',
    'ig'      => '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M7.8 2h8.4A5.8 5.8 0 0 1 22 7.8v8.4A5.8 5.8 0 0 1 16.2 22H7.8A5.8 5.8 0 0 1 2 16.2V7.8A5.8 5.8 0 0 1 7.8 2zm0 2A3.8 3.8 0 0 0 4 7.8v8.4A3.8 3.8 0 0 0 7.8 20h8.4A3.8 3.8 0 0 0 20 16.2V7.8A3.8 3.8 0 0 0 16.2 4zm4.2 3.5A4.5 4.5 0 1 1 7.5 12 4.5 4.5 0 0 1 12 7.5zm0 2A2.5 2.5 0 1 0 14.5 12 2.5 2.5 0 0 0 12 9.5zM17.6 6.6a1 1 0 1 1-1 1 1 1 0 0 1 1-1z"/></svg>',
    'phone'   => '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M6.6 10.8c1.5 3 4 5.4 7.1 6.9l2.4-2.4c.3-.3.8-.4 1.2-.2 1.3.5 2.8.8 4.3.8.7 0 1.4.6 1.4 1.4V21c0 .7-.6 1.4-1.4 1.4C10.7 22.4 1.6 13.3 1.6 2.4 1.6 1.6 2.2 1 2.9 1h3.6c.7 0 1.4.6 1.4 1.4 0 1.5.3 3 .8 4.3.1.4 0 .9-.3 1.2z"/></svg>',
    'arrow'   => '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M5 12h13l-5-5 1.4-1.4L21.8 12l-7.4 6.4L13 17l5-5H5z"/></svg>',
    'pin'     => '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2a7 7 0 0 0-7 7c0 5.2 7 13 7 13s7-7.8 7-13a7 7 0 0 0-7-7zm0 9.5A2.5 2.5 0 1 1 14.5 9 2.5 2.5 0 0 1 12 11.5z"/></svg>',
];

$jsonLd = [
    '@context' => 'https://schema.org',
    '@type' => 'Restaurant',
    'name' => 'Veranda Restaurant & Bar',
    'url' => $canonicalUrl,
    'image' => $ogImage,
    'telephone' => $telephone,
    'priceRange' => '$$',
    'servesCuisine' => ['Slavic', 'European', 'Vietnamese'],
    'address' => [
        '@type' => 'PostalAddress',
        'addressLocality' => 'Nha Trang',
        'addressRegion' => 'Khánh Hòa',
        'addressCountry' => 'Vietnam',
    ],
    'hasMenu' => $menuUrl,
    'potentialAction' => ['@type' => 'ReserveAction', 'target' => $reserveUrl],
];
?><!doctype html>
<html lang="<?= $h($lang) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#f5efe4">
<title>Veranda — ресторан в горах Нячанга, баня и игры</title>
<meta name="description" content="Veranda Restaurant &amp; Bar — ресторан на склоне в 10 минутах от центра Нячанга. Домашняя кухня, баня на дровах, игры для всей семьи, живая музыка и кино под звёздами. Вход на события свободный.">
<link rel="canonical" href="<?= $h($canonicalUrl) ?>">
<link rel="icon" type="image/svg+xml" href="/links/favicon.svg">

<meta property="og:type" content="website">
<meta property="og:site_name" content="Veranda">
<meta property="og:title" content="Veranda — целый вечер впечатлений в горах Нячанга">
<meta property="og:description" content="Ресторан, баня на дровах, игры для всей семьи, живая музыка и кино под звёздами. 10 минут от центра.">
<meta property="og:url" content="<?= $h($canonicalUrl) ?>">
<meta property="og:image" content="<?= $h($ogImage) ?>">
<meta name="twitter:card" content="summary_large_image">

<!-- Preload hero — критично для LCP -->
<link rel="preload" as="image"
      href="/assets/img/home/hero-terrace-1400.webp"
      imagesrcset="/assets/img/home/hero-terrace-700.webp 700w, /assets/img/home/hero-terrace-1400.webp 1400w"
      imagesizes="100vw">

<!-- Google Fonts (только Cyrillic subset, font-display: swap) -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cinzel:wght@600;700&family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,400&family=Inter:wght@400;500;600&display=swap">

<?php include $_SERVER['DOCUMENT_ROOT'] . '/analytics.php'; ?>

<style>
/* ─── Tokens ─────────────────────────────────────────────────── */
:root {
    /* Палитра реальной Veranda: дневная тропическая, тёплая. */
    --paper:    #f5efe4;   /* основной фон — бумага */
    --paper-2:  #ede4d2;   /* чуть темнее для секций */
    --paper-3:  #e0d3bb;
    --ink:      #1f1810;   /* глубокий тёмный (текст) */
    --ink-2:    #4a3d2c;
    --ink-3:    #7a6a55;   /* приглушённый */
    --line:     #d9cdb5;
    --line-2:   rgba(31, 24, 16, .08);

    --red:      #c2412c;   /* красный фонарей */
    --red-2:    #a3331f;
    --red-soft: #e89a8c;
    --leaf:     #4a6b4a;   /* зелень */
    --leaf-2:   #3a5536;
    --gold:     #b88a3e;   /* охра/золото для акцентов */
    --pink:     #d97595;   /* бугенвиллия — sparingly */
    --sky:      #b8c8d4;

    --r-sm: 6px;  --r-md: 12px;  --r-lg: 20px;  --r-pill: 999px;

    --s-1: 4px;   --s-2: 8px;   --s-3: 12px;  --s-4: 16px;
    --s-5: 24px;  --s-6: 32px;  --s-7: 48px;  --s-8: 72px;  --s-9: 96px;

    --shadow-soft: 0 4px 16px rgba(31, 24, 16, .08);
    --shadow-md:   0 12px 40px rgba(31, 24, 16, .12);
    --shadow-deep: 0 24px 80px rgba(31, 24, 16, .18);

    --serif: 'Cormorant Garamond', 'Iowan Old Style', Georgia, serif;
    --sans:  'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, system-ui, sans-serif;
}

/* ─── Reset ──────────────────────────────────────────────────── */
*, *::before, *::after { box-sizing: border-box; }
html { -webkit-text-size-adjust: 100%; scroll-behavior: smooth; }
body {
    margin: 0;
    background: var(--paper);
    color: var(--ink);
    font: 400 17px/1.6 var(--sans);
    text-rendering: optimizeLegibility;
    -webkit-font-smoothing: antialiased;
    overflow-x: hidden;
}
img { display: block; max-width: 100%; height: auto; }
a { color: inherit; text-decoration: none; }
button { font: inherit; color: inherit; background: none; border: 0; cursor: pointer; }
:focus-visible { outline: 2px solid var(--red); outline-offset: 3px; border-radius: 4px; }
[hidden] { display: none !important; }

.container { max-width: 1240px; margin: 0 auto; padding: 0 var(--s-4); }
.container--narrow { max-width: 920px; }

/* ─── Typography helpers ────────────────────────────────────── */
.serif      { font-family: var(--serif); font-weight: 500; }
.eyebrow {
    display: inline-block;
    font-size: 12px; font-weight: 500;
    letter-spacing: .22em; text-transform: uppercase;
    color: var(--red); margin-bottom: var(--s-3);
}
.h-display {
    font-family: var(--serif); font-weight: 500;
    line-height: 1.05; letter-spacing: -.01em;
    font-size: clamp(38px, 8vw, 88px);
    color: var(--ink);
}
.h-display em { font-style: italic; color: var(--red); font-weight: 400; }
.h-section {
    font-family: var(--serif); font-weight: 500;
    line-height: 1.1; letter-spacing: -.005em;
    font-size: clamp(30px, 5.5vw, 56px);
    color: var(--ink); margin: 0 0 var(--s-4);
}
.h-section em { font-style: italic; color: var(--red); font-weight: 400; }
.lead { font-size: clamp(17px, 2.2vw, 21px); line-height: 1.55; color: var(--ink-2); }

/* ─── Buttons ────────────────────────────────────────────────── */
.btn {
    display: inline-flex; align-items: center; gap: 10px;
    padding: 16px 28px; border-radius: var(--r-pill);
    font-family: var(--sans); font-weight: 500; font-size: 16px;
    letter-spacing: .01em; min-height: 52px;
    transition: transform .25s ease, box-shadow .25s ease, background .2s, color .2s;
    white-space: nowrap;
}
.btn svg { width: 18px; height: 18px; }
.btn--red {
    background: var(--red); color: #fff;
    box-shadow: 0 6px 24px rgba(194, 65, 44, .35);
}
.btn--red:hover { background: var(--red-2); transform: translateY(-2px); box-shadow: 0 10px 32px rgba(194, 65, 44, .45); }
.btn--outline {
    background: transparent; color: var(--ink);
    border: 1px solid var(--ink); padding: 15px 27px;
}
.btn--outline:hover { background: var(--ink); color: var(--paper); }
.btn--light {
    background: rgba(255, 255, 255, .92); color: var(--ink);
    backdrop-filter: blur(8px);
    box-shadow: var(--shadow-soft);
}
.btn--light:hover { background: #fff; transform: translateY(-2px); }
.btn--ghost-light {
    background: rgba(255, 255, 255, .12); color: #fff;
    border: 1px solid rgba(255, 255, 255, .35);
    backdrop-filter: blur(8px);
}
.btn--ghost-light:hover { background: rgba(255, 255, 255, .22); }

/* ─── Sticky header ─────────────────────────────────────────── */
.hdr {
    position: fixed; top: 0; left: 0; right: 0; z-index: 50;
    transition: background .35s ease, box-shadow .35s ease, padding .3s ease;
    padding: var(--s-3) 0;
}
.hdr.is-scrolled {
    background: rgba(245, 239, 228, .9);
    backdrop-filter: blur(14px);
    -webkit-backdrop-filter: blur(14px);
    box-shadow: 0 1px 0 var(--line);
}
.hdr__row {
    display: flex; align-items: center; justify-content: space-between;
    gap: var(--s-3);
}
/* Логотип — общий стиль с /links/: Cinzel 700, всё капсом,
   широкий letter-spacing. Так бренд читается одинаково на всех
   страницах сайта. */
.hdr__brand {
    font-family: 'Cinzel', Georgia, "Times New Roman", serif;
    font-weight: 700; font-size: 22px;
    letter-spacing: 0.12em;
    color: #fff;
    transition: color .35s;
}
.hdr.is-scrolled .hdr__brand { color: var(--ink); }

.hdr__actions { display: flex; align-items: center; gap: var(--s-2); }
.hdr__lang {
    display: none; gap: 0;
    font-size: 13px; font-weight: 500;
    color: rgba(255, 255, 255, .8);
    transition: color .35s;
}
.hdr.is-scrolled .hdr__lang { color: var(--ink-3); }
.hdr__lang button {
    padding: 6px 8px; transition: color .2s;
}
.hdr__lang button[aria-pressed="true"] {
    color: var(--red); font-weight: 600;
}
.hdr__lang span { padding: 6px 2px; color: inherit; opacity: .4; }
@media (min-width: 680px) { .hdr__lang { display: inline-flex; } }

.hdr__icon {
    width: 40px; height: 40px;
    display: inline-flex; align-items: center; justify-content: center;
    color: #fff; border-radius: var(--r-pill);
    transition: background .2s, color .35s;
}
.hdr.is-scrolled .hdr__icon { color: var(--ink); }
.hdr__icon:hover { background: rgba(255, 255, 255, .14); }
.hdr.is-scrolled .hdr__icon:hover { background: var(--line-2); }
.hdr__icon svg { width: 18px; height: 18px; }

.hdr__cta {
    display: none; align-items: center; gap: 8px;
    padding: 10px 20px; border-radius: var(--r-pill);
    background: var(--red); color: #fff;
    font-weight: 500; font-size: 14px;
    transition: transform .2s, background .2s;
}
.hdr__cta:hover { background: var(--red-2); transform: translateY(-1px); }
@media (min-width: 780px) { .hdr__cta { display: inline-flex; } }

/* ─── Hero ────────────────────────────────────────────────────── */
.hero {
    position: relative; overflow: hidden;
    height: 100svh; min-height: 600px; max-height: 1000px;
    color: #fff;
    isolation: isolate;
}
.hero__media {
    position: absolute; inset: 0; z-index: -1;
}
.hero__media img {
    width: 100%; height: 100%; object-fit: cover;
    animation: heroZoom 18s ease-out forwards;
}
@keyframes heroZoom {
    from { transform: scale(1.08); }
    to   { transform: scale(1.00); }
}
.hero__media::after {
    content: "";
    position: absolute; inset: 0;
    background:
        linear-gradient(180deg, rgba(31, 24, 16, .35) 0%, transparent 25%, transparent 55%, rgba(31, 24, 16, .85) 100%),
        linear-gradient(190deg, rgba(31, 24, 16, .25), transparent 40%);
}
.hero__inner {
    position: relative; z-index: 1;
    height: 100%;
    display: flex; flex-direction: column; justify-content: flex-end;
    padding: 0 var(--s-4) var(--s-8);
    max-width: 1240px; margin: 0 auto;
}
.hero__eyebrow {
    display: inline-flex; gap: var(--s-3); align-items: center;
    font-size: 12px; font-weight: 500;
    letter-spacing: .22em; text-transform: uppercase;
    color: rgba(255, 255, 255, .9);
    margin-bottom: var(--s-4);
    padding-bottom: var(--s-3);
}
.hero__eyebrow b { font-weight: 500; }
.hero__eyebrow span { width: 4px; height: 4px; border-radius: 50%; background: var(--red-soft); }
.hero h1 {
    margin: 0 0 var(--s-5);
    font-family: var(--serif); font-weight: 400;
    font-size: clamp(44px, 9vw, 104px); line-height: 0.95;
    letter-spacing: -.02em; color: #fff;
    max-width: 12ch;
}
.hero h1 em { font-style: italic; color: var(--red-soft); font-weight: 300; }
.hero__lead {
    max-width: 540px; margin: 0 0 var(--s-6);
    font-size: clamp(16px, 2.2vw, 20px); line-height: 1.55;
    color: rgba(255, 255, 255, .92);
}
.hero__cta {
    display: flex; gap: var(--s-3); flex-wrap: wrap;
}
.hero__scroll {
    position: absolute; bottom: var(--s-5); left: 50%;
    transform: translateX(-50%);
    color: rgba(255, 255, 255, .7); font-size: 11px;
    letter-spacing: .2em; text-transform: uppercase;
    display: none; flex-direction: column; align-items: center; gap: var(--s-3);
}
@media (min-width: 740px) { .hero__scroll { display: flex; } }
.hero__scroll::after {
    content: ""; width: 1px; height: 60px;
    background: linear-gradient(180deg, rgba(255,255,255,.8), transparent);
    animation: scrollLine 2.4s ease-in-out infinite;
}
@keyframes scrollLine {
    0%, 100% { transform: scaleY(.4); transform-origin: top; }
    50%      { transform: scaleY(1);  transform-origin: top; }
}

/* ─── Marquee strip ──────────────────────────────────────────── */
.strip {
    background: var(--ink); color: var(--paper);
    padding: var(--s-4) 0;
    overflow: hidden;
}
.strip__track {
    display: flex; gap: var(--s-7); align-items: center;
    font-family: var(--serif); font-style: italic; font-size: 22px;
    white-space: nowrap;
    animation: marquee 28s linear infinite;
}
.strip__track span:nth-child(even) { color: var(--red-soft); font-style: normal; font-family: var(--sans); font-size: 13px; letter-spacing: .25em; text-transform: uppercase; }
@keyframes marquee {
    from { transform: translateX(0); }
    to   { transform: translateX(-50%); }
}

/* ─── Section base ───────────────────────────────────────────── */
section { padding: var(--s-9) 0; position: relative; }
@media (max-width: 740px) { section { padding: var(--s-8) 0; } }

.section__head { margin-bottom: var(--s-7); max-width: 820px; }
.section__head .lead { margin-top: var(--s-4); }

.reveal { opacity: 0; transform: translateY(28px); transition: opacity .9s ease, transform .9s ease; }
.reveal.is-in { opacity: 1; transform: translateY(0); }
@media (prefers-reduced-motion: reduce) {
    .reveal { opacity: 1; transform: none; transition: none; }
    .hero__media img, .hero__scroll::after, .strip__track { animation: none; }
}

/* ─── Tonight (афиша) ────────────────────────────────────────── */
.tonight { background: var(--paper); }
.tonight__hero {
    position: relative; overflow: hidden;
    border-radius: var(--r-lg);
    background: var(--ink); color: var(--paper);
    margin-bottom: var(--s-5);
    isolation: isolate;
}
.tonight__hero-bg {
    position: absolute; inset: 0; z-index: -1;
    opacity: .35;
}
.tonight__hero-bg img { width: 100%; height: 100%; object-fit: cover; filter: blur(2px); }
.tonight__hero-inner {
    display: grid; gap: var(--s-5); align-items: center;
    padding: var(--s-7) var(--s-5);
    grid-template-columns: 1fr;
}
@media (min-width: 720px) {
    .tonight__hero-inner {
        grid-template-columns: auto 1fr auto;
        padding: var(--s-8) var(--s-7);
    }
}
.tonight__day {
    font-family: var(--serif); font-size: clamp(48px, 8vw, 86px);
    font-weight: 400; font-style: italic; line-height: 0.95;
    color: var(--red-soft);
}
.tonight__info h3 {
    margin: 0 0 var(--s-2);
    font-family: var(--serif); font-weight: 500;
    font-size: clamp(26px, 4vw, 38px); line-height: 1.15;
    color: #fff;
}
.tonight__info p { margin: 0; color: rgba(255, 255, 255, .85); font-size: 17px; }
.tonight__info .badge {
    display: inline-block; margin-bottom: var(--s-2);
    font-size: 11px; letter-spacing: .22em; text-transform: uppercase;
    color: var(--red-soft);
}

.tonight__week {
    display: grid; gap: var(--s-2);
    grid-template-columns: repeat(7, 1fr);
}
@media (max-width: 740px) {
    .tonight__week { grid-template-columns: repeat(4, 1fr); }
}
.tonight__day-card {
    padding: var(--s-3); border-radius: var(--r-md);
    background: var(--paper-2);
    border: 1px solid transparent;
    transition: background .2s, border-color .2s, transform .2s;
}
.tonight__day-card:hover { transform: translateY(-2px); border-color: var(--red-soft); }
.tonight__day-card.is-today {
    background: #fff; border-color: var(--red);
    box-shadow: var(--shadow-soft);
}
.tonight__day-card-name {
    font-size: 11px; letter-spacing: .22em; text-transform: uppercase;
    color: var(--ink-3); font-weight: 500; margin-bottom: 4px;
}
.tonight__day-card.is-today .tonight__day-card-name { color: var(--red); }
.tonight__day-card-title {
    font-family: var(--serif); font-size: 17px; font-weight: 500;
    color: var(--ink); line-height: 1.2; margin-bottom: 4px;
}
.tonight__day-card-time { font-size: 12px; color: var(--ink-3); }
.tonight__free {
    text-align: center; margin-top: var(--s-5);
    font-style: italic; color: var(--ink-3); font-family: var(--serif); font-size: 19px;
}

/* ─── Three worlds (большие полотна) ──────────────────────────── */
.worlds { background: var(--paper-2); }
.world {
    display: grid; gap: var(--s-6); align-items: center;
    grid-template-columns: 1fr;
    margin-bottom: var(--s-9);
}
.world:last-child { margin-bottom: 0; }
@media (min-width: 780px) {
    .world { grid-template-columns: 1fr 1fr; gap: var(--s-8); }
    .world--reverse .world__media { order: 2; }
}
.world__media {
    border-radius: var(--r-lg); overflow: hidden;
    box-shadow: var(--shadow-md);
    aspect-ratio: 4/5;
}
.world__media img { width: 100%; height: 100%; object-fit: cover; }
.world__text .num {
    font-family: var(--serif); font-size: 14px; font-style: italic;
    color: var(--red); margin-bottom: var(--s-3);
    display: flex; align-items: center; gap: var(--s-3);
}
.world__text .num::after { content: ""; width: 40px; height: 1px; background: var(--red); }
.world__text h3 {
    font-family: var(--serif); font-weight: 500;
    font-size: clamp(28px, 4.5vw, 48px); line-height: 1.1;
    margin: 0 0 var(--s-4); color: var(--ink);
    letter-spacing: -.005em;
}
.world__text h3 em { font-style: italic; color: var(--red); font-weight: 400; }
.world__text p { font-size: 17px; line-height: 1.65; color: var(--ink-2); margin: 0 0 var(--s-5); }
.world__list {
    list-style: none; padding: 0; margin: 0 0 var(--s-5);
    display: flex; flex-wrap: wrap; gap: var(--s-2);
}
.world__list li {
    font-size: 13px; padding: 6px 12px; border-radius: var(--r-pill);
    background: var(--paper); color: var(--ink-2); border: 1px solid var(--line);
}
.world__cta { display: flex; gap: var(--s-3); flex-wrap: wrap; }

/* ─── Bento gallery (атмосфера) ──────────────────────────────── */
.bento { background: var(--paper); }
.bento__grid {
    display: grid; gap: var(--s-3);
    grid-template-columns: repeat(2, 1fr);
}
@media (min-width: 780px) {
    .bento__grid {
        grid-template-columns: repeat(6, 1fr);
        grid-auto-rows: 170px;
        gap: var(--s-4);
    }
}
.bento__cell {
    border-radius: var(--r-md); overflow: hidden;
    position: relative;
    aspect-ratio: 3/4;
    box-shadow: var(--shadow-soft);
    transition: transform .35s ease, box-shadow .35s ease;
}
@media (min-width: 780px) { .bento__cell { aspect-ratio: auto; } }
.bento__cell:hover { transform: translateY(-3px); box-shadow: var(--shadow-md); }
.bento__cell img { width: 100%; height: 100%; object-fit: cover; transition: transform 1s ease; }
.bento__cell:hover img { transform: scale(1.04); }
@media (min-width: 780px) {
    .bento__cell:nth-child(1) { grid-column: span 3; grid-row: span 2; }
    .bento__cell:nth-child(2) { grid-column: span 3; grid-row: span 2; }
    .bento__cell:nth-child(3) { grid-column: span 2; grid-row: span 2; }
    .bento__cell:nth-child(4) { grid-column: span 2; grid-row: span 2; }
    .bento__cell:nth-child(5) { grid-column: span 2; grid-row: span 2; }
}
.bento__quote {
    grid-column: span 2;
    padding: var(--s-5);
    display: flex; flex-direction: column; justify-content: center;
    background: var(--paper-2); border-radius: var(--r-md);
}
@media (min-width: 780px) { .bento__quote { grid-column: span 3; grid-row: span 1; } }
.bento__quote p {
    font-family: var(--serif); font-style: italic;
    font-size: clamp(18px, 2.2vw, 24px); line-height: 1.35;
    color: var(--ink-2); margin: 0 0 var(--s-3);
}
.bento__quote cite {
    font-size: 12px; color: var(--ink-3);
    letter-spacing: .12em; text-transform: uppercase;
    font-style: normal; font-weight: 500;
}

/* ─── Gazebos ────────────────────────────────────────────────── */
.gazebos {
    background: var(--paper-2);
    padding-bottom: 0;
}
.gazebos__split {
    display: grid; gap: var(--s-6); align-items: stretch;
    grid-template-columns: 1fr;
}
@media (min-width: 780px) {
    .gazebos__split { grid-template-columns: 5fr 4fr; gap: 0; }
}
.gazebos__media {
    aspect-ratio: 4/5; overflow: hidden;
    border-radius: var(--r-lg);
}
@media (min-width: 780px) {
    .gazebos__media {
        aspect-ratio: auto; height: 100%; min-height: 580px;
        border-radius: var(--r-lg) 0 0 var(--r-lg);
    }
}
.gazebos__media img { width: 100%; height: 100%; object-fit: cover; }
.gazebos__text {
    padding: var(--s-7) var(--s-5);
    background: var(--ink); color: var(--paper);
}
@media (min-width: 780px) {
    .gazebos__text {
        padding: var(--s-8) var(--s-7);
        border-radius: 0 var(--r-lg) var(--r-lg) 0;
    }
}
.gazebos__text h3 {
    font-family: var(--serif); font-size: clamp(28px, 4.5vw, 44px);
    line-height: 1.1; margin: 0 0 var(--s-4);
    color: #fff; font-weight: 400;
}
.gazebos__text p { color: rgba(245, 239, 228, .8); margin: 0 0 var(--s-5); }

/* ─── Location ───────────────────────────────────────────────── */
.location { background: var(--paper); }
.location__inner {
    display: grid; gap: var(--s-6);
    grid-template-columns: 1fr;
    align-items: center;
}
@media (min-width: 780px) { .location__inner { grid-template-columns: 1fr 1fr; gap: var(--s-8); } }
.location__media {
    aspect-ratio: 4/3; overflow: hidden; border-radius: var(--r-lg);
    box-shadow: var(--shadow-md);
}
.location__media img { width: 100%; height: 100%; object-fit: cover; }
.location__facts ul { list-style: none; padding: 0; margin: var(--s-5) 0; }
.location__facts li {
    display: flex; gap: var(--s-3); padding: var(--s-3) 0;
    border-bottom: 1px solid var(--line-2);
    font-size: 16px; color: var(--ink-2);
}
.location__facts li:last-child { border-bottom: 0; }
.location__facts svg { width: 20px; height: 20px; color: var(--red); flex-shrink: 0; margin-top: 2px; }

/* ─── Footer ─────────────────────────────────────────────────── */
.ftr {
    background: var(--ink); color: var(--paper);
    padding: var(--s-9) 0 calc(var(--s-7) + 90px);
}
.ftr__top {
    display: grid; gap: var(--s-6);
    grid-template-columns: 1fr;
    margin-bottom: var(--s-7);
    padding-bottom: var(--s-6);
    border-bottom: 1px solid rgba(245, 239, 228, .12);
}
@media (min-width: 600px) { .ftr__top { grid-template-columns: 1fr 1fr; } }
@media (min-width: 900px) { .ftr__top { grid-template-columns: 2fr 1fr 1fr 1fr; gap: var(--s-7); } }
.ftr__brand {
    font-family: 'Cinzel', Georgia, "Times New Roman", serif;
    font-weight: 700; font-size: 28px;
    letter-spacing: 0.14em;
    color: #fff; margin-bottom: var(--s-3);
}
.ftr__tagline { color: rgba(245, 239, 228, .7); font-size: 15px; margin-bottom: var(--s-4); }
.ftr__socials { display: flex; gap: var(--s-2); }
.ftr__socials a {
    width: 40px; height: 40px; border-radius: var(--r-pill);
    background: rgba(245, 239, 228, .08);
    color: #fff;
    display: inline-flex; align-items: center; justify-content: center;
    transition: background .2s, transform .2s;
}
.ftr__socials a:hover { background: var(--red); transform: translateY(-2px); }
.ftr__socials svg { width: 18px; height: 18px; }
.ftr h4 {
    font-family: var(--sans); font-weight: 500; font-size: 13px;
    letter-spacing: .2em; text-transform: uppercase;
    margin: 0 0 var(--s-3); color: rgba(245, 239, 228, .6);
}
.ftr ul { list-style: none; padding: 0; margin: 0; }
.ftr ul li { padding: 6px 0; }
.ftr ul a { color: rgba(245, 239, 228, .9); transition: color .2s; }
.ftr ul a:hover { color: var(--red-soft); }
.ftr__bottom {
    text-align: center; color: rgba(245, 239, 228, .5); font-size: 13px;
}

/* ─── Sticky mobile bottom CTA ──────────────────────────────── */
.mob-cta {
    position: fixed; bottom: 0; left: 0; right: 0; z-index: 60;
    padding: 10px 12px calc(10px + env(safe-area-inset-bottom));
    background: rgba(245, 239, 228, .96);
    backdrop-filter: blur(14px);
    -webkit-backdrop-filter: blur(14px);
    border-top: 1px solid var(--line);
    display: flex; gap: var(--s-2);
}
.mob-cta a {
    flex: 1; display: inline-flex; align-items: center; justify-content: center; gap: 6px;
    padding: 14px; border-radius: var(--r-pill); font-weight: 500; font-size: 15px;
    min-height: 50px;
}
.mob-cta__primary { background: var(--red); color: #fff; }
.mob-cta__secondary {
    background: transparent; color: var(--ink);
    border: 1px solid var(--ink);
}
@media (min-width: 780px) { .mob-cta { display: none; } }
</style>
</head>
<body>

<!-- ─── Header ─────────────────────────────────────────────────── -->
<header class="hdr" id="hdr">
    <div class="container hdr__row">
        <a class="hdr__brand" href="<?= $h($canonicalUrl) ?>">VERANDA</a>
        <div class="hdr__actions">
            <div class="hdr__lang" role="group" aria-label="Язык">
                <button type="button" aria-pressed="true"  data-lang="ru">RU</button><span>·</span>
                <button type="button" aria-pressed="false" data-lang="en">EN</button><span>·</span>
                <button type="button" aria-pressed="false" data-lang="vi">VI</button><span>·</span>
                <button type="button" aria-pressed="false" data-lang="ko">KO</button>
            </div>
            <a class="hdr__icon" href="<?= $h($whatsappUrl) ?>" target="_blank" rel="noopener" aria-label="WhatsApp"><?= $icon['wa'] ?></a>
            <a class="hdr__icon" href="<?= $h($telegramUrl) ?>" target="_blank" rel="noopener" aria-label="Telegram"><?= $icon['tg'] ?></a>
            <a class="hdr__cta" href="<?= $h($reserveUrl) ?>">Забронировать</a>
        </div>
    </div>
</header>

<!-- ─── Hero ───────────────────────────────────────────────────── -->
<section class="hero" aria-label="Главная">
    <div class="hero__media" aria-hidden="true">
        <?= v_img('hero-terrace', 'Терраса Veranda с красными фонарями и видом на горы Нячанга', '100vw', true) ?>
    </div>
    <div class="hero__inner">
        <div class="hero__eyebrow">
            <b>Veranda Restaurant &amp; Bar</b><span></span><b>Nha Trang · Vietnam</b>
        </div>
        <h1>Целый вечер<br><em>в горах</em><br>Нячанга</h1>
        <p class="hero__lead">Ресторан с домашней кухней, баня на дровах, игры для всей семьи, живая музыка и кино под звёздами — на одной локации, в 10 минутах от центра города.</p>
        <div class="hero__cta">
            <a class="btn btn--red"          href="<?= $h($reserveUrl) ?>">Забронировать столик <?= $icon['arrow'] ?></a>
            <a class="btn btn--ghost-light"  href="#tonight">Что сегодня вечером</a>
        </div>
    </div>
    <div class="hero__scroll" aria-hidden="true"><span>Scroll</span></div>
</section>

<!-- ─── Marquee strip ─────────────────────────────────────────── -->
<div class="strip" aria-hidden="true">
    <div class="strip__track">
        <span>Ресторан</span><span>·</span>
        <span>Баня на дровах</span><span>·</span>
        <span>Archery Tag · Лазертаг</span><span>·</span>
        <span>Детский клуб</span><span>·</span>
        <span>Live Music</span><span>·</span>
        <span>Кино под звёздами</span><span>·</span>
        <span>Ресторан</span><span>·</span>
        <span>Баня на дровах</span><span>·</span>
        <span>Archery Tag · Лазертаг</span><span>·</span>
        <span>Детский клуб</span><span>·</span>
        <span>Live Music</span><span>·</span>
        <span>Кино под звёздами</span><span>·</span>
    </div>
</div>

<!-- ─── Tonight — афиша ───────────────────────────────────────── -->
<section id="tonight" class="tonight">
    <div class="container">
        <div class="section__head reveal">
            <span class="eyebrow">Живая афиша</span>
            <h2 class="h-section">Что сегодня <em>вечером</em></h2>
            <p class="lead">Каждый день недели — своё настроение. Вход на события свободный, столик стоит забронировать заранее.</p>
        </div>

        <div class="tonight__hero reveal">
            <div class="tonight__hero-bg" aria-hidden="true">
                <?= v_img('lanterns-city', '', '100vw') ?>
            </div>
            <div class="tonight__hero-inner">
                <div class="tonight__day" id="tonightDay">—</div>
                <div class="tonight__info">
                    <span class="badge">Сегодня вечером</span>
                    <h3 id="tonightTitle">…</h3>
                    <p id="tonightNote">…</p>
                </div>
                <a class="btn btn--red" href="<?= $h($reserveUrl) ?>">Забронировать <?= $icon['arrow'] ?></a>
            </div>
        </div>

        <div class="tonight__week reveal">
            <?php foreach ([1, 2, 3, 4, 5, 6, 0] as $d): $ev = $events[$d]; ?>
            <div class="tonight__day-card" data-day="<?= $d ?>">
                <div class="tonight__day-card-name"><?= $h($dayNames[$d]) ?></div>
                <div class="tonight__day-card-title"><?= $h($ev['title']) ?></div>
                <div class="tonight__day-card-time"><?= $h($ev['time']) ?></div>
            </div>
            <?php endforeach; ?>
        </div>

        <p class="tonight__free reveal">Вход на все события — свободный</p>
    </div>
</section>

<!-- ─── Three worlds ──────────────────────────────────────────── -->
<section class="worlds">
    <div class="container">
        <div class="section__head reveal">
            <span class="eyebrow">Один комплекс</span>
            <h2 class="h-section">Три мира на одной <em>поляне</em></h2>
            <p class="lead">Позавтракали в тени горных деревьев → днём поиграли, дети заняты в детском клубе → попарились в бане на закате → вечером ужин с живой музыкой или кино под звёздами.</p>
        </div>

        <!-- World 1: Restaurant -->
        <article class="world reveal">
            <div class="world__media"><?= v_img('food-breakfast', 'Завтрак-сет с лососем и омлетом', '(min-width: 780px) 50vw, 100vw') ?></div>
            <div class="world__text">
                <div class="num">01 — Ресторан</div>
                <h3>Домашняя кухня <em>с европейским</em> акцентом</h3>
                <p>Завтраки в тени деревьев, авторские бургеры, блины с творогом, вафли, авторские коктейли, разливное пиво. Готовим то, по чему скучаешь дома — и то, что попробовал в путешествии.</p>
                <ul class="world__list">
                    <li>Завтраки</li><li>Домашняя кухня</li><li>Европейское</li><li>Коктейли</li><li>Свежее пиво</li>
                </ul>
                <div class="world__cta">
                    <a class="btn btn--red"     href="<?= $h($menuUrl) ?>">Открыть меню <?= $icon['arrow'] ?></a>
                    <a class="btn btn--outline" href="<?= $h($reserveUrl) ?>">Забронировать</a>
                </div>
            </div>
        </article>

        <!-- World 2: Banya — reverse -->
        <article class="world world--reverse reveal">
            <div class="world__media"><?= v_img('banya', 'Русская баня на дровах — парная с веником', '(min-width: 780px) 50vw, 100vw') ?></div>
            <div class="world__text">
                <div class="num">02 — Баня «Сила Духа»</div>
                <h3>Настоящая русская баня <em>на дровах</em></h3>
                <p>Партнёр на нашей же поляне. Горячая парная, холодная купель, опытные пармастера, веники, чай с мёдом и квас. После — ужин на веранде, не вставая со стула.</p>
                <ul class="world__list">
                    <li>Парная на дровах</li><li>Холодная купель</li><li>Пармастера</li><li>Чай с мёдом</li>
                </ul>
                <div class="world__cta">
                    <a class="btn btn--red"     href="<?= $h($banyaUrl) ?>" target="_blank" rel="noopener">sila-duha.com <?= $icon['arrow'] ?></a>
                    <a class="btn btn--outline" href="tel:<?= $h($banyaPhone) ?>"><?= $h($banyaDisplay) ?></a>
                </div>
            </div>
        </article>

        <!-- World 3: GameZone -->
        <article class="world reveal">
            <div class="world__media"><?= v_img('gamezone', 'Archery Tag — лучный бой в GameZone', '(min-width: 780px) 50vw, 100vw') ?></div>
            <div class="world__text">
                <div class="num">03 — GameZone</div>
                <h3>Лазертаг, <em>Archery Tag</em>, детский клуб</h3>
                <p>Партнёрский игровой комплекс рядом. Archery Tag — лучный бой как пейнтбол, но безопасный (200 000 ₫/чел, 8–20 игроков, инструктаж перед игрой). Лазертаг, орбизбол, квесты «Форт Боярд», аниматор для детей с 18:00.</p>
                <ul class="world__list">
                    <li>Archery Tag</li><li>Лазертаг</li><li>Квесты</li><li>Детский клуб</li><li>Мастер-классы</li>
                </ul>
                <div class="world__cta">
                    <a class="btn btn--red"     href="<?= $h($gamezoneUrl) ?>" target="_blank" rel="noopener">ru.vn-gamezone.com <?= $icon['arrow'] ?></a>
                    <a class="btn btn--outline" href="<?= $h($whatsappUrl) ?>" target="_blank" rel="noopener">Записаться</a>
                </div>
            </div>
        </article>
    </div>
</section>

<!-- ─── Bento gallery ─────────────────────────────────────────── -->
<section class="bento">
    <div class="container">
        <div class="section__head reveal">
            <span class="eyebrow">Атмосфера</span>
            <h2 class="h-section">Тёплый горный <em>вечер</em></h2>
        </div>
        <div class="bento__grid reveal">
            <div class="bento__cell"><?= v_img('mountain-view',  'Столик с видом на гору',  '(min-width: 780px) 50vw, 50vw') ?></div>
            <div class="bento__cell"><?= v_img('garden-table',   'Столик в саду в розовом обрамлении бугенвиллии',  '(min-width: 780px) 50vw, 50vw') ?></div>
            <div class="bento__cell"><?= v_img('lanterns-city',  'Красные фонари на ветке + высотки Нячанга',  '(min-width: 780px) 33vw, 50vw') ?></div>
            <div class="bento__cell"><?= v_img('garden-path',    'Дорожка в саду с оранжевым зонтиком',     '(min-width: 780px) 33vw, 50vw') ?></div>
            <div class="bento__cell"><?= v_img('hibiscus',       'Жёлтый гибискус крупным планом',         '(min-width: 780px) 33vw, 50vw') ?></div>
            <div class="bento__quote">
                <p>«Поднимаешься от шумного Нячанга по серпантину — и оказываешься в другом мире: ветер, виды, тишина, тепло.»</p>
                <cite>— гость, январь 2026</cite>
            </div>
        </div>
    </div>
</section>

<!-- ─── Gazebos split ─────────────────────────────────────────── -->
<section class="gazebos">
    <div class="container">
        <div class="gazebos__split reveal">
            <div class="gazebos__media"><?= v_img('gazebo-inside', 'Беседка с тканевыми занавесками внутри', '(min-width: 780px) 50vw, 100vw') ?></div>
            <div class="gazebos__text">
                <span class="eyebrow" style="color: var(--red-soft);">Приватно</span>
                <h3>Беседки на компанию</h3>
                <p>Уютные мини-беседки с тканевыми вуалями и низким столом. Хорошо для семейного вечера, дня рождения или просто долгого ужина под звёздами. Можно забронировать заранее.</p>
                <a class="btn btn--light" href="<?= $h($reserveUrl) ?>">Забронировать беседку <?= $icon['arrow'] ?></a>
            </div>
        </div>
    </div>
</section>

<!-- ─── Location ───────────────────────────────────────────────── -->
<section class="location">
    <div class="container">
        <div class="location__inner">
            <div class="location__media reveal"><?= v_img('mountain-view', 'Vid на горы со столика Veranda', '(min-width: 780px) 50vw, 100vw') ?></div>
            <div class="reveal">
                <span class="eyebrow">Как добраться</span>
                <h2 class="h-section">10 минут <em>от центра</em></h2>
                <p class="lead">Veranda стоит на склоне горы — поднимаешься по короткому серпантину и оказываешься в саду с видом на Нячанг.</p>
                <div class="location__facts">
                    <ul>
                        <li><?= $icon['pin'] ?> <span>~10 минут на такси/байке от центра города. Парковка на месте.</span></li>
                        <li><?= $icon['phone'] ?> <span><a href="tel:<?= $h($telephone) ?>" style="color: var(--ink); text-decoration: underline;"><?= $h($telDisplay) ?></a></span></li>
                        <li><?= $icon['arrow'] ?> <span>Открыто ежедневно с 10:00 до 23:00</span></li>
                    </ul>
                </div>
                <div style="display: flex; gap: var(--s-3); flex-wrap: wrap;">
                    <a class="btn btn--red"     href="<?= $h($mapsUrl) ?>" target="_blank" rel="noopener">Построить маршрут <?= $icon['arrow'] ?></a>
                    <a class="btn btn--outline" href="<?= $h($telegramUrl) ?>" target="_blank" rel="noopener">Спросить дорогу</a>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ─── Footer ─────────────────────────────────────────────────── -->
<footer class="ftr">
    <div class="container">
        <div class="ftr__top">
            <div>
                <div class="ftr__brand">VERANDA</div>
                <p class="ftr__tagline">Ресторан, баня и игры на одной поляне в горах Нячанга. Бронирование столика — через сайт.</p>
                <div class="ftr__socials">
                    <a href="<?= $h($whatsappUrl) ?>"  target="_blank" rel="noopener" aria-label="WhatsApp"><?= $icon['wa'] ?></a>
                    <a href="<?= $h($telegramUrl) ?>"  target="_blank" rel="noopener" aria-label="Telegram"><?= $icon['tg'] ?></a>
                    <a href="<?= $h($instagramUrl) ?>" target="_blank" rel="noopener" aria-label="Instagram"><?= $icon['ig'] ?></a>
                    <a href="tel:<?= $h($telephone) ?>" aria-label="Позвонить"><?= $icon['phone'] ?></a>
                </div>
            </div>
            <div>
                <h4>Veranda</h4>
                <ul>
                    <li><a href="<?= $h($reserveUrl) ?>">Бронь столика</a></li>
                    <li><a href="<?= $h($menuUrl) ?>">Меню</a></li>
                    <li><a href="#tonight">Афиша недели</a></li>
                    <li><a href="/links/">Все ссылки</a></li>
                </ul>
            </div>
            <div>
                <h4>Партнёры</h4>
                <ul>
                    <li><a href="<?= $h($banyaUrl) ?>"    target="_blank" rel="noopener">Баня «Сила Духа»</a></li>
                    <li><a href="<?= $h($gamezoneUrl) ?>" target="_blank" rel="noopener">GameZone</a></li>
                </ul>
            </div>
            <div>
                <h4>Контакты</h4>
                <ul>
                    <li><a href="tel:<?= $h($telephone) ?>"><?= $h($telDisplay) ?></a></li>
                    <li><a href="<?= $h($whatsappUrl) ?>" target="_blank" rel="noopener">WhatsApp</a></li>
                    <li>Nha Trang, Việt Nam</li>
                </ul>
            </div>
        </div>
        <div class="ftr__bottom">
            © <?= date('Y') ?> Veranda · Бронирование через <a href="<?= $h($reserveUrl) ?>" style="color: var(--red-soft);">veranda.my/tr3</a>
        </div>
    </div>
</footer>

<!-- ─── Sticky mobile CTA ─────────────────────────────────────── -->
<div class="mob-cta" aria-hidden="false">
    <a class="mob-cta__primary"   href="<?= $h($reserveUrl) ?>">Забронировать <?= $icon['arrow'] ?></a>
    <a class="mob-cta__secondary" href="<?= $h($menuUrl) ?>">Меню</a>
</div>

<script type="application/ld+json"><?= json_encode($jsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>

<script>
(function () {
    'use strict';

    // ── 1. Header scroll state ────────────────────────────────────
    var hdr = document.getElementById('hdr');
    var onScroll = function () {
        if (window.scrollY > 80) hdr.classList.add('is-scrolled');
        else hdr.classList.remove('is-scrolled');
    };
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });

    // ── 2. Tonight (auto-pick day) ────────────────────────────────
    var dayNames = ['Воскресенье','Понедельник','Вторник','Среда','Четверг','Пятница','Суббота'];
    var events = <?= json_encode($events, JSON_UNESCAPED_UNICODE) ?>;
    var today = new Date().getDay();
    var ev = events[today];
    var dayEl   = document.getElementById('tonightDay');
    var titleEl = document.getElementById('tonightTitle');
    var noteEl  = document.getElementById('tonightNote');
    if (dayEl && titleEl && noteEl && ev) {
        dayEl.textContent   = dayNames[today];
        titleEl.textContent = ev.title + ' · ' + ev.time;
        noteEl.textContent  = ev.note;
    }
    document.querySelectorAll('.tonight__day-card').forEach(function (el) {
        if (Number(el.getAttribute('data-day')) === today) el.classList.add('is-today');
    });

    // ── 3. Reveal on scroll ───────────────────────────────────────
    if ('IntersectionObserver' in window && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (e) {
                if (e.isIntersecting) {
                    e.target.classList.add('is-in');
                    io.unobserve(e.target);
                }
            });
        }, { rootMargin: '0px 0px -8% 0px', threshold: 0.05 });
        document.querySelectorAll('.reveal').forEach(function (el) { io.observe(el); });
    } else {
        document.querySelectorAll('.reveal').forEach(function (el) { el.classList.add('is-in'); });
    }

    // ── 4. Language picker (stub) ─────────────────────────────────
    document.querySelectorAll('.hdr__lang button').forEach(function (b) {
        b.addEventListener('click', function () {
            var l = b.getAttribute('data-lang');
            document.querySelectorAll('.hdr__lang button').forEach(function (x) {
                x.setAttribute('aria-pressed', String(x === b));
            });
            if (l !== 'ru') {
                alert('Скоро: ' + l.toUpperCase() + '. Пока главная только на русском.');
                document.querySelectorAll('.hdr__lang button').forEach(function (x) {
                    x.setAttribute('aria-pressed', String(x.getAttribute('data-lang') === 'ru'));
                });
            }
        });
    });
})();
</script>

</body>
</html>
