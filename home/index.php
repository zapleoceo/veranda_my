<?php
// /home — самостоятельная главная страница veranda.my (mobile-first).
//
// Архитектурно — отдельный модуль, как links/ или tr3/. Один файл,
// весь CSS и JS inline для минимума HTTP-запросов и мгновенного
// первого пейнта. Поднимает Composer autoloader + env, чтобы
// можно было дернуть Config::baseUrl() и др. вспомогательные классы
// (как делает /links/).
//
// Контент пока только RU. Переключатель языка в шапке стоит, но
// EN/VI/KO ловятся в следующей итерации — каркас и i18n-словарь
// добавим после того как разметку утвердят.

if (!class_exists(\App\Infrastructure\Config::class, false)) {
    require_once __DIR__ . '/../vendor/autoload.php';
    \App\Infrastructure\Config::load(__DIR__ . '/../.env');
}

$supportedLangs = ['ru', 'en', 'vi', 'ko'];
$lang = 'ru'; // пока всегда RU; переключатель в шапке — заглушка

$siteBase     = \App\Infrastructure\Config::baseUrl();
$canonicalUrl = $siteBase . '/home';
$reserveUrl   = $siteBase . '/tr3/';
$menuUrl      = $siteBase . '/links/menu';
$ogImage      = $siteBase . '/assets/img/links_bg.png';

$telephone     = '+84792070707';
$telDisplay    = '+84 792 070 707';
$banyaPhone    = '+84395959140';
$banyaDisplay  = '+84 39 5959 140';
$whatsappUrl   = 'https://wa.me/' . preg_replace('/\D+/', '', $telephone);
$telegramUrl   = 'https://t.me/gamezone_vietnam';
$instagramUrl  = 'https://www.instagram.com/veranda.my/';
$mapsUrl       = 'https://maps.app.goo.gl/'; // TODO: реальная ссылка
$banyaUrl      = 'https://sila-duha.com/';
$gamezoneUrl   = 'https://ru.vn-gamezone.com/';

// Иконки одной коллекцией. Все 24×24, currentColor, для inline.
$icon = [
    'reserve'   => '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M6 2h10l4 4v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2zm8 1.5V7h3.5L14 3.5zM8 13h8v1.5H8zm0 3h5v1.5H8z"/></svg>',
    'menu'      => '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M3 6h18v2H3zm0 5h18v2H3zm0 5h12v2H3z"/></svg>',
    'wa'        => '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M20.1 3.9A11.8 11.8 0 0 0 12 0 12 12 0 0 0 1.6 17.6L0 24l6.6-1.6A12 12 0 0 0 24 12a11.8 11.8 0 0 0-3.9-8.1Zm-2.3 10.6c-.3-.2-1.8-.9-2.1-1s-.5-.2-.7.2-.8 1-.9 1.2-.3.2-.6.1a8.2 8.2 0 0 1-2.4-1.5 9 9 0 0 1-1.7-2.1c-.2-.3 0-.5.1-.6l.5-.6.3-.5a.6.6 0 0 0 0-.5c0-.2-.7-1.7-1-2.3s-.5-.5-.7-.5h-.6a1.2 1.2 0 0 0-.9.4 3.8 3.8 0 0 0-1.2 2.8 6.5 6.5 0 0 0 1.4 3.4 14.8 14.8 0 0 0 5.7 5 6.8 6.8 0 0 0 3.3.9 3.2 3.2 0 0 0 2.1-.9 2.6 2.6 0 0 0 .6-1.9c0-.2-.2-.3-.5-.4z"/></svg>',
    'tg'        => '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M9.6 15.6 9.2 19c.6 0 .9-.2 1.3-.6l3.1-3 6.4 4.6c1.2.7 2 .3 2.3-1.1l4.1-19.1c.4-1.7-.7-2.4-1.9-1.9L1.2 9.2c-1.6.6-1.6 1.5-.3 1.9l6 1.9L20.2 4c.7-.4 1.3-.2.8.3z"/></svg>',
    'ig'        => '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M7.8 2h8.4A5.8 5.8 0 0 1 22 7.8v8.4A5.8 5.8 0 0 1 16.2 22H7.8A5.8 5.8 0 0 1 2 16.2V7.8A5.8 5.8 0 0 1 7.8 2zm0 2A3.8 3.8 0 0 0 4 7.8v8.4A3.8 3.8 0 0 0 7.8 20h8.4A3.8 3.8 0 0 0 20 16.2V7.8A3.8 3.8 0 0 0 16.2 4zm4.2 3.5A4.5 4.5 0 1 1 7.5 12 4.5 4.5 0 0 1 12 7.5zm0 2A2.5 2.5 0 1 0 14.5 12 2.5 2.5 0 0 0 12 9.5zM17.6 6.6a1 1 0 1 1-1 1 1 1 0 0 1 1-1z"/></svg>',
    'phone'     => '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M6.6 10.8c1.5 3 4 5.4 7.1 6.9l2.4-2.4c.3-.3.8-.4 1.2-.2 1.3.5 2.8.8 4.3.8.7 0 1.4.6 1.4 1.4V21c0 .7-.6 1.4-1.4 1.4C10.7 22.4 1.6 13.3 1.6 2.4 1.6 1.6 2.2 1 2.9 1h3.6c.7 0 1.4.6 1.4 1.4 0 1.5.3 3 .8 4.3.1.4 0 .9-.3 1.2z"/></svg>',
    'map'       => '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2a7 7 0 0 0-7 7c0 5.2 7 13 7 13s7-7.8 7-13a7 7 0 0 0-7-7zm0 9.5A2.5 2.5 0 1 1 14.5 9 2.5 2.5 0 0 1 12 11.5z"/></svg>',
    'arrow'     => '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M5 12h13l-5-5 1.4-1.4L21.8 12l-7.4 6.4L13 17l5-5H5z"/></svg>',
    'sparkles'  => '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2 13.5 8 19 9.5 13.5 11 12 17 10.5 11 5 9.5 10.5 8zM19 14l.9 3.1L23 18l-3.1.9L19 22l-.9-3.1L15 18l3.1-.9z"/></svg>',
    'fork'      => '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M11 2h2v8h2V2h2v9a3 3 0 0 1-3 3v8h-2v-8a3 3 0 0 1-3-3V2zm10 0v13h-2v7h-2V2z"/></svg>',
    'flame'     => '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2s-3 4-3 8a3 3 0 0 0 6 0c0-2-1-3-1-3s3 2 3 6a5 5 0 0 1-10 0c0-4 5-11 5-11z"/></svg>',
    'target'    => '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2a10 10 0 1 0 10 10A10 10 0 0 0 12 2zm0 4a6 6 0 1 1-6 6 6 6 0 0 1 6-6zm0 2a4 4 0 1 0 4 4 4 4 0 0 0-4-4zm0 2a2 2 0 1 1-2 2 2 2 0 0 1 2-2z"/></svg>',
    'kids'      => '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="9" cy="11" r="3"/><circle cx="17" cy="13" r="2"/><path d="M3 22a6 6 0 0 1 12 0zm12 0a4 4 0 0 1 8 0z"/></svg>',
    'music'     => '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M20 3v13.5a3.5 3.5 0 1 1-2-3.16V7L9 9v9.5a3.5 3.5 0 1 1-2-3.16V6z"/></svg>',
    'film'      => '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M4 4h2v2H4zm14 0h2v2h-2zM4 8h2v2H4zm14 0h2v2h-2zM4 12h2v2H4zm14 0h2v2h-2zM4 16h2v2H4zm14 0h2v2h-2zM4 20h2v-2H4zm14 0h2v-2h-2zM8 4h8v16H8z"/></svg>',
];

