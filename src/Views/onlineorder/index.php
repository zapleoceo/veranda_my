<?php
/**
 * @var string $csrfToken
 * @var string $cssVersion
 * @var string $jsVersion
 * @var string $lang      'ru' | 'en' | 'vi'
 * @var array  $t         translation dictionary for the chosen language
 * @var array  $bootstrap OnlineOrderConfig::frontendBootstrap()
 */
declare(strict_types=1);

$h = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="<?= $h($lang) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#000000">
    <meta name="csrf-token" content="<?= $h($csrfToken) ?>">
    <meta name="description" content="Veranda Nha Trang — food delivery. Доставка еды по Нячангу.">
    <link rel="icon" type="image/svg+xml" href="/links/favicon.svg">
    <title><?= $h($t['title']) ?> — Veranda</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@600;700&family=Montserrat:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/onlineorder/assets/css/onlineorder.css?v=<?= $h($cssVersion) ?>">
</head>
<body class="oo">

<!-- ─── Top: brand + language + search ──────────────────────────── -->
<header class="oo-top">
    <div class="oo-top__row">
        <a class="oo-brand" href="/">
            <span class="oo-brand__name">VERANDA</span>
            <span class="oo-brand__sub"><?= $h($t['title']) ?></span>
        </a>
        <nav class="oo-langsw" aria-label="Language">
            <?php foreach (['ru', 'en', 'vi'] as $code): ?>
                <a href="?lang=<?= $h($code) ?>"
                   class="oo-langsw__btn <?= $lang === $code ? 'is-active' : '' ?>"><?= $h(strtoupper($code)) ?></a>
            <?php endforeach; ?>
        </nav>
    </div>
    <p class="oo-top__tagline"><?= $h($t['subtitle']) ?></p>
    <div class="oo-search">
        <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true">
            <path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M21 21l-4.35-4.35"/>
            <circle cx="10.5" cy="10.5" r="6.5" fill="none" stroke="currentColor" stroke-width="2"/>
        </svg>
        <input type="search" id="ooSearch" placeholder="<?= $h($t['searchPh']) ?>" autocomplete="off">
        <button type="button" id="ooSearchClear" hidden aria-label="<?= $h($t['close']) ?>">×</button>
    </div>
    <div class="oo-cats" id="ooCats"></div>
</header>

<!-- ─── Menu ─────────────────────────────────────────────────────── -->
<main class="oo-menu" id="ooMenu">
    <div class="oo-skeleton" aria-hidden="true">
        <div class="oo-skeleton__cat"></div>
        <div class="oo-skeleton__grid"><div></div><div></div><div></div><div></div></div>
        <div class="oo-skeleton__cat"></div>
        <div class="oo-skeleton__grid"><div></div><div></div></div>
    </div>
</main>

<!-- ─── Sticky cart bar ──────────────────────────────────────────── -->
<button type="button" class="oo-cartbar" id="ooCartBar" hidden>
    <span class="oo-cartbar__count" id="ooCartCount">0</span>
    <span class="oo-cartbar__label"><?= $h($t['cart']) ?></span>
    <span class="oo-cartbar__sum" id="ooCartSum">0 ₫</span>
</button>

<!-- ─── Cart sheet ───────────────────────────────────────────────── -->
<aside class="oo-sheet" id="ooCartSheet" hidden aria-hidden="true">
    <div class="oo-sheet__backdrop" data-oo-close></div>
    <section class="oo-sheet__panel" role="dialog" aria-modal="true" aria-labelledby="ooCartTitle">
        <header class="oo-sheet__header">
            <h2 id="ooCartTitle"><?= $h($t['cart']) ?></h2>
            <button type="button" class="oo-x" data-oo-close aria-label="<?= $h($t['close']) ?>">×</button>
        </header>
        <div class="oo-sheet__body" id="ooCartItems"></div>
        <footer class="oo-sheet__footer">
            <label class="oo-field">
                <span><?= $h($t['commentLabel']) ?></span>
                <textarea id="ooOrderComment" rows="2" placeholder="<?= $h($t['commentPh']) ?>"></textarea>
            </label>
            <div class="oo-total">
                <span><?= $h($t['total']) ?></span>
                <strong id="ooCartTotal">0 ₫</strong>
            </div>
            <button type="button" class="oo-btn oo-btn--primary" id="ooToCheckout" disabled>
                <?= $h($t['toCheckout']) ?>
            </button>
        </footer>
    </section>
</aside>

<!-- ─── Modifier sheet ───────────────────────────────────────────── -->
<aside class="oo-sheet" id="ooModifSheet" hidden aria-hidden="true">
    <div class="oo-sheet__backdrop" data-oo-close></div>
    <section class="oo-sheet__panel" role="dialog" aria-modal="true">
        <header class="oo-sheet__header">
            <h3 id="ooModifTitle"></h3>
            <button type="button" class="oo-x" data-oo-close aria-label="<?= $h($t['close']) ?>">×</button>
        </header>
        <div class="oo-sheet__body" id="ooModifBody"></div>
        <footer class="oo-sheet__footer">
            <div class="oo-total">
                <span><?= $h($t['addModifTotal']) ?></span>
                <strong id="ooModifPrice">0 ₫</strong>
            </div>
            <button type="button" class="oo-btn oo-btn--primary" id="ooModifAdd"><?= $h($t['add']) ?></button>
        </footer>
    </section>
