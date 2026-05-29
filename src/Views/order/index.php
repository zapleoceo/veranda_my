<?php
/**
 * @var string $csrfToken
 * @var string $cssVersion
 * @var string $jsVersion
 * @var string $lang     'ru' | 'en' | 'vi'
 * @var array  $t        translation dictionary for the chosen language
 */
declare(strict_types=1);

$h = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="<?= $h($lang) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0f1117">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="<?= $h($csrfToken) ?>">
    <link rel="icon" type="image/svg+xml" href="/links/favicon.svg">
    <title><?= $h($t['title']) ?> — Veranda</title>
    <link rel="stylesheet" href="/assets/css/common.css?v=<?= $h($cssVersion) ?>">
    <link rel="stylesheet" href="/neworder/assets/css/order.css?v=<?= $h($cssVersion) ?>">
</head>
<body class="no-mode">

<!-- ─── Top bar ─────────────────────────────────────────────────── -->
<header class="no-top">
    <div class="no-top__inner">
        <button type="button" class="no-iconbtn no-iconbtn--loc" id="noLocationBtn" aria-label="<?= $h($t['pickTable']) ?>">
            <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true">
                <path fill="currentColor" d="M12 2a7 7 0 0 0-7 7c0 5.25 7 13 7 13s7-7.75 7-13a7 7 0 0 0-7-7Zm0 9.5A2.5 2.5 0 1 1 12 6.5a2.5 2.5 0 0 1 0 5Z"/>
            </svg>
            <span class="no-location-label" id="noLocationLabel"><?= $h($t['locationDefault']) ?></span>
        </button>
        <!-- Language switcher (replaces the static "Новый заказ" title).
             Anchor reload — each click writes the cookie via the controller
             and bounces back with the new language slice. -->
        <nav class="no-langsw" aria-label="Language">
            <?php foreach (['ru', 'en', 'vi'] as $code): ?>
                <a href="?lang=<?= $h($code) ?>"
                   class="no-langsw__btn <?= $lang === $code ? 'is-active' : '' ?>"
                   data-lang="<?= $h($code) ?>"><?= $h(strtoupper($code)) ?></a>
            <?php endforeach; ?>
        </nav>
        <button type="button" class="no-iconbtn" id="noMenuRefreshBtn" aria-label="<?= $h($t['refreshMenu']) ?>" title="<?= $h($t['refreshMenu']) ?>">
            <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M21 2v6h-6"/>
                <path d="M3 12a9 9 0 0 1 15-6.7L21 8"/>
                <path d="M3 22v-6h6"/>
                <path d="M21 12a9 9 0 0 1-15 6.7L3 16"/>
            </svg>
        </button>
    </div>
    <div class="no-search-wrap">
        <svg class="no-search-icon" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true">
            <path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M21 21l-4.35-4.35"/>
            <circle cx="10.5" cy="10.5" r="6.5" fill="none" stroke="currentColor" stroke-width="2"/>
        </svg>
        <input type="search" id="noSearchInput" class="no-search-input" placeholder="<?= $h($t['searchPh']) ?>" autocomplete="off">
        <button type="button" class="no-search-clear" id="noSearchClear" hidden aria-label="<?= $h($t['close']) ?>">×</button>
    </div>
</header>

<!-- ─── Menu scroll area ─────────────────────────────────────────── -->
<main class="no-menu" id="noMenu">
    <div class="no-skeleton">
        <div class="no-skeleton__cat"></div>
        <div class="no-skeleton__row"><div></div><div></div><div></div></div>
        <div class="no-skeleton__row"><div></div><div></div><div></div></div>
        <div class="no-skeleton__cat"></div>
        <div class="no-skeleton__row"><div></div><div></div><div></div></div>
    </div>
</main>

<!-- ─── Sticky cart bar ─────────────────────────────────────────── -->
<button type="button" class="no-cartbar" id="noCartBar" hidden>
    <span class="no-cartbar__count" id="noCartBarCount">0</span>
    <span class="no-cartbar__label" id="noCartBarLabel"><?= $h($t['cart']) ?></span>
    <span class="no-cartbar__sum"   id="noCartBarSum">0 ₫</span>
</button>

