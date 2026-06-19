<?php
/**
 * Influencer cabinet — standalone, mobile-first, multilingual (en/ru/vi).
 *
 * @var string                          $mode      'login' | 'report'
 * @var string                          $lang      locale code
 * @var \App\Bloggers\Support\BloggerLang $t        translator
 * @var string                          $googleUrl  (login)
 * @var string                          $csrf       CSRF token
 * @var array                           $flash      ['ok'=>string,'err'=>string]
 * @var array                           $regData    (login) re-fill on error
 * @var string                          $name       (report)
 * @var array|null                      $row        (report) incl. limit_pct, socials[]
 * @var list<array>                     $checks     (report)
 * @var string                          $dateFrom   (report)
 * @var string                          $dateTo     (report)
 */
use App\Bloggers\Support\SocialNetworks;
use App\Home\I18n\Locale;

$mode     = $mode ?? 'login';
$row      = $row ?? null;
$checks   = $checks ?? [];
$regData  = $regData ?? [];
$csrf     = $csrf ?? '';
$lang     = $lang ?? 'en';
$dateFrom = $dateFrom ?? date('Y-m-01');
$dateTo   = $dateTo ?? date('Y-m-d');
$flash    = $flash ?? ['ok' => '', 'err' => ''];

$esc    = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$tt     = static fn (string $k, array $p = []): string => $esc($t->t($k, $p));      // escaped
$traw   = static fn (string $k, array $p = []): string => $t->t($k, $p);            // trusted HTML
$fmtVnd = static fn ($minor): string => number_format((int) round((float) $minor / 100), 0, '.', ' ');
$fmtPct = static fn ($p): string => rtrim(rtrim(number_format((float) $p, 2, '.', ''), '0'), '.') ?: '0';
$netLabel = static fn (string $c): string => $c === 'site' ? $t->t('social.site') : SocialNetworks::label($c);

// language switcher hrefs (preserve period in report mode)
$langHref = static function (string $l) use ($mode, $dateFrom, $dateTo): string {
    $q = 'lang=' . $l;
    if ($mode === 'report') {
        $q .= '&dateFrom=' . urlencode($dateFrom) . '&dateTo=' . urlencode($dateTo);
    }
    return '/bloggers?' . $q;
};

// <option> list for a social <select>, with $sel pre-selected
$netOptions = static function (string $sel) use ($esc, $netLabel): string {
    $out = '';
    foreach (SocialNetworks::all() as $code => $_label) {
        $s = $code === $sel ? ' selected' : '';
        $out .= '<option value="' . $esc($code) . '"' . $s . '>' . $esc($netLabel($code)) . '</option>';
    }
    return $out;
};