// Афиша недели. day: 0=Sun…6=Sat (как у JS Date.getDay()).
$events = [
    1 => ['title' => 'Мафия в беседке',     'time' => '19:00', 'note' => 'Командная игра под лампочками'],
    2 => ['title' => 'Кино под звёздами',   'time' => '18:00 · 20:00', 'note' => 'Детский и взрослый сеансы'],
    3 => ['title' => 'Live Music',           'time' => '19:00', 'note' => 'Авторская и кавер-программа'],
    4 => ['title' => 'Кино под звёздами',   'time' => '18:00 · 20:00', 'note' => 'Детский и взрослый сеансы'],
    5 => ['title' => 'Live Music',           'time' => '19:00', 'note' => 'BiBi Duo / MRV / TN Band'],
    6 => ['title' => 'Живая музыка',         'time' => '19:00', 'note' => 'The Pennywort, Рядновы и др.'],
    0 => ['title' => 'Вечер живой музыки',   'time' => '19:00', 'note' => 'Уютный воскресный вечер'],
];
$dayNames = [0 => 'Вс', 1 => 'Пн', 2 => 'Вт', 3 => 'Ср', 4 => 'Чт', 5 => 'Пт', 6 => 'Сб'];

$h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

// JSON-LD — Restaurant + LocalBusiness + Event[]
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
<meta name="theme-color" content="#0d1b2a">
<title>Veranda — ресторан, баня и игры в горах Нячанга</title>
<meta name="description" content="Veranda Restaurant &amp; Bar — ресторан в 10 минутах от центра Нячанга. Кухня, баня на дровах, лазертаг и Archery Tag, детский клуб, живая музыка и кино под звёздами. Вход на события свободный.">
<link rel="canonical" href="<?= $h($canonicalUrl) ?>">
<link rel="icon" type="image/svg+xml" href="/links/favicon.svg">

<meta property="og:type" content="website">
<meta property="og:site_name" content="Veranda">
<meta property="og:title" content="Veranda — целый вечер впечатлений в горах Нячанга">
<meta property="og:description" content="Ресторан, баня на дровах, игры для всей семьи, живая музыка и кино под звёздами. 10 минут от центра города.">
<meta property="og:url" content="<?= $h($canonicalUrl) ?>">
<meta property="og:image" content="<?= $h($ogImage) ?>">
<meta name="twitter:card" content="summary_large_image">

<?php include $_SERVER['DOCUMENT_ROOT'] . '/analytics.php'; ?>

<style>
/* ─── Tokens ─────────────────────────────────────────────────── */
:root {
    /* Тёплый горный вечер. Фон — глубокий ночной индиго,
       свет — янтарь + мёд + тёплое золото, дерево — какао. */
    --night-1: #0a1422;
    --night-2: #11233a;
    --night-3: #18324d;
    --indigo:  #1b2942;
    --amber:   #ffb547;
    --honey:   #f59e0b;
    --gold:    #d4af37;
    --warm:    #fff1d6;
    --wood:    #8b6f47;
    --leaf:    #4f7a5b;
    --text:    #f4ead5;
    --muted:   #b9b09a;
    --line:    rgba(255, 241, 214, 0.12);

    --r-sm: 8px;
    --r-md: 14px;
    --r-lg: 22px;
    --r-pill: 999px;

    --s-1: 4px;  --s-2: 8px;  --s-3: 12px; --s-4: 16px;
    --s-5: 24px; --s-6: 32px; --s-7: 48px; --s-8: 72px;

    --fs-xs: 13px; --fs-sm: 15px; --fs-md: 17px;
    --fs-lg: 21px; --fs-xl: 28px; --fs-2xl: 38px; --fs-3xl: 52px;

    --shadow-sm: 0 2px 12px rgba(0, 0, 0, .35);
    --shadow-md: 0 8px 32px rgba(0, 0, 0, .45);
    --glow:      0 0 24px rgba(255, 181, 71, .45);
}

/* ─── Reset ──────────────────────────────────────────────────── */
*, *::before, *::after { box-sizing: border-box; }
html { -webkit-text-size-adjust: 100%; }
body {
    margin: 0;
    background: var(--night-1);
    color: var(--text);
    font: 400 var(--fs-md)/1.55 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
    text-rendering: optimizeLegibility;
    -webkit-font-smoothing: antialiased;
    overflow-x: hidden;
}
img, svg { display: block; max-width: 100%; }
a { color: inherit; text-decoration: none; }
button { font: inherit; color: inherit; background: none; border: 0; cursor: pointer; }
:focus-visible { outline: 2px solid var(--amber); outline-offset: 2px; border-radius: 4px; }
[hidden] { display: none !important; }
.container { max-width: 1100px; margin: 0 auto; padding: 0 var(--s-4); }

/* ─── Sticky header ───────────────────────────────────────────── */
.hdr {
    position: sticky; top: 0; z-index: 50;
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
    background: linear-gradient(180deg, rgba(10, 20, 34, .82), rgba(10, 20, 34, .62));
    border-bottom: 1px solid var(--line);
}
.hdr__row {
    display: flex; align-items: center; justify-content: space-between;
    gap: var(--s-3); padding: var(--s-3) 0;
}
.hdr__brand {
    display: flex; align-items: baseline; gap: var(--s-2);
    font-weight: 600; letter-spacing: .04em;
}
.hdr__brand b { font-size: var(--fs-lg); color: var(--warm); }
.hdr__brand span { font-size: var(--fs-xs); color: var(--muted); display: none; }
@media (min-width: 600px) { .hdr__brand span { display: inline; } }