<!-- ─── Cart sheet ──────────────────────────────────────────────── -->
<aside class="no-cart" id="noCart" hidden aria-hidden="true">
    <div class="no-cart__backdrop" data-no-close></div>
    <section class="no-cart__panel" role="dialog" aria-modal="true" aria-labelledby="noCartTitle">
        <header class="no-cart__header">
            <h2 id="noCartTitle"><?= $h($t['cart']) ?></h2>
            <button type="button" class="no-iconbtn" data-no-close aria-label="<?= $h($t['close']) ?>">×</button>
        </header>
        <!-- Open-check banner — наверху корзины (под header'ом), не между
             items и footer'ом. Так оператор гарантированно его видит при
             открытии корзины, а не скроллит мимо. -->
        <div class="no-cart__open-check" id="noOpenCheck" hidden></div>
        <div class="no-cart__items" id="noCartItems"></div>
        <footer class="no-cart__footer">
            <label class="no-field">
                <span><?= $h($t['commentLabel']) ?></span>
                <textarea id="noOrderComment" rows="2" placeholder="<?= $h($t['commentPh']) ?>"></textarea>
            </label>
            <div class="no-cart__total">
                <span><?= $h($t['total']) ?></span>
                <strong id="noCartTotal">0 ₫</strong>
            </div>
            <button type="button" class="no-btn no-btn--primary" id="noSubmitBtn" disabled>
                <span class="no-btn__label"><?= $h($t['place']) ?></span>
                <span class="no-btn__spinner" aria-hidden="true"></span>
            </button>
            <div class="no-cart__error" id="noCartError" hidden></div>
        </footer>
    </section>
</aside>

<!-- ─── Modifier sheet ──────────────────────────────────────────── -->
<aside class="no-modif" id="noModif" hidden aria-hidden="true">
    <div class="no-modif__backdrop" data-no-close></div>
    <section class="no-modif__panel" role="dialog" aria-modal="true">
        <header class="no-modif__header">
            <h3 id="noModifTitle"></h3>
            <button type="button" class="no-iconbtn" data-no-close aria-label="<?= $h($t['close']) ?>">×</button>
        </header>
        <div class="no-modif__body" id="noModifBody"></div>
        <footer class="no-modif__footer">
            <div class="no-modif__total">
                <span><?= $h($t['addModifTotal']) ?></span>
                <strong id="noModifPrice">0 ₫</strong>
            </div>
            <button type="button" class="no-btn no-btn--primary" id="noModifAdd"><?= $h($t['add']) ?></button>
        </footer>
    </section>
</aside>

<!-- ─── Location picker ─────────────────────────────────────────── -->
<aside class="no-locsheet" id="noLocSheet" hidden aria-hidden="true">
    <div class="no-locsheet__backdrop" data-no-close></div>
    <section class="no-locsheet__panel" role="dialog" aria-modal="true">
        <header class="no-locsheet__header">
            <h3><?= $h($t['pickTable']) ?></h3>
            <button type="button" class="no-iconbtn" data-no-close aria-label="<?= $h($t['close']) ?>">×</button>
        </header>
        <div class="no-locsheet__body">
            <label class="no-field" id="noSpotWrap" hidden>
                <span><?= $h($t['spot']) ?></span>
                <select id="noSpotSelect"></select>
            </label>
            <label class="no-field">
                <span><?= $h($t['hall']) ?></span>
                <select id="noHallSelect"></select>
            </label>
            <div class="no-locsheet__tables" id="noTableGrid"></div>
        </div>
    </section>
</aside>

<!-- ─── Success state ───────────────────────────────────────────── -->
<aside class="no-success" id="noSuccess" hidden aria-hidden="true">
    <div class="no-success__inner">
        <div class="no-success__check" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="48" height="48"><path fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" d="M5 12l5 5L20 7"/></svg>
        </div>
        <h2><?= $h($t['success']) ?></h2>
        <p id="noSuccessSub"></p>
        <button type="button" class="no-btn no-btn--primary" id="noNewOrderBtn"><?= $h($t['newOrderBtn']) ?></button>
    </div>
</aside>

<!-- ─── Toast ───────────────────────────────────────────────────── -->
<div class="no-toast" id="noToast" hidden></div>

<!-- The runtime JS strings — same language slice the view uses. -->
<script>
window.__noI18n = <?= json_encode($t, JSON_UNESCAPED_UNICODE) ?>;
window.__noLang = <?= json_encode($lang) ?>;
</script>
<script type="module" src="/neworder/assets/js/index.js?v=<?= $h($jsVersion) ?>"></script>
</body>
</html>