// registration rows to render (re-fill on error; always ≥ 2 rows)
$regRows = [];
$rn = is_array($regData['social_net'] ?? null) ? $regData['social_net'] : [];
$rv = is_array($regData['social_val'] ?? null) ? $regData['social_val'] : [];
foreach ($rn as $i => $net) {
    $regRows[] = ['net' => (string) $net, 'val' => (string) ($rv[$i] ?? '')];
}
while (count($regRows) < 2) {
    $regRows[] = ['net' => '', 'val' => ''];
}
$regVal = static fn (string $k): string => $esc($regData[$k] ?? '');
?><!DOCTYPE html>
<html lang="<?= $esc(Locale::htmlLang($lang)) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<title>Veranda — influencer</title>
<link rel="icon" type="image/svg+xml" href="/links/favicon.svg">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@600;700&display=swap" rel="stylesheet">
<style>
:root{
  --bg:#0b0d12; --bg2:#0f1117; --card:#161922; --card2:#1c2030; --line:#272b39;
  --text:#eef1f7; --muted:#9aa3b8; --faint:#6b7385;
  --gold:#C79A54; --gold-soft:rgba(199,154,84,.14); --gold-line:rgba(199,154,84,.42);
  --ok:#34d399; --ok-soft:rgba(52,211,153,.12); --err:#f87171; --err-soft:rgba(248,113,113,.12);
  --r:16px; --r-sm:11px;
}
*{box-sizing:border-box;margin:0;padding:0}
html{-webkit-text-size-adjust:100%}
body{
  background:radial-gradient(120% 90% at 50% -10%, #14171f 0%, var(--bg) 55%) no-repeat var(--bg);
  color:var(--text);font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;
  min-height:100vh;line-height:1.5;
  padding:env(safe-area-inset-top) 0 calc(env(safe-area-inset-bottom) + 2rem);
}
.wrap{max-width:560px;margin:0 auto;padding:1.15rem}
a{color:inherit}
.topline{display:flex;align-items:center;justify-content:space-between;margin:.4rem 0 1.3rem}
.brand{font-family:'Cinzel',Georgia,serif;font-weight:700;letter-spacing:.3em;color:var(--gold);font-size:.82rem;text-indent:.3em}
.lang{display:flex;gap:.1rem;border:1px solid var(--line);border-radius:999px;padding:.15rem;background:var(--card)}
.lang a{font-size:.72rem;font-weight:700;letter-spacing:.04em;color:var(--muted);text-decoration:none;padding:.28rem .55rem;border-radius:999px;line-height:1}
.lang a.on{background:var(--gold-soft);color:var(--gold)}

.flash{padding:.8rem 1rem;border-radius:var(--r-sm);margin-bottom:1.1rem;font-size:.9rem;line-height:1.5;border:1px solid}
.flash.ok{background:var(--ok-soft);border-color:rgba(52,211,153,.32);color:#6ee7b7}
.flash.err{background:var(--err-soft);border-color:rgba(248,113,113,.32);color:#fca5a5}

.card{background:var(--card);border:1px solid var(--line);border-radius:var(--r);padding:1.15rem 1.2rem}
.card+.card{margin-top:.9rem}

/* welcome */
.hero{text-align:center;padding:.2rem 0 1.5rem}
.hero .eyebrow{font-size:.74rem;letter-spacing:.16em;text-transform:uppercase;color:var(--gold);font-weight:700;margin-bottom:.7rem}
.hero h1{font-size:1.72rem;line-height:1.18;font-weight:800;letter-spacing:-.01em;margin-bottom:.7rem}
.hero h1 i{font-style:normal;color:var(--gold)}
.hero p{color:var(--muted);font-size:.96rem;max-width:30rem;margin:0 auto}
.perks{display:grid;gap:.7rem;margin:1.5rem 0}
.perk{display:flex;gap:.9rem;align-items:flex-start;background:var(--card);border:1px solid var(--line);border-radius:var(--r);padding:.95rem 1.05rem}
.perk .ico{flex:none;width:42px;height:42px;border-radius:11px;display:grid;place-items:center;font-size:1.3rem;background:var(--gold-soft);border:1px solid var(--gold-line)}
.perk h3{font-size:.98rem;font-weight:700;margin-bottom:.2rem}
.perk p{color:var(--muted);font-size:.85rem;line-height:1.5}
.limit-box{background:linear-gradient(180deg,var(--gold-soft),transparent 80%),var(--card);border:1px solid var(--gold-line);border-radius:var(--r);padding:1.2rem;margin:1.5rem 0}
.limit-box .badge{display:inline-flex;align-items:center;gap:.4rem;font-size:.74rem;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:var(--gold);background:var(--gold-soft);border:1px solid var(--gold-line);border-radius:999px;padding:.28rem .7rem;margin-bottom:.7rem}
.limit-box h2{font-size:1.12rem;margin-bottom:.5rem}
.limit-box p{color:var(--muted);font-size:.9rem;margin-bottom:.55rem}
.limit-box p:last-child{margin-bottom:0}
.limit-box p b{color:var(--text)}
.limit-box .contact{color:var(--gold);font-weight:600;text-decoration:none;border-bottom:1px solid var(--gold-line)}
.sec-h{display:flex;align-items:baseline;gap:.6rem;margin:.1rem 0 .9rem}
.sec-h h2{font-size:1.02rem;font-weight:700}
.sec-h span{color:var(--faint);font-size:.82rem}
.gbtn{display:flex;align-items:center;justify-content:center;gap:.6rem;width:100%;padding:.92rem 1rem;background:#fff;color:#1f2024;border-radius:var(--r-sm);text-decoration:none;font-weight:600;font-size:.98rem;border:1px solid #e3e3e3}
.gbtn:active{opacity:.9}
.gbtn svg{width:19px;height:19px;flex:none}
.login-card{text-align:center}
.login-card p{color:var(--muted);font-size:.86rem;margin-bottom:.85rem}
.fld{display:flex;flex-direction:column;gap:.32rem;margin-bottom:.85rem}
.fld label{font-size:.8rem;color:var(--muted);font-weight:500}
.fld label .req{color:var(--gold)}
.fld input,.fld select{background:var(--bg2);border:1px solid var(--line);border-radius:var(--r-sm);padding:.72rem .85rem;color:var(--text);font-size:16px;width:100%;-webkit-appearance:none;appearance:none}
.fld input:focus,.fld select:focus{outline:none;border-color:var(--gold);box-shadow:0 0 0 3px var(--gold-soft)}
.fld input::placeholder{color:var(--faint)}
.fld .hint{font-size:.76rem;color:var(--faint);line-height:1.45}
.subhead{font-size:.76rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--faint);margin:.6rem 0 .15rem}
.subhead .h{font-weight:400;text-transform:none;letter-spacing:0;color:var(--faint);display:block;margin-top:.2rem;font-size:.74rem}
.hp{position:absolute!important;left:-9999px!important;width:1px;height:1px;opacity:0;pointer-events:none}
.soc-row{display:flex;gap:.5rem;margin-bottom:.5rem;align-items:center}
.soc-row select{flex:0 0 40%}
.soc-row input{flex:1 1 auto}
.soc-row .rm{flex:none;width:38px;height:38px;border-radius:9px;border:1px solid var(--line);background:var(--card2);color:var(--muted);font-size:1.2rem;line-height:1;cursor:pointer}
.soc-add{background:none;border:1px dashed var(--gold-line);color:var(--gold);border-radius:var(--r-sm);padding:.6rem;width:100%;font-size:.88rem;font-weight:600;cursor:pointer;margin-top:.15rem}
.soc-warn{display:none;color:var(--err);font-size:.8rem;margin-top:.4rem}
.btn-primary{display:block;width:100%;background:linear-gradient(180deg,#d4a85f,var(--gold));color:#1a1305;border:none;border-radius:var(--r-sm);padding:.92rem;font-size:1rem;font-weight:700;cursor:pointer;margin-top:.6rem}
.btn-primary:active{filter:brightness(.96)}
.divider{display:flex;align-items:center;gap:.85rem;margin:1.4rem .2rem;color:var(--faint);font-size:.8rem}
.divider::before,.divider::after{content:'';flex:1;height:1px;background:var(--line)}
.fine{text-align:center;color:var(--faint);font-size:.78rem;line-height:1.5;margin-top:1.4rem}

/* dashboard */
.topbar{display:flex;align-items:center;justify-content:space-between;gap:.6rem;margin-bottom:1.1rem}
.topbar .who{font-size:.8rem;color:var(--muted)}
.topbar .who b{display:block;color:var(--text);font-size:.98rem;font-weight:700;margin-top:.05rem}
.topbar .right{display:flex;align-items:center;gap:.5rem}
.logout{color:var(--muted);font-size:.82rem;text-decoration:none;border:1px solid var(--line);border-radius:9px;padding:.45rem .75rem;white-space:nowrap}
.promo-card{background:linear-gradient(135deg,var(--card2),var(--card));border:1px solid var(--gold-line);border-radius:var(--r);padding:1.15rem 1.2rem;margin-bottom:.9rem;text-align:center}
.promo-card .l{font-size:.74rem;text-transform:uppercase;letter-spacing:.08em;color:var(--gold);margin-bottom:.45rem}
.promo-card .code{font-family:'Cinzel',Georgia,serif;font-weight:700;font-size:1.9rem;color:var(--text);letter-spacing:.04em;word-break:break-word;line-height:1.1}
.promo-card .sub{color:var(--muted);font-size:.82rem;margin-top:.5rem}
.period{display:flex;gap:.5rem;align-items:flex-end;flex-wrap:wrap;margin-bottom:.95rem}
.period .f{display:flex;flex-direction:column;flex:1 1 38%;gap:.25rem}
.period label{font-size:.72rem;color:var(--muted)}
.period input{background:var(--bg2);border:1px solid var(--line);border-radius:9px;padding:.6rem;color:var(--text);font-size:16px;width:100%}
.period button{flex:1 1 100%;background:var(--card2);color:var(--text);border:1px solid var(--line);border-radius:9px;padding:.65rem;font-size:.92rem;font-weight:600;cursor:pointer}
.payout-hero{background:linear-gradient(180deg,var(--gold-soft),transparent),var(--card);border:1px solid var(--gold-line);border-radius:var(--r);padding:1.15rem 1.2rem;margin-bottom:.9rem;text-align:center}
.payout-hero .l{font-size:.76rem;text-transform:uppercase;letter-spacing:.06em;color:var(--gold);margin-bottom:.3rem}
.payout-hero .v{font-size:2.3rem;font-weight:800;color:var(--text);font-variant-numeric:tabular-nums;line-height:1.05}
.payout-hero .v .u{font-size:1.1rem;color:var(--muted);font-weight:600;margin-left:.2rem}
.payout-hero .meta{color:var(--muted);font-size:.82rem;margin-top:.5rem}
.stats{display:grid;grid-template-columns:repeat(2,1fr);gap:.65rem;margin-bottom:.9rem}
.stat{background:var(--card);border:1px solid var(--line);border-radius:var(--r-sm);padding:.85rem .95rem}
.stat .l{color:var(--muted);font-size:.72rem;text-transform:uppercase;letter-spacing:.03em}
.stat .v{font-size:1.28rem;font-weight:700;margin-top:.22rem;font-variant-numeric:tabular-nums}
.stat .v .u{font-size:.82rem;color:var(--muted);font-weight:600;margin-left:.12rem}
.stat.ok .v{color:var(--ok)}
.chips{display:flex;flex-wrap:wrap;gap:.4rem}
.chip{display:inline-flex;gap:.4rem;align-items:center;background:var(--bg2);border:1px solid var(--line);border-radius:999px;padding:.35rem .7rem;font-size:.82rem;max-width:100%}
.chip b{color:var(--gold);font-weight:600}
.chip span{color:var(--text);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.tbl-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch;border:1px solid var(--line);border-radius:var(--r-sm)}
table{width:100%;border-collapse:collapse;font-size:.88rem}
thead th{background:var(--bg2);color:var(--muted);font-weight:600;font-size:.72rem;text-transform:uppercase;letter-spacing:.03em;padding:.6rem .85rem;text-align:right;white-space:nowrap}
thead th:first-child{text-align:left}
tbody tr+tr{border-top:1px solid var(--line)}
td{padding:.6rem .85rem;text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums}
td:first-child{text-align:left;color:var(--muted);font-size:.84rem}
.empty{padding:1.3rem;text-align:center;color:var(--faint);font-size:.86rem}
.foot{color:var(--faint);font-size:.78rem;line-height:1.5;margin-top:.7rem;text-align:center}
.dist .limit-line{display:flex;align-items:center;justify-content:space-between;gap:.6rem;margin-bottom:.9rem;padding-bottom:.9rem;border-bottom:1px solid var(--line)}
.dist .limit-line .big{font-size:1.5rem;font-weight:800;color:var(--gold);font-variant-numeric:tabular-nums}
.dist .limit-line .lbl{font-size:.8rem;color:var(--muted)}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:.6rem}
.pct-hint{font-size:.8rem;margin:.1rem 0 .2rem;font-weight:600}
.pct-hint.ok{color:var(--ok)}
.pct-hint.over{color:var(--err)}
.dist .raise{font-size:.82rem;color:var(--muted);margin-top:.85rem;line-height:1.5}
.dist .raise a{color:var(--gold);font-weight:600;text-decoration:none;border-bottom:1px solid var(--gold-line)}
</style>
</head>
<body>
<div class="wrap">

<?php
// language switcher (shared)
$switcher = '<nav class="lang">';
foreach (Locale::SUPPORTED as $l) {
    $on = $l === $lang ? ' on' : '';
    $switcher .= '<a class="' . trim($on) . '" href="' . $esc($langHref($l)) . '">' . $esc(strtoupper($l)) . '</a>';
}
$switcher .= '</nav>';
?>

<?php if ($mode === 'login'): /* ════════ WELCOME ════════ */ ?>

  <div class="topline"><div class="brand">VERANDA</div><?= $switcher ?></div>

  <?php if (!empty($flash['ok'])): ?><div class="flash ok"><?= $esc($flash['ok']) ?></div><?php endif; ?>
  <?php if (!empty($flash['err'])): ?><div class="flash err"><?= $esc($flash['err']) ?></div><?php endif; ?>

  <div class="hero">
    <div class="eyebrow"><?= $tt('w.eyebrow') ?></div>
    <h1><?= $traw('w.title') ?></h1>
    <p><?= $tt('w.lead') ?></p>
  </div>

  <div class="perks">
    <div class="perk"><div class="ico">🎟️</div><div><h3><?= $tt('w.perk1.t') ?></h3><p><?= $tt('w.perk1.d') ?></p></div></div>
    <div class="perk"><div class="ico">💸</div><div><h3><?= $tt('w.perk2.t') ?></h3><p><?= $tt('w.perk2.d') ?></p></div></div>
    <div class="perk"><div class="ico">📊</div><div><h3><?= $tt('w.perk3.t') ?></h3><p><?= $tt('w.perk3.d') ?></p></div></div>
  </div>

  <div class="limit-box">
    <span class="badge"><?= $tt('w.limit.badge') ?></span>
    <h2><?= $tt('w.limit.title') ?></h2>
    <p><?= $traw('w.limit.p1') ?></p>
    <p><?= $traw('w.limit.p2') ?></p>
  </div>

  <div class="card login-card">
    <p><?= $tt('w.have') ?></p>
    <a class="gbtn" href="<?= $esc($googleUrl ?? '#') ?>">
      <svg viewBox="0 0 48 48"><path fill="#EA4335" d="M24 9.5c3.5 0 6.6 1.2 9.1 3.6l6.8-6.8C35.9 2.4 30.4 0 24 0 14.6 0 6.5 5.4 2.6 13.2l7.9 6.1C12.4 13.3 17.7 9.5 24 9.5z"/><path fill="#4285F4" d="M46.1 24.5c0-1.6-.1-3.1-.4-4.5H24v9h12.4c-.5 2.9-2.1 5.3-4.6 7l7.1 5.5c4.2-3.9 6.6-9.6 6.6-16z"/><path fill="#FBBC05" d="M10.5 28.3c-.5-1.4-.7-2.8-.7-4.3s.3-2.9.7-4.3l-7.9-6.1C1 16.8 0 20.3 0 24s1 7.2 2.6 10.4l7.9-6.1z"/><path fill="#34A853" d="M24 48c6.5 0 11.9-2.1 15.9-5.8l-7.1-5.5c-2 1.3-4.5 2.1-8.8 2.1-6.3 0-11.6-3.8-13.5-9.3l-7.9 6.1C6.5 42.6 14.6 48 24 48z"/></svg>
      <?= $tt('w.google') ?>
    </a>
  </div>

  <div class="divider"><?= $tt('w.divider') ?></div>

  <div class="card">
    <div class="sec-h"><h2><?= $tt('w.reg.h') ?></h2><span><?= $tt('w.reg.free') ?></span></div>
    <form id="reg-form" method="post" action="/bloggers?lang=<?= $esc($lang) ?>" autocomplete="off">
      <input type="hidden" name="register" value="1">
      <input type="hidden" name="csrf_token" value="<?= $esc($csrf) ?>">
      <input class="hp" type="text" name="website" tabindex="-1" autocomplete="off" aria-hidden="true">

      <div class="fld">
        <label><?= $tt('f.name') ?> <span class="req">*</span></label>
        <input type="text" name="name" value="<?= $regVal('name') ?>" required maxlength="100" placeholder="<?= $tt('f.name.ph') ?>">
      </div>
      <div class="fld">
        <label><?= $tt('f.email') ?> <span class="req">*</span></label>
        <input type="email" name="email" value="<?= $regVal('email') ?>" required placeholder="your@gmail.com">
        <span class="hint"><?= $tt('f.email.hint') ?></span>
      </div>
      <div class="fld">
        <label><?= $tt('f.promo') ?> <span class="req">*</span></label>
        <input type="text" name="promocode" value="<?= $regVal('promocode') ?>" required maxlength="50" placeholder="ANNA" pattern="[^\s]+">
        <span class="hint"><?= $tt('f.promo.hint') ?></span>
      </div>

      <div class="subhead"><?= $tt('w.socials.h') ?><span class="h"><?= $tt('w.socials.hint') ?></span></div>
      <div id="soc-list">
        <?php foreach ($regRows as $r): ?>
          <div class="soc-row" data-soc-row>
            <select name="social_net[]"><?= $netOptions($r['net']) ?></select>
            <input type="text" name="social_val[]" value="<?= $esc($r['val']) ?>" placeholder="<?= $tt('social.val.ph') ?>">
            <button type="button" class="rm" data-soc-remove aria-label="<?= $tt('social.remove') ?>">×</button>
          </div>
        <?php endforeach; ?>
      </div>
      <button type="button" class="soc-add" id="soc-add"><?= $tt('w.social.add') ?></button>
      <div class="soc-warn" id="soc-warn"><?= $tt('err.min_socials') ?></div>

      <button type="submit" class="btn-primary"><?= $tt('w.reg.submit') ?></button>
    </form>
    <p class="fine"><?= $tt('w.reg.fine') ?></p>
  </div>

  <template id="soc-tpl">
    <div class="soc-row" data-soc-row>
      <select name="social_net[]"><?= $netOptions('') ?></select>
      <input type="text" name="social_val[]" placeholder="<?= $tt('social.val.ph') ?>">
      <button type="button" class="rm" data-soc-remove aria-label="<?= $tt('social.remove') ?>">×</button>
    </div>
  </template>

<?php else: /* ════════ DASHBOARD ════════ */
  $limit   = (float) ($row['limit_pct'] ?? 15);
  $isStart = $limit <= 5.0;
  $socials = is_array($row['socials'] ?? null) ? $row['socials'] : [];
?>

  <div class="topbar">
    <div class="who"><?= $tt('d.who') ?><b><?= $esc($name !== '' ? $name : ($row['name'] ?? '')) ?></b></div>
    <div class="right"><?= $switcher ?><a class="logout" href="/bloggers/logout"><?= $tt('d.logout') ?></a></div>
  </div>

  <?php if (!empty($flash['ok'])): ?><div class="flash ok"><?= $esc($flash['ok']) ?></div><?php endif; ?>
  <?php if (!empty($flash['err'])): ?><div class="flash err"><?= $esc($flash['err']) ?></div><?php endif; ?>

  <?php if ($row === null): ?>
    <div class="card"><p class="empty" style="padding:.3rem 0"><?= $tt('d.load.err') ?></p></div>
  <?php else: ?>

    <div class="promo-card">
      <div class="l"><?= $tt('d.promo.l') ?></div>
      <div class="code"><?= $esc($row['promocode']) ?></div>
      <div class="sub"><?= $tt('d.promo.sub') ?></div>
    </div>

    <form class="period" method="get" action="/bloggers">
      <input type="hidden" name="lang" value="<?= $esc($lang) ?>">
      <div class="f"><label><?= $tt('d.period.from') ?></label><input type="date" name="dateFrom" value="<?= $esc($dateFrom) ?>"></div>
      <div class="f"><label><?= $tt('d.period.to') ?></label><input type="date" name="dateTo" value="<?= $esc($dateTo) ?>"></div>
      <button type="submit"><?= $tt('d.period.btn') ?></button>
    </form>

    <div class="payout-hero">
      <div class="l"><?= $tt('d.payout') ?></div>
      <div class="v"><?= $fmtVnd($row['topay']) ?><span class="u">₫</span></div>
      <div class="meta"><?= $tt('d.payout.meta', ['a' => $fmtVnd($row['cashback']), 'p' => $fmtVnd($row['paid'])]) ?></div>
    </div>

    <div class="stats">
      <div class="stat"><div class="l"><?= $tt('d.stat.checks') ?></div><div class="v"><?= (int) $row['checks'] ?></div></div>
      <div class="stat"><div class="l"><?= $tt('d.stat.revenue') ?></div><div class="v"><?= $fmtVnd($row['revenue']) ?><span class="u">₫</span></div></div>
      <div class="stat"><div class="l"><?= $tt('d.stat.accrued') ?></div><div class="v"><?= $fmtVnd($row['cashback']) ?><span class="u">₫</span></div></div>
      <div class="stat ok"><div class="l"><?= $tt('d.stat.paid') ?></div><div class="v"><?= $fmtVnd($row['paid']) ?><span class="u">₫</span></div></div>
    </div>

    <?php if (!empty($socials)): ?>
      <div class="sec-h"><h2><?= $tt('d.socials.h') ?></h2></div>
      <div class="card"><div class="chips">
        <?php foreach ($socials as $s): ?>
          <span class="chip"><b><?= $esc($netLabel((string) ($s['net'] ?? ''))) ?></b><span><?= $esc($s['val'] ?? '') ?></span></span>
        <?php endforeach; ?>
      </div></div>
    <?php endif; ?>

    <div class="sec-h"><h2><?= $tt('d.checks.h') ?></h2><span><?= count($checks) ?></span></div>
    <div class="tbl-wrap">
      <?php if (empty($checks)): ?>
        <div class="empty"><?= $tt('d.checks.empty') ?></div>
      <?php else: ?>
        <table>
          <thead><tr><th><?= $tt('d.tbl.date') ?></th><th><?= $tt('d.tbl.sum') ?></th><th><?= $tt('d.tbl.disc') ?></th></tr></thead>
          <tbody>
            <?php foreach ($checks as $ch):
              $dt  = (string) ($ch['date_close'] ?? '');
              $sum = (int) round((float) ($ch['payed_sum']    ?? 0) / 100);
              $dis = (int) round((float) ($ch['discount_sum'] ?? 0) / 100);
            ?>
            <tr>
              <td><?= $dt !== '' ? $esc(date('d.m.y · H:i', strtotime($dt))) : '—' ?></td>
              <td><?= number_format($sum, 0, '.', ' ') ?></td>
              <td><?= $dis > 0 ? number_format($dis, 0, '.', ' ') : '—' ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

    <div class="sec-h" style="margin-top:1.5rem"><h2><?= $tt('d.dist.h') ?></h2></div>
    <div class="card dist">
      <div class="limit-line">
        <div class="lbl"><?= $traw('d.limit.l') ?></div>
        <div class="big"><?= $fmtPct($limit) ?>%</div>
      </div>
      <form method="post" action="/bloggers?lang=<?= $esc($lang) ?>&dateFrom=<?= $esc($dateFrom) ?>&dateTo=<?= $esc($dateTo) ?>">
        <input type="hidden" name="save_self" value="1">
        <input type="hidden" name="csrf_token" value="<?= $esc($csrf) ?>">
        <div class="fld">
          <label><?= $tt('d.f.promo') ?></label>
          <input type="text" name="promocode" value="<?= $esc($row['promocode']) ?>" required maxlength="50" pattern="[^\s]+">
        </div>
        <div class="grid2">
          <div class="fld">
            <label><?= $tt('d.f.disc') ?></label>
            <input id="inp-disc" type="number" name="discount_pct" value="<?= $esc($fmtPct($row['discount_pct'] ?? 0)) ?>" min="0" max="<?= $esc($fmtPct($limit)) ?>" step="0.5" required>
          </div>
          <div class="fld">
            <label><?= $tt('d.f.cash') ?></label>
            <input id="inp-cash" type="number" name="cashback_pct" value="<?= $esc($fmtPct($row['cashback_pct'] ?? 0)) ?>" min="0" max="<?= $esc($fmtPct($limit)) ?>" step="0.5" required>
          </div>
        </div>
        <div id="pct-hint" class="pct-hint ok" data-limit="<?= $esc($fmtPct($limit)) ?>" data-tpl="<?= $esc($t->t('d.hint.tpl')) ?>"></div>
        <button type="submit" class="btn-primary"><?= $tt('d.save') ?></button>
      </form>
      <p class="raise"><?= $isStart ? $traw('d.raise.start') : $traw('d.raise.more') ?></p>
    </div>

    <p class="foot"><?= $tt('d.foot') ?></p>
  <?php endif; ?>

<?php endif; ?>
</div>

<script>
(function () {
  // dynamic social rows
  var list = document.getElementById('soc-list');
  var add  = document.getElementById('soc-add');
  var tpl  = document.getElementById('soc-tpl');
  if (list && add && tpl && 'content' in tpl) {
    add.addEventListener('click', function () { list.appendChild(tpl.content.cloneNode(true)); });
    list.addEventListener('click', function (e) {
      var b = e.target.closest('[data-soc-remove]');
      if (!b) return;
      if (list.querySelectorAll('[data-soc-row]').length > 1) b.closest('[data-soc-row]').remove();
    });
  }
  var form = document.getElementById('reg-form');
  if (form) form.addEventListener('submit', function (e) {
    var filled = 0;
    form.querySelectorAll('input[name="social_val[]"]').forEach(function (i) { if (i.value.trim() !== '') filled++; });
    if (filled < 2) { e.preventDefault(); var w = document.getElementById('soc-warn'); if (w) w.style.display = 'block'; }
  });

  // distribution hint
  var disc = document.getElementById('inp-disc');
  var cash = document.getElementById('inp-cash');
  var hint = document.getElementById('pct-hint');
  if (disc && cash && hint) {
    var limit = parseFloat(hint.getAttribute('data-limit')) || 15;
    var tplStr = hint.getAttribute('data-tpl') || 'Total {s}% of {l}%';
    var upd = function () {
      var s = Math.round(((parseFloat(disc.value) || 0) + (parseFloat(cash.value) || 0)) * 100) / 100;
      hint.textContent = tplStr.replace('{s}', s).replace('{l}', limit);
      hint.className = 'pct-hint ' + (s > limit ? 'over' : 'ok');
    };
    disc.addEventListener('input', upd);
    cash.addEventListener('input', upd);
    upd();
  }
})();
</script>
</body>
</html>