.hdr__actions { display: flex; align-items: center; gap: var(--s-2); }
.hdr__lang {
    display: inline-flex; gap: 2px; padding: 4px; border-radius: var(--r-pill);
    background: rgba(255, 241, 214, .06); border: 1px solid var(--line);
}
.hdr__lang button {
    padding: 4px 10px; border-radius: var(--r-pill); font-size: var(--fs-xs);
    color: var(--muted); transition: background .2s, color .2s;
}
.hdr__lang button[aria-pressed="true"] { background: var(--amber); color: #2a1a05; font-weight: 600; }
.hdr__icon {
    width: 38px; height: 38px; display: inline-flex; align-items: center; justify-content: center;
    color: var(--warm); border-radius: var(--r-pill);
    transition: background .2s, transform .2s;
}
.hdr__icon:hover { background: rgba(255, 241, 214, .08); transform: translateY(-1px); }
.hdr__icon svg { width: 18px; height: 18px; }
.hdr__cta {
    display: none; align-items: center; gap: 6px;
    padding: 10px 16px; border-radius: var(--r-pill);
    background: linear-gradient(180deg, var(--amber), var(--honey));
    color: #2a1a05; font-weight: 600; font-size: var(--fs-sm);
    box-shadow: var(--shadow-sm), inset 0 1px 0 rgba(255, 255, 255, .3);
    transition: transform .2s, box-shadow .2s;
}
.hdr__cta:hover { transform: translateY(-1px); box-shadow: var(--shadow-md), var(--glow); }
.hdr__cta svg { width: 16px; height: 16px; }
@media (min-width: 740px) { .hdr__cta { display: inline-flex; } }

/* ─── Hero ────────────────────────────────────────────────────── */
.hero {
    position: relative; overflow: hidden;
    min-height: calc(100svh - 64px);
    display: flex; align-items: center; justify-content: center;
    padding: var(--s-7) var(--s-4) var(--s-8);
    background:
        radial-gradient(ellipse 80% 50% at 50% -10%, rgba(255, 181, 71, .15), transparent 70%),
        linear-gradient(180deg, var(--night-1) 0%, var(--night-2) 60%, var(--night-3) 100%);
}
.hero__stars, .hero__stars::after, .hero__stars::before {
    position: absolute; inset: 0; pointer-events: none;
}
/* Многослойное звёздное небо — без картинок, чистый box-shadow на
   одинокий пиксельный псевдоэлемент. 3 слоя × разный размер и плотность
   для глубины и параллакс-эффекта при скролле. */
.hero__stars::before, .hero__stars::after {
    content: ""; width: 2px; height: 2px; border-radius: 50%; background: transparent;
    box-shadow:
        17vw 18vh 0 .3px #fff, 33vw 8vh 0 .2px #fffbe8, 48vw 22vh 0 .3px #fff,
        62vw 6vh 0 .2px #fff7d8, 75vw 19vh 0 .3px #fff, 87vw 11vh 0 .2px #fffbe8,
        9vw  35vh 0 .3px #fff, 22vw 27vh 0 .2px #fffbe8, 41vw 33vh 0 .3px #fff,
        56vw 28vh 0 .3px #fff, 68vw 36vh 0 .2px #fff7d8, 81vw 30vh 0 .3px #fff,
        93vw 38vh 0 .2px #fffbe8, 5vw  44vh 0 .3px #fff, 14vw 52vh 0 .2px #fff,
        29vw 47vh 0 .3px #fff, 38vw 55vh 0 .2px #fff7d8, 52vw 49vh 0 .3px #fff,
        66vw 52vh 0 .2px #fff, 79vw 45vh 0 .3px #fff, 95vw 56vh 0 .2px #fffbe8;
    opacity: .9; animation: twinkle 6s ease-in-out infinite alternate;
}
.hero__stars::after {
    box-shadow:
        4vw 11vh 0 .5px #ffd96b, 27vw 14vh 0 .5px #ffe28a, 55vw 4vh 0 .5px #fff,
        72vw 13vh 0 .5px #ffd96b, 89vw 22vh 0 .5px #fff, 18vw 41vh 0 .5px #ffe28a,
        46vw 38vh 0 .5px #fff, 60vw 44vh 0 .5px #ffd96b, 84vw 41vh 0 .5px #fff;
    animation-delay: -3s; animation-duration: 8s;
}
@keyframes twinkle {
    0%, 100% { opacity: .85; }
    50%      { opacity: 1; }
}

/* Гирлянда лампочек — ряд жёлтых точек вверху hero с тёплым свечением. */
.hero__lanterns {
    position: absolute; top: 12%; left: -5%; right: -5%; height: 24px;
    display: flex; justify-content: space-between; align-items: center;
    pointer-events: none;
}
.hero__lanterns::before {
    content: ""; position: absolute; top: 11px; left: 0; right: 0; height: 1px;
    background: linear-gradient(90deg, transparent, rgba(255, 241, 214, .25), transparent);
}
.hero__lanterns i {
    display: block; width: 9px; height: 9px; border-radius: 50%;
    background: radial-gradient(circle at 35% 35%, #fff5c9, #ffb547 50%, #b87813 100%);
    box-shadow: 0 0 16px 3px rgba(255, 181, 71, .55), 0 0 4px 1px rgba(255, 220, 140, .8);
    animation: bulb 3s ease-in-out infinite;
}
.hero__lanterns i:nth-child(odd)  { animation-delay: -1.5s; }
.hero__lanterns i:nth-child(3n)   { transform: translateY(2px); }
@keyframes bulb {
    0%, 100% { box-shadow: 0 0 16px 3px rgba(255, 181, 71, .55), 0 0 4px 1px rgba(255, 220, 140, .8); }
    50%      { box-shadow: 0 0 24px 5px rgba(255, 181, 71, .85), 0 0 6px 2px rgba(255, 230, 160, 1); }
}

/* Силуэт гор и деревьев на дне hero — чистый CSS clip-path. */
.hero__horizon {
    position: absolute; left: 0; right: 0; bottom: 0;
    height: 35vh; min-height: 180px; pointer-events: none;
    background: linear-gradient(180deg, transparent, rgba(0, 0, 0, .35) 40%, rgba(0, 0, 0, .65));
}
.hero__mountains {
    position: absolute; left: 0; right: 0; bottom: 0; height: 22vh; min-height: 110px;
    background: linear-gradient(180deg, #1a2c3e, #0a1422);
    clip-path: polygon(
        0% 100%, 0% 60%, 8% 35%, 18% 55%, 28% 25%, 38% 50%, 50% 15%,
        62% 45%, 72% 30%, 82% 55%, 92% 35%, 100% 50%, 100% 100%
    );
    opacity: .9;
}
.hero__trees {
    position: absolute; left: 0; right: 0; bottom: 0; height: 10vh; min-height: 60px;
    background: linear-gradient(180deg, #0a1422, #050a12);
    clip-path: polygon(
        0% 100%, 0% 75%, 5% 50%, 8% 70%, 12% 40%, 15% 65%, 20% 45%,
        25% 70%, 30% 35%, 35% 65%, 42% 50%, 48% 30%, 55% 65%, 62% 45%,
        70% 70%, 78% 40%, 85% 60%, 92% 35%, 100% 70%, 100% 100%
    );
}

.hero__inner {
    position: relative; z-index: 2;
    max-width: 760px; text-align: center;
    animation: heroIn 1s cubic-bezier(.2, .8, .2, 1) both;
}
@keyframes heroIn {
    from { opacity: 0; transform: translateY(20px); }
    to   { opacity: 1; transform: translateY(0); }
}
.hero__eyebrow {
    display: inline-flex; align-items: center; gap: 6px;
    font-size: var(--fs-xs); letter-spacing: .14em; text-transform: uppercase;
    color: var(--amber); margin-bottom: var(--s-4);
    padding: 4px 12px; border-radius: var(--r-pill);
    background: rgba(255, 181, 71, .12);
    border: 1px solid rgba(255, 181, 71, .3);
}
.hero__eyebrow svg { width: 14px; height: 14px; }
.hero h1 {
    margin: 0 0 var(--s-4);
    font-size: clamp(var(--fs-2xl), 7vw, var(--fs-3xl));
    line-height: 1.05; font-weight: 700;
    color: var(--warm); letter-spacing: -.01em;
    font-family: Georgia, "Times New Roman", serif;
}
.hero h1 em {
    font-style: normal;
    background: linear-gradient(180deg, var(--amber), var(--gold));
    -webkit-background-clip: text; background-clip: text;
    -webkit-text-fill-color: transparent; color: var(--amber);
    text-shadow: 0 0 30px rgba(255, 181, 71, .35);
}
.hero p {
    margin: 0 auto var(--s-6); max-width: 56ch;
    color: var(--text); opacity: .92; font-size: var(--fs-md);
}
.hero__cta {
    display: flex; gap: var(--s-3); flex-wrap: wrap; justify-content: center;
    margin-bottom: var(--s-5);
}
.btn {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 14px 22px; border-radius: var(--r-pill);
    font-weight: 600; font-size: var(--fs-md);
    transition: transform .2s, box-shadow .2s, background .2s;
    min-height: 48px; /* tap target */
}
.btn--primary {
    background: linear-gradient(180deg, var(--amber), var(--honey));
    color: #2a1a05;
    box-shadow: var(--shadow-md), inset 0 1px 0 rgba(255, 255, 255, .35);
}
.btn--primary:hover { transform: translateY(-2px); box-shadow: var(--shadow-md), var(--glow); }
.btn--ghost {
    background: rgba(255, 241, 214, .08);
    color: var(--warm);
    border: 1px solid rgba(255, 241, 214, .25);
    backdrop-filter: blur(6px);
}
.btn--ghost:hover { background: rgba(255, 241, 214, .14); transform: translateY(-2px); }
.btn svg { width: 18px; height: 18px; }
.hero__micro {
    font-size: var(--fs-xs); color: var(--muted);
    display: inline-flex; align-items: center; gap: 6px;
    padding: 6px 12px; border-radius: var(--r-pill);
    background: rgba(0, 0, 0, .3);
}

/* ─── Section base ────────────────────────────────────────────── */
section { padding: var(--s-8) 0; position: relative; }
.section__head { text-align: center; margin-bottom: var(--s-6); }
.section__eyebrow {
    font-size: var(--fs-xs); letter-spacing: .14em; text-transform: uppercase;
    color: var(--amber); margin-bottom: var(--s-2);
}
.section__title {
    margin: 0; font-family: Georgia, serif; font-weight: 700;
    font-size: clamp(var(--fs-xl), 5vw, var(--fs-2xl));
    color: var(--warm); line-height: 1.15;
}
.section__sub { color: var(--muted); margin: var(--s-3) auto 0; max-width: 56ch; }

.reveal { opacity: 0; transform: translateY(20px); transition: opacity .8s ease, transform .8s ease; }
.reveal.is-in { opacity: 1; transform: translateY(0); }
@media (prefers-reduced-motion: reduce) {
    .reveal { opacity: 1; transform: none; transition: none; }
    .hero__inner, .hero__lanterns i, .hero__stars::before, .hero__stars::after { animation: none; }
}

/* ─── Afisha / events ─────────────────────────────────────────── */
.afisha { background: linear-gradient(180deg, var(--night-1), var(--night-2)); }
.afisha__today {
    background: linear-gradient(135deg, rgba(255, 181, 71, .14), rgba(255, 181, 71, .04));
    border: 1px solid rgba(255, 181, 71, .35);
    border-radius: var(--r-lg);
    padding: var(--s-5);
    margin-bottom: var(--s-5);
    display: flex; flex-wrap: wrap; align-items: center; gap: var(--s-4);
    box-shadow: var(--shadow-md);
    position: relative; overflow: hidden;
}
.afisha__today::before {
    content: ""; position: absolute; top: -50%; right: -20%; width: 60%; height: 200%;
    background: radial-gradient(circle, rgba(255, 181, 71, .15), transparent 60%);
    pointer-events: none;
}
.afisha__today-day {
    font-size: var(--fs-3xl); font-weight: 700; line-height: 1;
    color: var(--amber); font-family: Georgia, serif;
    min-width: 80px;
}
.afisha__today-info { flex: 1; min-width: 220px; }
.afisha__today-info h3 {
    margin: 0 0 4px; font-size: var(--fs-xl); color: var(--warm);
}
.afisha__today-info p { margin: 0; color: var(--text); opacity: .85; }
.afisha__badge {
    display: inline-block; padding: 4px 10px; border-radius: var(--r-pill);
    background: var(--amber); color: #2a1a05; font-size: var(--fs-xs); font-weight: 600;
    margin-bottom: var(--s-2);
}

.afisha__week {
    display: grid; grid-template-columns: repeat(2, 1fr); gap: var(--s-3);
}
@media (min-width: 600px) { .afisha__week { grid-template-columns: repeat(4, 1fr); } }
@media (min-width: 900px) { .afisha__week { grid-template-columns: repeat(7, 1fr); } }
.afisha__day {
    padding: var(--s-3); border-radius: var(--r-md);
    background: rgba(255, 241, 214, .04);
    border: 1px solid var(--line);
    transition: transform .2s, border-color .2s, background .2s;
}
.afisha__day:hover { transform: translateY(-2px); border-color: rgba(255, 181, 71, .35); background: rgba(255, 181, 71, .06); }
.afisha__day.is-today { border-color: var(--amber); background: rgba(255, 181, 71, .1); }
.afisha__day-name {
    font-size: var(--fs-xs); letter-spacing: .1em; text-transform: uppercase;
    color: var(--muted); margin-bottom: 4px;
}
.afisha__day.is-today .afisha__day-name { color: var(--amber); font-weight: 600; }
.afisha__day-title { font-size: var(--fs-sm); color: var(--warm); margin-bottom: 4px; font-weight: 600; }
.afisha__day-time  { font-size: var(--fs-xs); color: var(--muted); }
.afisha__free {
    margin-top: var(--s-5); text-align: center; color: var(--amber);
    font-size: var(--fs-sm); display: inline-flex; align-items: center; gap: 6px;
    width: 100%; justify-content: center;
}

/* ─── Worlds — 5 cards ────────────────────────────────────────── */
.worlds { background: var(--night-2); }
.worlds__grid {
    display: grid; gap: var(--s-4); grid-template-columns: 1fr;
}
@media (min-width: 640px) { .worlds__grid { grid-template-columns: repeat(2, 1fr); } }
@media (min-width: 980px) { .worlds__grid { grid-template-columns: repeat(3, 1fr); } }

.world {
    position: relative; overflow: hidden; border-radius: var(--r-lg);
    padding: var(--s-5); min-height: 240px;
    background: linear-gradient(160deg, var(--night-3), var(--indigo));
    border: 1px solid var(--line);
    display: flex; flex-direction: column; justify-content: space-between;
    transition: transform .25s, border-color .25s, box-shadow .25s;
}
.world:hover { transform: translateY(-3px); border-color: rgba(255, 181, 71, .35); box-shadow: var(--shadow-md); }
.world::after {
    content: ""; position: absolute; inset: 0;
    background: radial-gradient(circle at top right, rgba(255, 181, 71, .12), transparent 60%);
    pointer-events: none; opacity: .8;
}
.world__icon {
    width: 48px; height: 48px; display: inline-flex; align-items: center; justify-content: center;
    border-radius: var(--r-md); background: rgba(255, 181, 71, .14);
    color: var(--amber); margin-bottom: var(--s-3);
}
.world__icon svg { width: 26px; height: 26px; }
.world h3 { margin: 0 0 var(--s-2); font-family: Georgia, serif; font-size: var(--fs-xl); color: var(--warm); }
.world p  { margin: 0 0 var(--s-4); color: var(--text); opacity: .85; font-size: var(--fs-sm); }
.world__cta {
    display: inline-flex; align-items: center; gap: 6px;
    color: var(--amber); font-weight: 600; font-size: var(--fs-sm);
    align-self: flex-start; padding: 8px 0;
}
.world__cta svg { width: 14px; height: 14px; transition: transform .2s; }
.world:hover .world__cta svg { transform: translateX(4px); }
.world__extra {
    font-size: var(--fs-xs); color: var(--muted); margin-top: var(--s-2);
    padding: 6px 10px; border-radius: var(--r-sm);
    background: rgba(0, 0, 0, .25); display: inline-block;
}

/* ─── Breakfast band ──────────────────────────────────────────── */
.breakfast {
    background: linear-gradient(135deg, #1a3326 0%, #0d1b2a 100%);
    border-top: 1px solid var(--line); border-bottom: 1px solid var(--line);
}
.breakfast__inner {
    display: grid; gap: var(--s-5);
    grid-template-columns: 1fr;
    align-items: center;
}
@media (min-width: 740px) { .breakfast__inner { grid-template-columns: 1.2fr 1fr; } }
.breakfast h2 { margin: 0 0 var(--s-3); font-family: Georgia, serif; font-size: clamp(var(--fs-xl), 4vw, var(--fs-2xl)); color: var(--warm); line-height: 1.2; }
.breakfast p { color: var(--text); opacity: .9; margin: 0 0 var(--s-4); }
.breakfast__visual {
    aspect-ratio: 4/3; border-radius: var(--r-lg);
    background:
        radial-gradient(ellipse at 30% 70%, rgba(255, 181, 71, .25), transparent 50%),
        linear-gradient(135deg, #2a4538, #1a3326);
    border: 1px solid var(--line);
    position: relative; overflow: hidden;
}
.breakfast__visual::before {
    content: "☕"; font-size: 64px; position: absolute;
    top: 50%; left: 50%; transform: translate(-50%, -50%);
    opacity: .4;
}

/* ─── Gazebos ─────────────────────────────────────────────────── */
.gazebos { background: var(--night-1); }
.gazebos__row {
    display: grid; gap: var(--s-3);
    grid-template-columns: repeat(2, 1fr);
}
@media (min-width: 740px) { .gazebos__row { grid-template-columns: repeat(4, 1fr); } }
.gazebo {
    aspect-ratio: 1; border-radius: var(--r-md);
    background: linear-gradient(160deg, var(--night-3), var(--indigo));
    border: 1px solid var(--line);
    display: flex; align-items: center; justify-content: center;
    color: var(--muted); font-size: var(--fs-xs);
    position: relative; overflow: hidden;
    transition: transform .2s;
}
.gazebo:hover { transform: scale(1.03); }
.gazebo::before {
    content: ""; position: absolute; inset: 12px; border-radius: var(--r-sm);
    border: 1px dashed rgba(255, 181, 71, .3);
    background: radial-gradient(circle at center, rgba(255, 181, 71, .08), transparent 70%);
}
.gazebo span { position: relative; z-index: 1; padding: 6px 10px; }

/* ─── Map / location ──────────────────────────────────────────── */
.location {
    background: linear-gradient(180deg, var(--night-2), var(--night-1));
}
.location__card {
    background: rgba(255, 241, 214, .04);
    border: 1px solid var(--line);
    border-radius: var(--r-lg);
    padding: var(--s-5);
    display: grid; gap: var(--s-4);
    grid-template-columns: 1fr;
}
@media (min-width: 740px) { .location__card { grid-template-columns: 1fr 1fr; align-items: center; } }
.location__facts ul { list-style: none; padding: 0; margin: var(--s-3) 0 var(--s-4); }
.location__facts li {
    padding: 8px 0; display: flex; gap: var(--s-3); align-items: flex-start;
    color: var(--text); font-size: var(--fs-sm); border-bottom: 1px solid var(--line);
}
.location__facts li:last-child { border-bottom: 0; }
.location__facts svg { width: 18px; height: 18px; color: var(--amber); flex-shrink: 0; margin-top: 2px; }
.location__cta { margin-top: var(--s-4); }

/* ─── Footer ──────────────────────────────────────────────────── */
.ftr {
    background: var(--night-1); padding: var(--s-7) 0 calc(var(--s-7) + 80px);
    border-top: 1px solid var(--line);
    color: var(--muted); font-size: var(--fs-sm);
}
.ftr__grid {
    display: grid; gap: var(--s-5);
    grid-template-columns: 1fr;
}
@media (min-width: 600px) { .ftr__grid { grid-template-columns: 1fr 1fr; } }
@media (min-width: 900px) { .ftr__grid { grid-template-columns: 2fr 1fr 1fr 1fr; } }
.ftr h4 { color: var(--warm); margin: 0 0 var(--s-3); font-size: var(--fs-md); }
.ftr ul { list-style: none; padding: 0; margin: 0; }
.ftr ul li { padding: 4px 0; }
.ftr ul a { color: var(--muted); transition: color .2s; }
.ftr ul a:hover { color: var(--amber); }
.ftr__socials { display: flex; gap: var(--s-2); margin-top: var(--s-3); }
.ftr__socials a {
    width: 38px; height: 38px; border-radius: var(--r-pill);
    background: rgba(255, 241, 214, .06); border: 1px solid var(--line);
    color: var(--warm);
    display: inline-flex; align-items: center; justify-content: center;
    transition: background .2s, color .2s, transform .2s;
}
.ftr__socials a:hover { background: var(--amber); color: #2a1a05; transform: translateY(-2px); }
.ftr__socials svg { width: 18px; height: 18px; }
.ftr__bottom {
    margin-top: var(--s-6); padding-top: var(--s-4);
    border-top: 1px solid var(--line);
    text-align: center; font-size: var(--fs-xs);
}

/* ─── Mobile sticky bottom bar ───────────────────────────────── */
.mob-cta {
    position: fixed; bottom: 0; left: 0; right: 0; z-index: 60;
    padding: 10px 12px calc(10px + env(safe-area-inset-bottom));
    background: linear-gradient(180deg, rgba(10, 20, 34, .5), rgba(10, 20, 34, .95));
    backdrop-filter: blur(14px);
    -webkit-backdrop-filter: blur(14px);
    display: flex; gap: var(--s-2);
}
.mob-cta a {
    flex: 1; display: inline-flex; align-items: center; justify-content: center; gap: 6px;
    padding: 12px; border-radius: var(--r-pill); font-weight: 600; font-size: var(--fs-sm);
    min-height: 48px;
}
.mob-cta__primary {
    background: linear-gradient(180deg, var(--amber), var(--honey));
    color: #2a1a05;
    box-shadow: 0 4px 12px rgba(255, 181, 71, .3);
}
.mob-cta__secondary {
    background: rgba(255, 241, 214, .1);
    color: var(--warm); border: 1px solid var(--line);
}
.mob-cta a svg { width: 16px; height: 16px; }
@media (min-width: 740px) { .mob-cta { display: none; } }
@media (max-width: 739px) { .ftr { padding-bottom: calc(var(--s-7) + 90px); } }
</style>
</head>

<body>

<!-- ─── Header ─────────────────────────────────────────────────── -->
<header class="hdr">
    <div class="container hdr__row">
        <a class="hdr__brand" href="<?= $h($canonicalUrl) ?>">
            <b>Veranda</b><span>Nha Trang</span>
        </a>
        <div class="hdr__actions">
            <div class="hdr__lang" role="group" aria-label="Язык">
                <button type="button" aria-pressed="true"  data-lang="ru">RU</button>
                <button type="button" aria-pressed="false" data-lang="en">EN</button>
                <button type="button" aria-pressed="false" data-lang="vi">VI</button>
                <button type="button" aria-pressed="false" data-lang="ko">KO</button>
            </div>
            <a class="hdr__icon" href="<?= $h($whatsappUrl) ?>" target="_blank" rel="noopener" aria-label="WhatsApp"><?= $icon['wa'] ?></a>
            <a class="hdr__icon" href="<?= $h($telegramUrl) ?>" target="_blank" rel="noopener" aria-label="Telegram"><?= $icon['tg'] ?></a>
            <a class="hdr__cta" href="<?= $h($reserveUrl) ?>"><?= $icon['reserve'] ?>Забронировать</a>
        </div>
    </div>
</header>

<!-- ─── Hero ───────────────────────────────────────────────────── -->
<section class="hero" aria-label="Главная">
    <div class="hero__stars" aria-hidden="true"></div>
    <div class="hero__lanterns" aria-hidden="true">
        <?php for ($i = 0; $i < 18; $i++) echo '<i></i>'; ?>
    </div>
    <div class="hero__horizon" aria-hidden="true"></div>
    <div class="hero__mountains" aria-hidden="true"></div>
    <div class="hero__trees" aria-hidden="true"></div>

    <div class="hero__inner">
        <div class="hero__eyebrow"><?= $icon['sparkles'] ?> Ресторан · Баня · Игры</div>
        <h1>Целый вечер <em>впечатлений</em><br>в горах Нячанга</h1>
        <p>Ресторан с домашней кухней, баня на дровах, лазертаг и Archery&nbsp;Tag для всей семьи, живая музыка и кино под звёздами&nbsp;— на одной локации, в&nbsp;10&nbsp;минутах от центра.</p>
        <div class="hero__cta">
            <a class="btn btn--primary" href="<?= $h($reserveUrl) ?>"><?= $icon['reserve'] ?>Забронировать столик</a>
            <a class="btn btn--ghost"   href="#afisha"><?= $icon['music'] ?>Что сегодня вечером</a>
        </div>
        <span class="hero__micro"><?= $icon['sparkles'] ?>Вход на все события — свободный</span>
    </div>
</section>

<!-- ─── Afisha ─────────────────────────────────────────────────── -->
<section id="afisha" class="afisha">
    <div class="container">
        <div class="section__head reveal">
            <div class="section__eyebrow">Живая афиша</div>
            <h2 class="section__title">Что сегодня вечером</h2>
            <p class="section__sub">Каждый день недели — своё настроение. Вход свободный, столик стоит заранее забронировать.</p>
        </div>

        <div class="afisha__today reveal" id="afishaToday">
            <div class="afisha__today-day" id="afishaTodayDay">—</div>
            <div class="afisha__today-info">
                <span class="afisha__badge">Сегодня</span>
                <h3 id="afishaTodayTitle">…</h3>
                <p id="afishaTodayNote">…</p>
            </div>
            <a class="btn btn--primary" href="<?= $h($reserveUrl) ?>"><?= $icon['reserve'] ?>Забронировать</a>
        </div>

        <div class="afisha__week reveal">
            <?php foreach ([1, 2, 3, 4, 5, 6, 0] as $d): $ev = $events[$d]; ?>
            <div class="afisha__day" data-day="<?= $d ?>">
                <div class="afisha__day-name"><?= $h($dayNames[$d]) ?></div>
                <div class="afisha__day-title"><?= $h($ev['title']) ?></div>
                <div class="afisha__day-time"><?= $h($ev['time']) ?></div>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="reveal" style="text-align:center; margin-top: var(--s-4);">
            <span class="afisha__free"><?= $icon['sparkles'] ?>Все события — бесплатно для гостей</span>
        </div>
    </div>
</section>

<!-- ─── Worlds ─────────────────────────────────────────────────── -->
<section class="worlds">
    <div class="container">
        <div class="section__head reveal">
            <div class="section__eyebrow">Один день — целый мир</div>
            <h2 class="section__title">Пять причин остаться надолго</h2>
            <p class="section__sub">Поесть, попариться, поиграть, послушать музыку и посмотреть кино под звёздами&nbsp;— всё на одной поляне.</p>
        </div>

        <div class="worlds__grid">
            <article class="world reveal">
                <div>
                    <div class="world__icon"><?= $icon['fork'] ?></div>
                    <h3>Поесть</h3>
                    <p>Славянская и домашняя кухня + европейское: бургеры, завтраки, блины, вафли, разливное пиво, авторские коктейли.</p>
                </div>
                <a class="world__cta" href="<?= $h($menuUrl) ?>">Открыть меню <?= $icon['arrow'] ?></a>
            </article>

            <article class="world reveal">
                <div>
                    <div class="world__icon"><?= $icon['flame'] ?></div>
                    <h3>Попариться</h3>
                    <p>«Сила Духа» — настоящая русская баня на дровах. Парная, холодная купель, пармастера, веники, чай с мёдом и квас.</p>
                </div>
                <a class="world__cta" href="<?= $h($banyaUrl) ?>" target="_blank" rel="noopener">sila-duha.com <?= $icon['arrow'] ?></a>
                <span class="world__extra">Бронь: <a href="tel:<?= $h($banyaPhone) ?>" style="color:inherit"><?= $h($banyaDisplay) ?></a></span>
            </article>

            <article class="world reveal">
                <div>
                    <div class="world__icon"><?= $icon['target'] ?></div>
                    <h3>Поиграть</h3>
                    <p>Лазертаг, Archery&nbsp;Tag (лучный бой с мягкими стрелами), орбизбол, квесты. От 8 до 20 игроков, инструктаж перед каждой игрой.</p>
                </div>
                <a class="world__cta" href="<?= $h($gamezoneUrl) ?>" target="_blank" rel="noopener">ru.vn-gamezone.com <?= $icon['arrow'] ?></a>
                <span class="world__extra">Archery Tag · 200 000&nbsp;₫/чел</span>
            </article>

            <article class="world reveal">
                <div>
                    <div class="world__icon"><?= $icon['kids'] ?></div>
                    <h3>Детям</h3>
                    <p>Детский клуб с аниматором 18:00–21:00, мастер-классы (имбирные пряники), дискотека, квест «Форт&nbsp;Боярд».</p>
                </div>
                <a class="world__cta" href="<?= $h($whatsappUrl) ?>" target="_blank" rel="noopener">Спросить в WhatsApp <?= $icon['arrow'] ?></a>
                <span class="world__extra">~100 000&nbsp;₫/час с аниматором</span>
            </article>

            <article class="world reveal">
                <div>
                    <div class="world__icon"><?= $icon['music'] ?></div>
                    <h3>Вечер</h3>
                    <p>Живая музыка по средам, пятницам, субботам и воскресеньям. Большой экран и проектор для кино под звёздами&nbsp;— такого в Нячанге больше нет.</p>
                </div>
                <a class="world__cta" href="#afisha">Афиша недели <?= $icon['arrow'] ?></a>
            </article>

            <article class="world reveal">
                <div>
                    <div class="world__icon"><?= $icon['film'] ?></div>
                    <h3>Кино под звёздами</h3>
                    <p>Большой проектор, тёплые гирлянды, плед и попкорн. Детский сеанс в 18:00, взрослый в 20:00 — по вторникам и четвергам.</p>
                </div>
                <a class="world__cta" href="<?= $h($reserveUrl) ?>">Забронировать места <?= $icon['arrow'] ?></a>
            </article>
        </div>
    </div>
</section>

<!-- ─── Breakfast ─────────────────────────────────────────────── -->
<section class="breakfast">
    <div class="container">
        <div class="breakfast__inner">
            <div class="reveal">
                <div class="section__eyebrow">Завтраки в горах</div>
                <h2>Прохлада в тени горных деревьев, когда в городе уже жарко</h2>
                <p>Веранда на склоне ловит утренний бриз и тень больших деревьев. Свежий кофе, омлет, блины с творогом, авокадо-тосты, фермерская сметана&nbsp;— и виды, которые невозможно сфотографировать как следует.</p>
                <div style="display:flex; gap:var(--s-3); flex-wrap:wrap;">
                    <a class="btn btn--primary" href="<?= $h($menuUrl) ?>"><?= $icon['menu'] ?>Меню завтраков</a>
                    <a class="btn btn--ghost"   href="<?= $h($reserveUrl) ?>"><?= $icon['reserve'] ?>Забронировать</a>
                </div>
            </div>
            <div class="breakfast__visual reveal" aria-hidden="true"></div>
        </div>
    </div>
</section>

<!-- ─── Gazebos ────────────────────────────────────────────────── -->
<section class="gazebos">
    <div class="container">
        <div class="section__head reveal">
            <div class="section__eyebrow">Беседки</div>
            <h2 class="section__title">Уютные мини-беседки на компанию</h2>
            <p class="section__sub">Гирлянды, дерево, мягкие подушки. Хорошо для семейного вечера, дня рождения или просто долгого ужина под звёздами.</p>
        </div>
        <div class="gazebos__row reveal">
            <div class="gazebo"><span>Беседка 1</span></div>
            <div class="gazebo"><span>Беседка 2</span></div>
            <div class="gazebo"><span>Беседка 3</span></div>
            <div class="gazebo"><span>Беседка 4</span></div>
        </div>
        <div style="text-align:center; margin-top:var(--s-5);" class="reveal">
            <a class="btn btn--primary" href="<?= $h($reserveUrl) ?>"><?= $icon['reserve'] ?>Забронировать беседку</a>
        </div>
    </div>
</section>

<!-- ─── Location ───────────────────────────────────────────────── -->
<section class="location">
    <div class="container">
        <div class="section__head reveal">
            <div class="section__eyebrow">Как добраться</div>
            <h2 class="section__title">10 минут от центра Нячанга</h2>
        </div>
        <div class="location__card reveal">
            <div class="location__facts">
                <h3 style="margin:0 0 var(--s-2); color: var(--warm); font-family: Georgia, serif;">Veranda Restaurant &amp; Bar</h3>
                <ul>
                    <li><?= $icon['map'] ?> ~10 минут от центра города на такси/байке. Парковка на месте.</li>
                    <li><?= $icon['phone'] ?> <a href="tel:<?= $h($telephone) ?>" style="color:var(--warm)"><?= $h($telDisplay) ?></a></li>
                    <li><?= $icon['sparkles'] ?> Открыто ежедневно с 10:00 до 23:00</li>
                </ul>
                <div class="location__cta" style="display:flex; gap:var(--s-3); flex-wrap:wrap;">
                    <a class="btn btn--primary" href="<?= $h($mapsUrl) ?>" target="_blank" rel="noopener"><?= $icon['map'] ?>Построить маршрут</a>
                    <a class="btn btn--ghost"   href="<?= $h($whatsappUrl) ?>" target="_blank" rel="noopener"><?= $icon['wa'] ?>Спросить дорогу</a>
                </div>
            </div>
            <div class="breakfast__visual" aria-hidden="true" style="aspect-ratio: 1; background: radial-gradient(circle at 50% 60%, rgba(255,181,71,.35), transparent 55%), linear-gradient(135deg, #1a2c3e, #0a1422);"></div>
        </div>
    </div>
</section>

<!-- ─── Footer ─────────────────────────────────────────────────── -->
<footer class="ftr">
    <div class="container">
        <div class="ftr__grid">
            <div>
                <h4>Veranda Restaurant &amp; Bar</h4>
                <p style="margin:0 0 var(--s-3);">Ресторан, баня и игры на одной поляне в горах Нячанга. Бронирование столика — через сайт.</p>
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
                    <li><a href="<?= $h($reserveUrl) ?>">Забронировать столик</a></li>
                    <li><a href="<?= $h($menuUrl) ?>">Меню</a></li>
                    <li><a href="/links/">Все ссылки</a></li>
                </ul>
            </div>
            <div>
                <h4>Партнёры</h4>
                <ul>
                    <li><a href="<?= $h($banyaUrl) ?>"    target="_blank" rel="noopener">Баня «Сила Духа»</a></li>
                    <li><a href="<?= $h($gamezoneUrl) ?>" target="_blank" rel="noopener">GameZone — игры</a></li>
                    <li><a href="<?= $h($telegramUrl) ?>" target="_blank" rel="noopener">TG-канал Nha Trang</a></li>
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
            © <?= date('Y') ?> Veranda · Бронирование через <a href="<?= $h($reserveUrl) ?>" style="color:var(--amber)">veranda.my/tr3</a>
        </div>
    </div>
</footer>

<!-- ─── Sticky mobile CTA ─────────────────────────────────────── -->
<div class="mob-cta" aria-hidden="false">
    <a class="mob-cta__primary"   href="<?= $h($reserveUrl) ?>"><?= $icon['reserve'] ?>Забронировать</a>
    <a class="mob-cta__secondary" href="<?= $h($menuUrl) ?>"><?= $icon['menu'] ?>Меню</a>
</div>

<script type="application/ld+json"><?= json_encode($jsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>

<script>
(function () {
    'use strict';

    // ── 1. Подсветка дня недели в афише ───────────────────────────
    var dayNames = ['Воскресенье','Понедельник','Вторник','Среда','Четверг','Пятница','Суббота'];
    var events = <?= json_encode($events, JSON_UNESCAPED_UNICODE) ?>;
    var today = new Date().getDay();
    var ev = events[today];

    var dayEl   = document.getElementById('afishaTodayDay');
    var titleEl = document.getElementById('afishaTodayTitle');
    var noteEl  = document.getElementById('afishaTodayNote');
    if (dayEl && titleEl && noteEl && ev) {
        dayEl.textContent   = dayNames[today];
        titleEl.textContent = ev.title + ' · ' + ev.time;
        noteEl.textContent  = ev.note;
    }
    document.querySelectorAll('.afisha__day').forEach(function (el) {
        if (Number(el.getAttribute('data-day')) === today) el.classList.add('is-today');
    });

    // ── 2. Reveal on scroll (IntersectionObserver) ────────────────
    if ('IntersectionObserver' in window && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (e) {
                if (e.isIntersecting) {
                    e.target.classList.add('is-in');
                    io.unobserve(e.target);
                }
            });
        }, { rootMargin: '0px 0px -10% 0px', threshold: 0.05 });
        document.querySelectorAll('.reveal').forEach(function (el) { io.observe(el); });
    } else {
        document.querySelectorAll('.reveal').forEach(function (el) { el.classList.add('is-in'); });
    }

    // ── 3. Language picker — пока заглушка, шлёт сигнал в консоль
    //      и подсвечивает выбор. EN/VI/KO контент добавим следующей
    //      итерацией; пока RU=единственный язык.
    document.querySelectorAll('.hdr__lang button').forEach(function (b) {
        b.addEventListener('click', function () {
            var l = b.getAttribute('data-lang');
            document.querySelectorAll('.hdr__lang button').forEach(function (x) {
                x.setAttribute('aria-pressed', String(x === b));
            });
            if (l !== 'ru') {
                alert('Скоро: ' + l.toUpperCase() + ' версия. Пока главная только на русском.');
                document.querySelectorAll('.hdr__lang button').forEach(function (x) {
                    x.setAttribute('aria-pressed', String(x.getAttribute('data-lang') === 'ru'));
                });
            }
            console.log('[home] lang ->', l);
        });
    });
})();
</script>

</body>
</html>
