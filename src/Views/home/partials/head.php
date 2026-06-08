<?php

declare(strict_types=1);

use App\Home\View\Html;

/**
 * @var \App\Home\View\View   $view
 * @var \App\Home\Content\Seo $seo
 */

$v = '20260608b'; // cache-bust статики (редизайн)
?>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#14100b">
<title><?= Html::e($seo->title()) ?></title>
<meta name="description" content="<?= Html::e($seo->description()) ?>">
<link rel="canonical" href="<?= Html::e($seo->canonical) ?>">
<link rel="icon" type="image/svg+xml" href="/links/favicon.svg">

<meta property="og:type" content="website">
<meta property="og:site_name" content="Veranda">
<meta property="og:title" content="<?= Html::e($seo->ogTitle()) ?>">
<meta property="og:description" content="<?= Html::e($seo->ogDescription()) ?>">
<meta property="og:url" content="<?= Html::e($seo->canonical) ?>">
<meta property="og:image" content="<?= Html::e($seo->ogImage) ?>">
<meta name="twitter:card" content="summary_large_image">

<!-- Preload hero — критично для LCP -->
<link rel="preload" as="image"
      href="/assets/img/home/hero-terrace-1400.webp"
      imagesrcset="/assets/img/home/hero-terrace-700.webp 700w, /assets/img/home/hero-terrace-1400.webp 1400w"
      imagesizes="100vw">

<!-- Google Fonts (Cyrillic subset, font-display: swap) -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cinzel:wght@600;700&family=Cormorant:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400;1,500&family=Manrope:wght@300;400;500;600;700&display=swap">

<!-- Стили /home — вынесены из PHP, разбиты по слоям -->
<link rel="stylesheet" href="/assets/css/home/tokens.css?v=<?= $v ?>">
<link rel="stylesheet" href="/assets/css/home/base.css?v=<?= $v ?>">
<link rel="stylesheet" href="/assets/css/home/sections.css?v=<?= $v ?>">

<?php
// Аналитика проекта (на сервере лежит в docroot). Под локальный рендер — мягкая защита.
$analytics = ($_SERVER['DOCUMENT_ROOT'] ?? '') . '/analytics.php';
if ($analytics !== '/analytics.php' && is_file($analytics)) {
    include $analytics;
}
?>
</head>
