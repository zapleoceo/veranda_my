<?php

/*
TR3 (Booking) — Developer Note

Purpose: TR3 is a standalone booking page. Keep it clean and maintainable.

MVC / Separation of Responsibility:
- View (this file): renders UI only (HTML). No DB, no HTTP calls to external systems, no business rules.
- Controller: /tr3/api.php is the only entrypoint for AJAX. Keep controllers thin.
- Model/Services: Poster/DB/Telegram/WhatsApp integrations + business logic live behind clear interfaces (one responsibility per class).

Rules:
- No cross-layer leaks (Views never talk to DB; Models don’t know UI).
- No coupling with previous versions (do not import/require old pages; do not reuse legacy names like “tr2” in TR3 assets).
- TR3 assets must live in /tr3/assets with neutral names (app.js, base.css, layout.css, tr3.css, etc.).
*/

$cfg = require __DIR__ . '/i18n.php';
$supportedLangs = is_array($cfg['supported'] ?? null) ? $cfg['supported'] : ['ru', 'en', 'vi'];
$I18N = is_array($cfg['i18n'] ?? null) ? $cfg['i18n'] : [];

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
  if (in_array($cookieLang, $supportedLangs, true)) $lang = $cookieLang;
}
if ($lang === null) $lang = 'ru';
if (!isset($I18N[$lang])) $lang = 'ru';

function tr(string $key): string {
  global $I18N, $lang;
  return isset($I18N[$lang][$key]) ? (string)$I18N[$lang][$key] : $key;
}

$self = strtok((string)($_SERVER['REQUEST_URI'] ?? '/tr3/'), '?');
$params = $_GET;
unset($params['ajax'], $params['lang']);
$baseQs = http_build_query($params);
$mk = function (string $l) use ($self, $baseQs) {
  $qs = $baseQs !== '' ? ($baseQs . '&lang=' . rawurlencode($l)) : ('lang=' . rawurlencode($l));
  return $self . '?' . $qs;
};
?>
<!doctype html>
<html lang="<?= htmlspecialchars($lang) ?>" dir="ltr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars(tr('page_title')) ?></title>
  <link rel="icon" type="image/svg+xml" href="/links/favicon.svg">
  <link rel="preconnect" href="https://api.fontshare.com">
  <link rel="preconnect" href="https://cdn.fontshare.com" crossorigin>
  <link href="https://api.fontshare.com/v2/css?f[]=satoshi@400,500,700&f[]=clash-display@500,600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/tr3/assets/tr3.css?v=20260416_0344">
  <noscript>
    <style>
      .modal:target { display: flex !important; }
      .modal:target .modal-card { transform: translateY(0) scale(1) !important; opacity: 1 !important; }
      .nojs-only { display: grid !important; }
    </style>
  </noscript>
  <?php include $_SERVER['DOCUMENT_ROOT'] . '/analytics.php'; ?>
