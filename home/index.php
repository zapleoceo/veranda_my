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
<meta name="theme-color" content="#14100e">
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
/* ─── Veranda /home — premium "evening" dark theme ──────────────
   Тёмная вечерняя база, фирменный «красный фонарей» #c2412c как акцент,
   охра/золото вторичным. Стекло, амбиентные блобы, спот-свет, зерно. */

:root {
    /* Вечерняя база */
    --bg:       #14100e;
    --bg-2:     #0d0a09;
    --bg-3:     #1d1714;
    --ink:      #f4ece1;
    --ink-2:    #cabfae;
    --ink-3:    #978a78;

    /* Фирменный акцент — красный фонарей */
    --red:      #c2412c;
    --red-br:   #e0563b;
    --red-soft: #e7a08f;
    --gold:     #c79a4e;
    --gold-soft:#e6cd9b;

    --line:     rgba(231,160,143,.14);
    --line-gold:rgba(199,154,78,.20);

    --glass:    linear-gradient(180deg, rgba(255,255,255,.055), rgba(255,255,255,.02));
    --glass-hi: linear-gradient(180deg, rgba(255,255,255,.085), rgba(255,255,255,.03));
    --card:     linear-gradient(180deg, #1c1613 0%, #120d0b 100%);
    --card-hi:  linear-gradient(180deg, #261c17 0%, #18110d 100%);

    --r-sm: 10px; --r-md: 16px; --r-lg: 24px; --r-xl: 32px; --r-pill: 999px;
    --s-1: 4px; --s-2: 8px; --s-3: 12px; --s-4: 16px; --s-5: 24px;
    --s-6: 32px; --s-7: 48px; --s-8: 72px; --s-9: 110px;

    --shadow-md:   0 14px 40px rgba(0,0,0,.45);
    --shadow-deep: 0 30px 90px rgba(0,0,0,.6);
    --glow-red:    0 0 0 1px rgba(194,65,44,.4), 0 14px 40px rgba(194,65,44,.28);

    --serif: 'Cormorant Garamond', 'Iowan Old Style', Georgia, serif;
    --sans:  'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, system-ui, sans-serif;
}

/* ─── Reset ──────────────────────────────────────────────────── */
*, *::before, *::after { box-sizing: border-box; }
html { -webkit-text-size-adjust: 100%; scroll-behavior: smooth; }
body {
    margin: 0;
    background: var(--bg);
    color: var(--ink);
    font: 400 17px/1.65 var(--sans);
    text-rendering: optimizeLegibility;
    -webkit-font-smoothing: antialiased;
    overflow-x: hidden;
    position: relative;
}
img { display: block; max-width: 100%; height: auto; }
a { color: inherit; text-decoration: none; }
button { font: inherit; color: inherit; background: none; border: 0; cursor: pointer; }
:focus-visible { outline: 2px solid var(--red-soft); outline-offset: 3px; border-radius: 6px; }
[hidden] { display: none !important; }
::selection { background: rgba(194,65,44,.5); color: #fff; }

.container { max-width: 1240px; margin: 0 auto; padding: 0 var(--s-4); }
.container--narrow { max-width: 920px; }

/* ─── Ambient FX layer (fixed, behind content) ──────────────── */
.fx { position: fixed; inset: 0; z-index: 0; pointer-events: none; overflow: hidden; }
.fx .blob {
    position: absolute; border-radius: 999px; filter: blur(90px);
    will-change: transform;
}
.fx .b1 { width: 560px; height: 560px; top: -180px; right: -160px;
    background: radial-gradient(circle, rgba(194,65,44,.5), transparent 65%);
    opacity: .45; animation: blob-a 16s ease-in-out infinite; }
.fx .b2 { width: 460px; height: 460px; bottom: -160px; left: -140px;
    background: radial-gradient(circle, rgba(199,154,78,.42), transparent 65%);
    opacity: .35; animation: blob-b 21s ease-in-out infinite; }
.fx .b3 { width: 380px; height: 380px; top: 46%; left: 38%;
    background: radial-gradient(circle, rgba(224,86,59,.4), transparent 65%);
    opacity: .25; animation: blob-c 26s ease-in-out infinite; }
.fx .spotlight {
    position: absolute; inset: 0; opacity: .9; mix-blend-mode: screen;
    background: radial-gradient(circle 360px at var(--mx,60%) var(--my,30%),
        rgba(224,120,70,.16), transparent 60%);
    transition: background-position .2s ease;
}
/* film grain + vignette */
body::before {
    content: ''; position: fixed; inset: 0; z-index: 1; pointer-events: none;
    opacity: .05; mix-blend-mode: overlay;
    background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 512 512' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.75' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)'/%3E%3C/svg%3E");
    background-size: 300px 300px;
}
body::after {
    content: ''; position: fixed; inset: 0; z-index: 1; pointer-events: none;
    background: radial-gradient(ellipse at center, transparent 52%, rgba(0,0,0,.55) 100%);
}
/* keep real content above fx */
.hdr, .hero, .strip, .tonight, .worlds, .bento, .gazebos, .location, .ftr, .mob-cta { position: relative; z-index: 2; }

@keyframes blob-a { 0%{transform:translate(0,0) scale(1)} 50%{transform:translate(-46px,34px) scale(1.1)} 100%{transform:translate(0,0) scale(1)} }
@keyframes blob-b { 0%{transform:translate(0,0) scale(1)} 50%{transform:translate(52px,-40px) scale(1.12)} 100%{transform:translate(0,0) scale(1)} }
@keyframes blob-c { 0%{transform:translate(0,0) scale(1)} 50%{transform:translate(-36px,-30px) scale(1.08)} 100%{transform:translate(0,0) scale(1)} }

/* ─── Typography ─────────────────────────────────────────────── */
.serif { font-family: var(--serif); font-weight: 500; }
.eyebrow {
    display: inline-block; font-size: 12px; font-weight: 500;
    letter-spacing: .26em; text-transform: uppercase;
    color: var(--red-soft); margin-bottom: var(--s-3);
}
.h-display {
    font-family: var(--serif); font-weight: 500; line-height: 1.02;
    letter-spacing: -.01em; font-size: clamp(40px, 8vw, 92px); color: var(--ink);
}
.h-display em { font-style: italic; color: var(--red-soft); font-weight: 400; }
.h-section {
    font-family: var(--serif); font-weight: 500; line-height: 1.08;
    letter-spacing: -.005em; font-size: clamp(32px, 5.5vw, 58px);
    color: var(--ink); margin: 0 0 var(--s-4);
}
.h-section em { font-style: italic; color: var(--red-soft); font-weight: 400; }
.lead { font-size: clamp(17px, 2.2vw, 21px); line-height: 1.6; color: var(--ink-2); }
.section__head { max-width: 760px; margin: 0 0 var(--s-7); }

/* ─── Buttons ────────────────────────────────────────────────── */
.btn {
    display: inline-flex; align-items: center; gap: var(--s-2);
    padding: 14px 24px; border-radius: var(--r-pill);
    font-family: var(--sans); font-weight: 500; font-size: 15px;
    letter-spacing: .01em; line-height: 1; cursor: pointer;
    transition: transform .18s ease, box-shadow .25s ease, background .25s ease, border-color .25s ease, color .25s ease;
    white-space: nowrap; border: 1px solid transparent;
}
.btn svg { width: 18px; height: 18px; fill: currentColor; }
.btn:hover { transform: translateY(-2px); }
.btn--red { background: var(--red); color: #fff; box-shadow: var(--glow-red); }
.btn--red:hover { background: var(--red-br); box-shadow: 0 0 0 1px rgba(224,86,59,.5), 0 20px 50px rgba(194,65,44,.4); }
.btn--outline, .btn--ghost-light, .btn--light {
    background: rgba(255,255,255,.04); color: var(--ink);
    border-color: rgba(231,160,143,.28);
    backdrop-filter: blur(10px) saturate(120%); -webkit-backdrop-filter: blur(10px) saturate(120%);
}
.btn--outline:hover, .btn--ghost-light:hover, .btn--light:hover {
    border-color: var(--red-soft); background: rgba(194,65,44,.12);
}

/* ─── Header ─────────────────────────────────────────────────── */
.hdr {
    position: fixed; top: 0; left: 0; right: 0; z-index: 100;
    transition: background .3s ease, backdrop-filter .3s ease, border-color .3s ease;
    border-bottom: 1px solid transparent;
}
.hdr.is-scrolled {
    background: rgba(13,10,9,.72); border-bottom-color: var(--line);
    backdrop-filter: blur(16px) saturate(130%); -webkit-backdrop-filter: blur(16px) saturate(130%);
}
.hdr__row { display: flex; align-items: center; justify-content: space-between; height: 68px; }
.hdr__brand {
    font-family: var(--serif); font-weight: 600; font-size: 26px;
    letter-spacing: .16em; color: var(--ink);
}
.hdr__actions { display: flex; align-items: center; gap: var(--s-3); }
.hdr__lang { display: flex; align-items: center; gap: 4px; color: var(--ink-3); font-size: 13px; }
.hdr__lang button { padding: 4px 4px; color: inherit; opacity: .55; transition: opacity .2s, color .2s; letter-spacing: .04em; }
.hdr__lang button[aria-pressed="true"] { opacity: 1; color: var(--red-soft); }
.hdr__lang button:hover { opacity: 1; }
.hdr__lang span { opacity: .3; }
.hdr__icon {
    width: 38px; height: 38px; border-radius: var(--r-pill);
    display: grid; place-items: center; color: var(--ink-2);
    border: 1px solid var(--line); background: rgba(255,255,255,.03);
    transition: border-color .2s, color .2s, transform .2s;
}
.hdr__icon svg { width: 18px; height: 18px; fill: currentColor; }
.hdr__icon:hover { color: var(--red-soft); border-color: var(--red-soft); transform: translateY(-1px); }
.hdr__cta {
    padding: 10px 20px; border-radius: var(--r-pill); font-size: 14px; font-weight: 500;
    background: var(--red); color: #fff; box-shadow: var(--glow-red); transition: transform .2s, background .2s;
}
.hdr__cta:hover { transform: translateY(-1px); background: var(--red-br); }
@media (max-width: 640px) { .hdr__lang, .hdr__icon { display: none; } }

/* ─── Hero ───────────────────────────────────────────────────── */
.hero { min-height: 100svh; display: flex; align-items: flex-end; overflow: clip; padding: 0 0 var(--s-9); }
.hero__media { position: absolute; inset: 0; z-index: 0; }
.hero__media img { width: 100%; height: 115%; object-fit: cover; transform: translateY(var(--py, 0)); will-change: transform; }
.hero::after {
    content: ''; position: absolute; inset: 0; z-index: 1; pointer-events: none;
    background:
        linear-gradient(180deg, rgba(13,10,9,.55) 0%, rgba(13,10,9,.15) 30%, rgba(13,10,9,.55) 62%, rgba(13,10,9,.96) 100%),
        radial-gradient(120% 80% at 50% 120%, rgba(194,65,44,.35), transparent 60%);
}
.hero__inner { position: relative; z-index: 2; max-width: 1240px; margin: 0 auto; padding: 0 var(--s-4); width: 100%; }
.hero__eyebrow {
    display: inline-flex; align-items: center; gap: 10px; margin-bottom: var(--s-4);
    padding: 8px 16px; border-radius: var(--r-pill);
    border: 1px solid var(--line); background: rgba(0,0,0,.3);
    backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px);
    font-size: 11px; letter-spacing: .22em; text-transform: uppercase; color: var(--ink-2);
}
.hero__eyebrow span { width: 5px; height: 5px; border-radius: 50%; background: var(--red); box-shadow: 0 0 10px var(--red); }
.hero h1 {
    font-family: var(--serif); font-weight: 500; margin: 0 0 var(--s-4);
    font-size: clamp(48px, 11vw, 132px); line-height: .98; letter-spacing: -.02em; color: #fff;
    text-shadow: 0 6px 50px rgba(0,0,0,.6);
}
.hero h1 em { font-style: italic; color: var(--red-soft); font-weight: 400; text-shadow: 0 0 40px rgba(231,160,143,.4); }
.hero__lead { max-width: 560px; color: var(--ink-2); font-size: clamp(16px, 2vw, 20px); }
.hero__cta { display: flex; gap: var(--s-3); flex-wrap: wrap; margin-top: var(--s-6); }
.hero__scroll {
    position: absolute; bottom: var(--s-5); left: 50%; transform: translateX(-50%); z-index: 2;
    font-size: 10px; letter-spacing: .3em; text-transform: uppercase; color: var(--ink-3);
    animation: bob 2.2s ease-in-out infinite;
}
@keyframes bob { 0%,100%{transform:translate(-50%,0)} 50%{transform:translate(-50%,8px)} }

/* ─── Marquee strip ──────────────────────────────────────────── */
.strip {
    border-top: 1px solid var(--line); border-bottom: 1px solid var(--line);
    background: var(--bg-2); overflow: hidden; padding: var(--s-4) 0;
}
.strip__track { display: flex; gap: var(--s-5); white-space: nowrap; width: max-content; animation: marquee 32s linear infinite; }
.strip__track span { font-family: var(--serif); font-size: 22px; color: var(--ink-2); font-style: italic; }
.strip__track span:nth-child(even) { color: var(--red); font-family: var(--sans); font-style: normal; font-size: 14px; letter-spacing: .25em; }
@keyframes marquee { to { transform: translateX(-50%); } }

/* ─── Reveal ─────────────────────────────────────────────────── */
.reveal { opacity: 0; transform: translateY(28px); transition: opacity .8s cubic-bezier(.2,.7,.2,1), transform .8s cubic-bezier(.2,.7,.2,1); }
.reveal.is-in { opacity: 1; transform: none; }

/* ─── Tonight ────────────────────────────────────────────────── */
.tonight { padding: var(--s-9) 0; }
.tonight__hero {
    position: relative; border-radius: var(--r-xl); overflow: hidden;
    border: 1px solid var(--line); box-shadow: var(--shadow-deep); margin-bottom: var(--s-5);
}
.tonight__hero-bg { position: absolute; inset: 0; z-index: 0; }
.tonight__hero-bg img { width: 100%; height: 100%; object-fit: cover; }
.tonight__hero::after { content:''; position:absolute; inset:0; z-index:1;
    background: linear-gradient(120deg, rgba(13,10,9,.94) 30%, rgba(13,10,9,.55) 100%); }
.tonight__hero-inner { position: relative; z-index: 2; display: flex; align-items: center; gap: var(--s-5); flex-wrap: wrap; padding: clamp(24px,4vw,48px); }
.tonight__day { font-family: var(--serif); font-style: italic; font-size: clamp(28px,4vw,46px); color: var(--red-soft); }
.tonight__info { flex: 1 1 280px; }
.tonight__info h3 { font-family: var(--serif); font-size: clamp(26px,3.5vw,40px); margin: 8px 0; color: #fff; }
.tonight__info p { color: var(--ink-2); margin: 0; }
.badge {
    display: inline-block; padding: 5px 12px; border-radius: var(--r-pill); font-size: 11px;
    letter-spacing: .18em; text-transform: uppercase; color: var(--red-soft);
    border: 1px solid var(--red-soft); background: rgba(194,65,44,.12);
}
.tonight__week { display: grid; grid-template-columns: repeat(7, 1fr); gap: var(--s-2); }
.tonight__day-card {
    padding: var(--s-3); border-radius: var(--r-md); text-align: center;
    background: var(--card); border: 1px solid var(--line); transition: border-color .2s, transform .2s, background .2s;
}
.tonight__day-card:hover { transform: translateY(-3px); border-color: var(--red-soft); }
.tonight__day-card.is-today { background: linear-gradient(180deg, rgba(194,65,44,.3), rgba(194,65,44,.08)); border-color: var(--red); box-shadow: var(--glow-red); }
.tonight__day-card-name { font-size: 12px; letter-spacing: .12em; text-transform: uppercase; color: var(--ink-3); }
.tonight__day-card-title { font-family: var(--serif); font-size: 17px; margin: 6px 0 2px; color: var(--ink); }
.tonight__day-card-time { font-size: 12px; color: var(--red-soft); }
.tonight__free { text-align: center; margin-top: var(--s-5); color: var(--ink-3); font-size: 14px; letter-spacing: .04em; }

/* ─── Worlds ─────────────────────────────────────────────────── */
.worlds { padding: var(--s-9) 0; }
.world { display: grid; grid-template-columns: 1fr; gap: var(--s-5); align-items: center; margin-bottom: var(--s-8); }
.world__media { border-radius: var(--r-lg); overflow: hidden; border: 1px solid var(--line-gold); box-shadow: var(--shadow-deep); position: relative; }
.world__media img { width: 100%; aspect-ratio: 4/3; object-fit: cover; transition: transform .9s cubic-bezier(.2,.7,.2,1); }
.world__media::after { content:''; position:absolute; inset:0; box-shadow: inset 0 0 80px rgba(0,0,0,.4); pointer-events:none; }
.world:hover .world__media img { transform: scale(1.05); }
.world .num { font-family: var(--serif); font-style: italic; font-size: 18px; color: var(--gold); letter-spacing: .04em; margin-bottom: var(--s-2); }
.world h3 { font-family: var(--serif); font-weight: 500; font-size: clamp(26px,3.4vw,42px); line-height: 1.1; margin: 0 0 var(--s-3); color: #fff; }
.world h3 em { font-style: italic; color: var(--red-soft); }
.world__text p { color: var(--ink-2); margin: 0 0 var(--s-4); }
.world__list { list-style: none; padding: 0; margin: 0 0 var(--s-5); display: flex; flex-wrap: wrap; gap: var(--s-2); }
.world__list li {
    font-size: 13px; color: var(--ink-2); padding: 6px 14px; border-radius: var(--r-pill);
    border: 1px solid var(--line); background: rgba(255,255,255,.03);
}
.world__cta { display: flex; gap: var(--s-3); flex-wrap: wrap; }
@media (min-width: 880px) {
    .world { grid-template-columns: 1.05fr .95fr; gap: var(--s-8); }
    .world--reverse .world__media { order: 2; }
}

/* ─── Bento ──────────────────────────────────────────────────── */
.bento { padding: var(--s-9) 0; }
.bento__grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: var(--s-3); }
.bento__cell { border-radius: var(--r-md); overflow: hidden; border: 1px solid var(--line); position: relative; }
.bento__cell img { width: 100%; height: 100%; object-fit: cover; transition: transform .8s cubic-bezier(.2,.7,.2,1); }
.bento__cell:hover img { transform: scale(1.07); }
.bento__quote {
    grid-column: 1 / -1; padding: clamp(24px,4vw,44px); border-radius: var(--r-md);
    background: var(--card); border: 1px solid var(--line-gold); display: flex; flex-direction: column; justify-content: center;
}
.bento__quote p { font-family: var(--serif); font-style: italic; font-size: clamp(20px,3vw,30px); line-height: 1.3; margin: 0 0 var(--s-3); color: var(--ink); }
.bento__quote p::before { content: '“'; color: var(--gold); font-size: 1.4em; margin-right: 4px; }
.bento__quote cite { font-style: normal; font-size: 13px; color: var(--ink-3); letter-spacing: .08em; }
@media (min-width: 780px) {
    .bento__grid { grid-template-columns: repeat(3, 1fr); grid-auto-rows: 200px; }
    .bento__cell:nth-child(1) { grid-column: span 2; grid-row: span 2; }
    .bento__cell:nth-child(2) { grid-row: span 2; }
    .bento__quote { grid-column: span 2; grid-row: span 1; }
}

/* ─── Gazebos split ──────────────────────────────────────────── */
.gazebos { padding: var(--s-8) 0; }
.gazebos__split { display: grid; grid-template-columns: 1fr; gap: var(--s-5); align-items: center;
    background: var(--card); border: 1px solid var(--line); border-radius: var(--r-xl); overflow: hidden; }
.gazebos__media img { width: 100%; height: 100%; min-height: 280px; object-fit: cover; }
.gazebos__text { padding: clamp(24px,4vw,52px); }
.gazebos__text h3 { font-family: var(--serif); font-size: clamp(26px,3.4vw,40px); margin: 8px 0 var(--s-3); color: #fff; }
.gazebos__text p { color: var(--ink-2); margin: 0 0 var(--s-5); }
@media (min-width: 820px) { .gazebos__split { grid-template-columns: 1fr 1fr; } }

/* ─── Location ───────────────────────────────────────────────── */
.location { padding: var(--s-9) 0; }
.location__inner { display: grid; grid-template-columns: 1fr; gap: var(--s-6); align-items: center; }
.location__media { border-radius: var(--r-lg); overflow: hidden; border: 1px solid var(--line); box-shadow: var(--shadow-deep); }
.location__media img { width: 100%; aspect-ratio: 4/3; object-fit: cover; }
.location__facts { margin: var(--s-5) 0; }
.location__facts ul { list-style: none; padding: 0; margin: 0; display: grid; gap: var(--s-3); }
.location__facts li { display: flex; align-items: flex-start; gap: var(--s-3); color: var(--ink-2); }
.location__facts svg { width: 22px; height: 22px; fill: var(--red-soft); flex: 0 0 auto; margin-top: 2px; }
@media (min-width: 880px) { .location__inner { grid-template-columns: 1fr 1fr; } }

/* ─── Footer ─────────────────────────────────────────────────── */
.ftr { padding: var(--s-9) 0 calc(var(--s-9) + 70px); border-top: 1px solid var(--line); background: var(--bg-2); }
.ftr__top { display: grid; grid-template-columns: 1fr; gap: var(--s-6); }
.ftr__brand { font-family: var(--serif); font-weight: 600; font-size: 30px; letter-spacing: .14em; color: var(--ink); }
.ftr__tagline { color: var(--ink-3); max-width: 320px; margin: var(--s-3) 0 var(--s-4); font-size: 15px; }
.ftr__socials { display: flex; gap: var(--s-2); }
.ftr__socials a { width: 40px; height: 40px; border-radius: var(--r-pill); display: grid; place-items: center;
    border: 1px solid var(--line); color: var(--ink-2); transition: color .2s, border-color .2s, transform .2s; }
.ftr__socials svg { width: 18px; height: 18px; fill: currentColor; }
.ftr__socials a:hover { color: var(--red-soft); border-color: var(--red-soft); transform: translateY(-2px); }
.ftr h4 { font-family: var(--sans); font-size: 12px; letter-spacing: .14em; text-transform: uppercase; color: var(--ink-3); margin: 0 0 var(--s-3); }
.ftr ul { list-style: none; padding: 0; margin: 0; display: grid; gap: var(--s-2); }
.ftr li a, .ftr li { color: var(--ink-2); font-size: 15px; transition: color .2s; }
.ftr li a:hover { color: var(--red-soft); }
.ftr__bottom { margin-top: var(--s-7); padding-top: var(--s-4); border-top: 1px solid var(--line); color: var(--ink-3); font-size: 13px; }
.ftr__bottom a { color: var(--red-soft); }
@media (min-width: 720px) { .ftr__top { grid-template-columns: 2fr 1fr 1fr 1fr; } }

/* ─── Sticky mobile CTA ──────────────────────────────────────── */
.mob-cta {
    position: fixed; bottom: 0; left: 0; right: 0; z-index: 90; display: flex; gap: var(--s-2);
    padding: var(--s-3) var(--s-4); padding-bottom: calc(var(--s-3) + env(safe-area-inset-bottom));
    background: rgba(13,10,9,.85); backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px);
    border-top: 1px solid var(--line);
}
.mob-cta a { flex: 1; text-align: center; justify-content: center; padding: 14px; border-radius: var(--r-pill); font-weight: 500; font-size: 15px; display: inline-flex; align-items: center; gap: 6px; }
.mob-cta__primary { background: var(--red); color: #fff; }
.mob-cta__primary svg { width: 16px; height: 16px; fill: currentColor; }
.mob-cta__secondary { background: rgba(255,255,255,.06); color: var(--ink); border: 1px solid var(--line); }
@media (min-width: 780px) { .mob-cta { display: none; } }

/* ─── Reduced motion ─────────────────────────────────────────── */
@media (prefers-reduced-motion: reduce) {
    *, *::before, *::after { animation-duration: .001ms !important; animation-iteration-count: 1 !important; transition-duration: .001ms !important; scroll-behavior: auto !important; }
    .reveal { opacity: 1; transform: none; }
    .hero__media img { transform: none !important; }
}
</style>
</head>
<body>

<div class="fx" aria-hidden="true">
    <span class="blob b1"></span>
    <span class="blob b2"></span>
    <span class="blob b3"></span>
    <span class="spotlight"></span>
</div>

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

    // ── 5. Cursor spotlight + hero parallax ───────────────────────
    var spot = document.querySelector('.fx .spotlight');
    if (spot && window.matchMedia('(pointer: fine)').matches) {
        window.addEventListener('pointermove', function (e) {
            spot.style.setProperty('--mx', (e.clientX / window.innerWidth * 100).toFixed(1) + '%');
            spot.style.setProperty('--my', (e.clientY / window.innerHeight * 100).toFixed(1) + '%');
        }, { passive: true });
    }
    var heroImg = document.querySelector('.hero__media img');
    if (heroImg && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        window.addEventListener('scroll', function () {
            var y = window.scrollY;
            if (y < window.innerHeight) heroImg.style.setProperty('--py', (y * -0.12).toFixed(1) + 'px');
        }, { passive: true });
    }
})();
</script>

</body>
</html>