</aside>

<!-- ─── Checkout sheet ───────────────────────────────────────────── -->
<aside class="oo-sheet" id="ooCheckout" hidden aria-hidden="true">
    <div class="oo-sheet__backdrop" data-oo-close></div>
    <section class="oo-sheet__panel oo-sheet__panel--tall" role="dialog" aria-modal="true" aria-labelledby="ooCheckoutTitle">
        <header class="oo-sheet__header">
            <h2 id="ooCheckoutTitle"><?= $h($t['checkoutTitle']) ?></h2>
            <button type="button" class="oo-x" data-oo-close aria-label="<?= $h($t['close']) ?>">×</button>
        </header>
        <div class="oo-sheet__body">
            <form id="ooCheckoutForm" novalidate>
                <!-- Honeypot: invisible to humans, irresistible to bots -->
                <input type="text" name="website" id="ooWebsite" class="oo-hp" tabindex="-1" autocomplete="off">

                <label class="oo-field">
                    <span><?= $h($t['nameLabel']) ?> *</span>
                    <input type="text" id="ooName" placeholder="<?= $h($t['namePh']) ?>" autocomplete="name" required>
                    <em class="oo-field__err" id="ooNameErr" hidden><?= $h($t['requiredField']) ?></em>
                </label>

                <label class="oo-field">
                    <span><?= $h($t['phoneLabel']) ?> *</span>
                    <input type="tel" id="ooPhone" placeholder="<?= $h($t['phonePh']) ?>" autocomplete="tel" inputmode="tel" required>
                    <em class="oo-field__err" id="ooPhoneErr" hidden><?= $h($t['phoneInvalid']) ?></em>
                </label>

                <label class="oo-field">
                    <span><?= $h($t['addressLabel']) ?> *</span>
                    <input type="text" id="ooAddress" placeholder="<?= $h($t['addressPh']) ?>" autocomplete="street-address" required>
                    <em class="oo-field__err" id="ooAddressErr" hidden><?= $h($t['addressMissing']) ?></em>
                </label>

                <div class="oo-field-row">
                    <label class="oo-field">
                        <span><?= $h($t['apartmentLabel']) ?></span>
                        <input type="text" id="ooApartment" placeholder="<?= $h($t['apartmentPh']) ?>">
                    </label>
                    <label class="oo-field">
                        <span><?= $h($t['noteLabel']) ?></span>
                        <input type="text" id="ooNote" placeholder="<?= $h($t['notePh']) ?>">
                    </label>
                </div>

                <!-- Delivery quote box: filled by JS after address resolves -->
                <div class="oo-quote" id="ooQuote" hidden></div>

                <div class="oo-summary" id="ooSummary"></div>

                <button type="submit" class="oo-btn oo-btn--primary oo-btn--big" id="ooSubmit">
                    <span class="oo-btn__label"><?= $h($t['placeOrder']) ?></span>
                    <span class="oo-btn__spinner" aria-hidden="true"></span>
                </button>
                <div class="oo-form-error" id="ooFormError" hidden></div>
                <p class="oo-courier-note"><?= $h($t['quoteCourierNote']) ?></p>
            </form>
        </div>
    </section>
</aside>

<!-- ─── Success ──────────────────────────────────────────────────── -->
<aside class="oo-success" id="ooSuccess" hidden aria-hidden="true">
    <div class="oo-success__inner">
        <div class="oo-success__check" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="46" height="46"><path fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" d="M5 12l5 5L20 7"/></svg>
        </div>
        <h2><?= $h($t['success']) ?></h2>
        <p class="oo-success__sub" id="ooSuccessSub"></p>
        <div class="oo-pay" id="ooPay" hidden></div>
        <p class="oo-success__courier" id="ooSuccessCourier"></p>
        <button type="button" class="oo-btn oo-btn--ghost" id="ooNewOrder"><?= $h($t['newOrderBtn']) ?></button>
    </div>
</aside>

<!-- ─── Toast ────────────────────────────────────────────────────── -->
<div class="oo-toast" id="ooToast" hidden></div>

<script>
window.__oo = {
    csrf: <?= json_encode($csrfToken) ?>,
    lang: <?= json_encode($lang) ?>,
    i18n: <?= json_encode($t, JSON_UNESCAPED_UNICODE) ?>,
    cfg:  <?= json_encode($bootstrap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
};
</script>
<script type="module" src="/onlineorder/assets/js/index.js?v=<?= $h($jsVersion) ?>"></script>
</body>
</html>