</head>
<body>
  <div class="app">
    <main class="panel">
      <div class="topbar">
        <div class="title-wrap">
          <h1 data-i18n="page_title"><?= htmlspecialchars(tr('page_title')) ?></h1>
          <input type="date" id="resDate" aria-label="<?= htmlspecialchars(tr('select_date_time')) ?>">
        </div>
        <div class="date-wrap">
          <span id="busyDateLabel" data-i18n="data_on"><?= htmlspecialchars(tr('data_on')) ?></span>
          <button type="button" class="dt-btn attn" id="resDateBtn" data-i18n="pick_date"><?= htmlspecialchars(tr('pick_date')) ?></button>
          <span class="mini-loader" id="busyDateLoader" hidden></span>
        </div>
        <div class="map-invite" data-i18n="tap_table_to_book"><?= htmlspecialchars(tr('tap_table_to_book')) ?></div>
        <div class="busy-progress" id="busyProgress" hidden></div>
        <div class="controls">
          <label class="zoom" aria-label="<?= htmlspecialchars(tr('zoom')) ?>">
            <span data-i18n="zoom"><?= htmlspecialchars(tr('zoom')) ?></span>
            <button class="zbtn" type="button" id="mapZoomMinus" aria-label="−">−</button>
            <span class="zv" id="mapZoomVal">100%</span>
            <button class="zbtn" type="button" id="mapZoomPlus" aria-label="+">+</button>
            <input id="mapZoomRange" type="range" min="10" max="100" step="1" value="100" aria-label="<?= htmlspecialchars(tr('zoom')) ?>">
          </label>
        </div>
        <div class="lang" aria-label="Language">
          <a href="<?= htmlspecialchars($mk('ru')) ?>" class="<?= $lang === 'ru' ? 'active' : '' ?>">RU</a>
          <a href="<?= htmlspecialchars($mk('en')) ?>" class="<?= $lang === 'en' ? 'active' : '' ?>">EN</a>
          <a href="<?= htmlspecialchars($mk('vi')) ?>" class="<?= $lang === 'vi' ? 'active' : '' ?>">VI</a>
        </div>
      </div>
  
      <section class="layout">
        <div class="map-shell">
          <div class="tile-layer" aria-hidden="true"></div>
            <div class="map-zoom-box" id="mapZoomBox">
              <div class="map-zoom-inner" id="mapZoomInner">
              <div class="map" aria-label="Схема столов ресторана">
            <div class="grass-corner-1-7" aria-hidden="true"></div>
            <button class="table large" style="left: 712px; top: 276px;" data-table="1"><span class="num">1</span><span class="cap"></span></button>
            <button class="table large" style="left: 712px; top: 402px;" data-table="2"><span class="num">2</span><span class="cap"></span></button>
            <button class="table large" style="left: 712px; top: 528px;" data-table="3"><span class="num">3</span><span class="cap"></span></button>
  
            <button class="table small-vertical wide-1" style="left: 580px; top: 528px;" data-table="4"><span class="num">4</span><span class="cap"></span></button>
            <button class="table small-vertical wide-1" style="left: 460px; top: 500px;" data-table="5"><span class="num">5</span><span class="cap"></span></button>
            <button class="table small-vertical wide-1" style="left: 340px; top: 528px;" data-table="6"><span class="num">6</span><span class="cap"></span></button>
            <button class="table small-vertical wide-1" style="left: 220px; top: 500px;" data-table="7"><span class="num">7</span><span class="cap"></span></button>
            <button class="table small-vertical wide-1" style="left: 110px; top: 528px;" data-table="8"><span class="num">8</span><span class="cap"></span></button>
            <button class="table large" style="left: -30px; top: 512px;" data-table="9"><span class="num">9</span><span class="cap"></span></button>
  
            <button class="table wide" style="left: 422px; top: 420px;" data-table="10"><span class="num">10</span><span class="cap"></span></button>
            <button class="table wide" style="left: 310px; top: 420px;" data-table="11"><span class="num">11</span><span class="cap"></span></button>
            <div class="fountain" style="left: 532px; top: 316px;" aria-hidden="true" id="fountainEl">
              <svg viewBox="0 0 64 64" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                <defs>
                  <linearGradient id="fWat" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0" stop-color="rgba(255,255,255,0.85)"/>
                    <stop offset="1" stop-color="rgba(90,180,255,0.10)"/>
                  </linearGradient>
                  <linearGradient id="fBowl" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0" stop-color="rgba(255,255,255,0.35)"/>
                    <stop offset="1" stop-color="rgba(0,0,0,0.35)"/>
                  </linearGradient>
                </defs>
                <circle cx="32" cy="44" r="16" fill="rgba(35,110,180,0.20)" stroke="rgba(255,255,255,0.18)" stroke-width="1"/>
                <path d="M18 44c4-6 24-6 28 0" stroke="rgba(255,255,255,0.28)" stroke-width="2" stroke-linecap="round"/>
                <path d="M22 44c3-4 17-4 20 0" stroke="rgba(90,180,255,0.30)" stroke-width="2" stroke-linecap="round"/>
                <path class="water-fall" d="M32 18c0 10-6 12-6 20" stroke="url(#fWat)" stroke-width="3" stroke-linecap="round"/>
                <path class="water-fall" d="M32 18c0 10 6 12 6 20" stroke="url(#fWat)" stroke-width="3" stroke-linecap="round"/>
                <path class="water-fall-center" d="M32 14c0 10 0 14 0 24" stroke="rgba(255,255,255,0.78)" stroke-width="3" stroke-linecap="round"/>
                <circle cx="32" cy="14" r="3" fill="rgba(255,255,255,0.75)"/>
                <path d="M24 40h16c0 0 2 0 2 2s-2 2-2 2H24c0 0-2 0-2-2s2-2 2-2Z" fill="url(#fBowl)" stroke="rgba(255,255,255,0.16)" stroke-width="1"/>
              </svg>
              <div class="koi koi-1"></div>
              <div class="koi koi-2"></div>
            </div>
            <button class="table wide" style="left: 102px; top: 420px;" data-table="12"><span class="num">12</span><span class="cap"></span></button>
            <button class="table wide" style="left: -10px; top: 420px;" data-table="13"><span class="num">13</span><span class="cap"></span></button>
  
            <button class="table" style="left: 402px; top: 304px;" data-table="14"><span class="num">14</span><span class="cap"></span></button>
            <button class="table" style="left: 274px; top: 304px;" data-table="15"><span class="num">15</span><span class="cap"></span></button>
            <button class="table" style="left: 162px; top: 304px;" data-table="16"><span class="num">16</span><span class="cap"></span></button>
  
            <button class="table small-vertical" style="left: 532px; top: 192px;" data-table="17"><span class="num">17</span><span class="cap"></span></button>
            <button class="table small-vertical" style="left: 417px; top: 192px;" data-table="18"><span class="num">18</span><span class="cap"></span></button>
            <button class="table small-vertical" style="left: 306px; top: 192px;" data-table="19"><span class="num">19</span><span class="cap"></span></button>
            <button class="table small-vertical" style="left: 194px; top: 192px;" data-table="20"><span class="num">20</span><span class="cap"></span></button>
            <button class="table small-vertical" style="left: 82px; top: 192px;" data-table="21"><span class="num">21</span><span class="cap"></span></button>
            <button class="table large" style="left: -31px; top: 254px;" data-table="22"><span class="num">22</span><span class="cap"></span></button>
  
            <div class="bar-row">
              <div class="station-wrap">
                <div class="side-station" data-i18n="musicians"><?= htmlspecialchars(tr('musicians')) ?></div>
              </div>
              <div class="bar" data-i18n="bar"><?= htmlspecialchars(tr('bar')) ?></div>
              <div class="station-wrap cash">
                <div class="side-station" data-i18n="cashier"><?= htmlspecialchars(tr('cashier')) ?></div>
              </div>
            </div>
              </div>
            </div>
          </div>
      </section>
    </main>
  </div>

  <div class="dtp" id="dtpModal" aria-hidden="true">
    <div class="dtp-backdrop" data-dtp-close></div>
    <div class="dtp-card" role="dialog" aria-modal="true" aria-labelledby="dtpTitle">
      <div class="dtp-title" id="dtpTitle" data-i18n="dtp_title"><?= htmlspecialchars(tr('dtp_title')) ?></div>
      <div class="cal">
        <div class="cal-head">
          <button type="button" class="cal-nav" id="dtpPrev" aria-label="Prev month">‹</button>
          <div class="cal-month" id="dtpMonthLabel"></div>
          <button type="button" class="cal-nav" id="dtpNext" aria-label="Next month">›</button>
        </div>
        <div class="cal-week" id="dtpWeek"></div>
        <div class="cal-grid" id="dtpCalGrid"></div>
      </div>
      <div class="dtp-actions">
        <button class="btn btn-secondary" type="button" data-dtp-close data-i18n="cancel"><?= htmlspecialchars(tr('cancel')) ?></button>
        <button class="btn btn-primary" type="button" id="dtpOk" data-i18n="ok"><?= htmlspecialchars(tr('ok')) ?></button>
      </div>
    </div>
  </div>

  <div class="modal" id="capModal" aria-hidden="true">
    <div class="modal-backdrop" data-modal-close="capModal"></div>
    <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="capModalTitle">
      <div class="modal-title" id="capModalTitle" data-i18n="confirm"><?= htmlspecialchars(tr('confirm')) ?></div>
      <div class="modal-text" id="capModalText"></div>
      <div class="modal-actions">
        <button class="btn btn-secondary" type="button" id="capModalNo" data-i18n="no"><?= htmlspecialchars(tr('no')) ?></button>
        <button class="btn btn-primary" type="button" id="capModalYes" data-i18n="yes"><?= htmlspecialchars(tr('yes')) ?></button>
      </div>
    </div>
  </div>

  <div class="modal" id="reqModal" aria-hidden="true">
    <div class="modal-backdrop" data-modal-close="reqModal"></div>
    <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="reqModalTitle" id="reqModalCard">
      <div class="modal-title-bar">
        <div class="modal-title" id="reqModalTitle">
          <span data-i18n="booking_request"><?= htmlspecialchars(tr('booking_request')) ?></span>
          <span class="framed-box" id="reqModalTable"></span>
          <span data-i18n="on_date"><?= htmlspecialchars(tr('on_date')) ?></span>
          <span class="framed-box" id="reqModalDate"></span>
          <span class="framed-box busy-tag" id="reqModalBusy" hidden></span>
        </div>
        <button class="btn-close-modal" type="button" data-modal-close="reqModal" aria-label="Close">×</button>
      </div>
      <form id="reqForm" method="post" action="/tr3/api.php?ajax=submit_booking" accept-charset="utf-8">
        <input type="hidden" id="reqTableNum" name="table_num" value="">
        <input type="hidden" id="reqStartIso" name="start" value="">
        <div class="req-layout">
          <div class="req-left" id="reqLeft">
            <div class="modal-grid">
              <div class="modal-label full nojs-only" style="display:none;">
                <span>Без JavaScript</span>
                <div class="modal-grid" style="margin-top:0;">
                  <label class="modal-label">
                    <span>Дата</span>
                    <input type="date" name="res_date" value="<?= htmlspecialchars((string)date('Y-m-d')) ?>">
                  </label>
                  <label class="modal-label">
                    <span>Стол</span>
                    <input type="number" name="table_num_manual" min="1" max="500" inputmode="numeric">
                  </label>
                </div>
              </div>
              <div class="guests-time-row full">
                <div class="modal-label">
                  <span data-i18n="guests_count"><?= htmlspecialchars(tr('guests_count')) ?></span>
                  <div class="num-step">
                    <button class="num-btn" type="button" id="reqGuestsMinus" aria-label="<?= htmlspecialchars(tr('decrease_guests')) ?>">−</button>
                    <input type="number" id="reqGuests" name="guests" min="1" max="99" value="2" inputmode="numeric">
                    <button class="num-btn" type="button" id="reqGuestsPlus" aria-label="<?= htmlspecialchars(tr('increase_guests')) ?>">+</button>
                  </div>
                </div>
                <label class="modal-label">
                  <span data-i18n="start_time"><?= htmlspecialchars(tr('start_time')) ?></span>
                  <select id="reqStart" name="start_time" required>
                    <?php
                    for ($h = 10; $h <= 21; $h++) {
                      for ($m = 0; $m < 60; $m += 30) {
                        if ($h === 21 && $m > 0) continue;
                        $val = str_pad((string)$h, 2, '0', STR_PAD_LEFT) . ':' . str_pad((string)$m, 2, '0', STR_PAD_LEFT);
                        $sel = ($h === 12 && $m === 0) ? ' selected' : '';
                        echo "<option value=\"" . htmlspecialchars($val) . "\"$sel>" . htmlspecialchars($val) . "</option>";
                      }
                    }
                    ?>
                  </select>
                </label>
                <label class="modal-label">
                  <span data-i18n="duration"><?= htmlspecialchars(tr('duration')) ?></span>
                  <select id="reqDuration" name="duration_m">
                    <?php
                    $durations = [60 => '1', 90 => '1.5', 120 => '2', 150 => '2.5', 180 => '3', 210 => '3.5', 240 => '4', 270 => '4.5', 300 => '5'];
                    foreach ($durations as $v => $lbl) {
                      $sel = $v === 120 ? ' selected' : '';
                      echo "<option value=\"$v\"$sel>$lbl " . htmlspecialchars(tr('h_short')) . "</option>";
                    }
                    ?>
                  </select>
                </label>
                <div class="modal-label end-time-label" aria-live="polite">
                  <span>&nbsp;</span>
                  <div class="end-time-value" id="reqEndTime">до —</div>
                </div>
              </div>
              <label class="modal-label">
                <span data-i18n="your_name"><?= htmlspecialchars(tr('your_name')) ?></span>
                <input type="text" id="reqName" name="name" autocomplete="name" required maxlength="80">
              </label>
              <label class="modal-label">
                <div class="label-row">
                  <div class="label-left">
                    <span data-i18n="your_phone"><?= htmlspecialchars(tr('your_phone')) ?></span>
                    <span class="tg-nick" id="tgNick" hidden></span>
                    <span class="tg-nick" id="waNick" hidden></span>
                  </div>
                  <div class="label-right">
                    <div class="msgr-hint" id="msgrHint" hidden></div>
                  </div>
                </div>
                <div class="phone-field">
                  <input type="tel" id="reqPhone" name="phone" autocomplete="tel" inputmode="numeric" pattern="\+[1-9][0-9]{8,14}" required>
                  <div class="msgr-actions" id="msgrActions">
                    <button type="button" class="msgr-btn msgr-btn-inline" id="msgrTgBtn" aria-label="Telegram" title="Telegram">
                      <svg class="ico-tg" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path d="M20.6 5.3 4.2 11.7c-1.1.4-1.1 1-.2 1.3l4.2 1.3 1.6 4.8c.2.6.4.6.8.2l2.3-2.2 4.7 3.4c.9.5 1.5.2 1.7-.8l2.8-13.1c.3-1.2-.4-1.7-1.5-1.3Z" fill="currentColor" opacity=".9"/>
                        <path d="M9.1 14.9 18.3 8.9c.5-.3.9-.1.5.2l-7.6 6.9-.3 2.9c0 .4-.2.5-.4.1l-1.5-4.8Z" fill="currentColor"/>
                      </svg>
                      <span class="msgr-cd" data-msgr-cd="tg" aria-hidden="true"></span>
                    </button>
                    <button type="button" class="msgr-btn msgr-btn-inline" id="msgrWaBtn" aria-label="WhatsApp" title="WhatsApp">
                      <svg class="ico-wa" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path d="M12.04 2c-5.46 0-9.91 4.45-9.91 9.91 0 1.75.46 3.45 1.32 4.95L2.05 22l5.25-1.38c1.45.79 3.08 1.21 4.74 1.21 5.46 0 9.91-4.45 9.91-9.91 0-2.65-1.03-5.14-2.9-7.01A9.816 9.816 0 0 0 12.04 2zM12.04 20.13c-1.55 0-3.07-.42-4.39-1.21l-.31-.19-3.11.82.83-3.04-.21-.33a8.103 8.103 0 0 1-1.24-4.27c0-4.47 3.64-8.11 8.11-8.11 2.17 0 4.2 0.84 5.73 2.38 1.53 1.53 2.38 3.56 2.38 5.73 0 4.47-3.64 8.12-8.11 8.12zM16.48 13.84c-.24-.12-1.44-.71-1.66-.79-.22-.08-.38-.12-.54.12-.16.24-.61.76-.75.91-.14.15-.28.17-.52.05-.24-.12-1.01-.37-1.92-1.18-.71-.63-1.19-1.41-1.33-1.65-.14-.24-.01-.37.11-.49.11-.11.24-.28.36-.42.12-.14.16-.24.24-.4.08-.16.04-.3-.02-.42s-.54-1.31-.74-1.79c-.2-.47-.4-.41-.54-.41-.14 0-.3 0-.46 0s-.42.06-.64.3c-.22.24-.84.82-.84 2s.86 2.33.98 2.49c.12.16 1.7 2.59 4.11 3.64.57.25 1.02.4 1.37.51.58.18 1.1.16 1.51.1.46-.07 1.44-.59 1.64-1.16.2-.57.2-1.06.14-1.16-.06-.1-.22-.16-.46-.28z" fill="currentColor"/>
                      </svg>
                      <span class="msgr-cd" data-msgr-cd="wa" aria-hidden="true"></span>
                    </button>
                  </div>
                  <div class="msgr-toast" id="msgrToast" hidden></div>
                  <button type="button" class="msgr-field-icon" id="msgrTgIcon" aria-label="Telegram verified" title="Telegram" hidden>
                    <svg class="ico-tg" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                      <path d="M20.6 5.3 4.2 11.7c-1.1.4-1.1 1-.2 1.3l4.2 1.3 1.6 4.8c.2.6.4.6.8.2l2.3-2.2 4.7 3.4c.9.5 1.5.2 1.7-.8l2.8-13.1c.3-1.2-.4-1.7-1.5-1.3Z" fill="currentColor" opacity=".9"/>
                      <path d="M9.1 14.9 18.3 8.9c.5-.3.9-.1.5.2l-7.6 6.9-.3 2.9c0 .4-.2.5-.4.1l-1.5-4.8Z" fill="currentColor"/>
                    </svg>
                  </button>
                  <button type="button" class="msgr-field-icon" id="msgrWaIcon" aria-label="WhatsApp verified" title="WhatsApp" hidden>
                    <svg class="ico-wa" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                      <path d="M12.04 2c-5.46 0-9.91 4.45-9.91 9.91 0 1.75.46 3.45 1.32 4.95L2.05 22l5.25-1.38c1.45.79 3.08 1.21 4.74 1.21 5.46 0 9.91-4.45 9.91-9.91 0-2.65-1.03-5.14-2.9-7.01A9.816 9.816 0 0 0 12.04 2zM12.04 20.13c-1.55 0-3.07-.42-4.39-1.21l-.31-.19-3.11.82.83-3.04-.21-.33a8.103 8.103 0 0 1-1.24-4.27c0-4.47 3.64-8.11 8.11-8.11 2.17 0 4.2 0.84 5.73 2.38 1.53 1.53 2.38 3.56 2.38 5.73 0 4.47-3.64 8.12-8.11 8.12zM16.48 13.84c-.24-.12-1.44-.71-1.66-.79-.22-.08-.38-.12-.54.12-.16.24-.61.76-.75.91-.14.15-.28.17-.52.05-.24-.12-1.01-.37-1.92-1.18-.71-.63-1.19-1.41-1.33-1.65-.14-.24-.01-.37.11-.49.11-.11.24-.28.36-.42.12-.14.16-.24.24-.4.08-.16.04-.3-.02-.42s-.54-1.31-.74-1.79c-.2-.47-.4-.41-.54-.41-.14 0-.3 0-.46 0s-.42.06-.64.3c-.22.24-.84.82-.84 2s.86 2.33.98 2.49c.12.16 1.7 2.59 4.11 3.64.57.25 1.02.4 1.37.51.58.18 1.1.16 1.51.1.46-.07 1.44-.59 1.64-1.16.2-.57.2-1.06.14-1.16-.06-.1-.22-.16-.46-.28z" fill="currentColor"/>
                    </svg>
                  </button>
                </div>
              </label>
              <label class="modal-label full" id="reqCommentLabel">
                <span data-i18n="comment"><?= htmlspecialchars(tr('comment')) ?></span>
                <textarea id="reqComment" name="comment" class="preorder-box" rows="4" placeholder="<?= htmlspecialchars(tr('comment_placeholder')) ?>"></textarea>
              </label>
              <label class="modal-label full" id="reqPreorderLabel" hidden>
                <span class="desktop-text" data-i18n="preorder_title"><?= htmlspecialchars(tr('preorder_title')) ?></span>
                <div class="mobile-text mobile-preorder-title-wrap">
                  <span data-i18n="preorder_title_mobile"><?= htmlspecialchars(tr('preorder_title_mobile')) ?></span>
                  <button type="button" class="btn-preorder-mobile-inline" id="btnOpenMobilePreorder" aria-label="Menu" data-i18n="menu_btn"><?= htmlspecialchars(tr('menu_btn')) ?></button>
                </div>
                <div id="reqPreorderBox" class="preorder-box" aria-readonly="true"></div>
                <input type="hidden" id="reqPreorderHidden" name="preorder" value="">
                <input type="hidden" id="reqPreorderRuHidden" name="preorder_ru" value="">
              </label>
            </div>
          </div>
          <div class="req-right" id="preorderPanel" hidden>
            <div class="pre-title" data-i18n="preorder_title"><?= htmlspecialchars(tr('preorder_title')) ?></div>
            <div class="pre-body" id="preorderBody"></div>
          </div>
        </div>
        <div class="modal-hint" id="reqHint" hidden></div>

        <div class="modal-note" data-i18n="booking_note"><?= htmlspecialchars(tr('booking_note')) ?></div>
        <div class="modal-actions">
          <button class="btn btn-primary" type="submit" id="reqSubmit" data-i18n="send"><?= htmlspecialchars(tr('send')) ?></button>
        </div>
      </form>
    </div>
  </div>

  <div class="table-toast" id="tableToast" aria-live="polite" aria-atomic="true">
    <div class="t-title" id="toastTitle"></div>
    <div class="t-reason" id="toastReason"></div>
  </div>

  <div class="modal" id="mobilePreorderModal" aria-hidden="true">
    <div class="modal-backdrop modal-backdrop-strong" data-modal-close="mobilePreorderModal"></div>
    <div class="modal-card preorder-modal-card" role="dialog" aria-modal="true" aria-labelledby="mobilePreorderTitle">
      <div class="modal-title-bar">
        <div class="modal-title-left">
          <div class="modal-title" id="mobilePreorderTitle" data-i18n="preorder_title"><?= htmlspecialchars(tr('preorder_title')) ?></div>
          <div class="modal-total" id="mobilePreorderTotal"></div>
        </div>
        <button class="btn-close-modal" type="button" data-modal-close="mobilePreorderModal" aria-label="Close">×</button>
      </div>
      <div class="mobile-preorder-layout">
        <div class="preorder-top">
          <div id="mobilePreorderBox" class="preorder-box"></div>
        </div>
        <div class="preorder-bottom">
          <div id="mobilePreorderMenuBody" class="pre-body"></div>
        </div>
      </div>
    </div>
  </div>
  
  <script src="/tr3/assets/tr3.boot.js?v=20260416_0344" defer></script>
</body>
</html>

