<?php
/**
 * Influencer cabinet — standalone mobile-first page (no admin layout).
 *
 * @var string      $mode      'login' | 'report'
 * @var string      $googleUrl  (login) Google consent URL
 * @var string      $csrf       CSRF synchroniser token
 * @var array       $flash      ['ok'=>string,'err'=>string]
 * @var array       $regData    (login) form values to re-fill on error
 * @var string      $name       (report) display name
 * @var array|null  $row        (report) report row (incl. limit_pct)
 * @var list<array> $checks     (report) closed checks
 * @var string      $dateFrom   (report)
 * @var string      $dateTo     (report)
 */
$mode     = $mode ?? 'login';
$row      = $row ?? null;
$checks   = $checks ?? [];
$regData  = $regData ?? [];
$csrf     = $csrf ?? '';
$dateFrom = $dateFrom ?? date('Y-m-01');
$dateTo   = $dateTo ?? date('Y-m-d');
$flash    = $flash ?? ['ok' => '', 'err' => ''];
$esc      = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$val      = static fn (string $k): string => $esc($regData[$k] ?? '');
$fmtVnd   = static fn ($minor): string => number_format((int) round((float) $minor / 100), 0, '.', ' ');
$fmtPct   = static fn ($p): string => rtrim(rtrim(number_format((float) $p, 2, '.', ''), '0'), '.') ?: '0';
?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<title>Veranda — кабинет инфлюенсера</title>
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
.brand{font-family:'Cinzel',Georgia,serif;font-weight:700;letter-spacing:.32em;color:var(--gold);
  font-size:.82rem;text-align:center;margin:.6rem 0 1.6rem;text-indent:.32em}

/* flash */
.flash{padding:.8rem 1rem;border-radius:var(--r-sm);margin-bottom:1.1rem;font-size:.9rem;line-height:1.5;border:1px solid}
.flash.ok{background:var(--ok-soft);border-color:rgba(52,211,153,.32);color:#6ee7b7}
.flash.err{background:var(--err-soft);border-color:rgba(248,113,113,.32);color:#fca5a5}

/* generic card */
.card{background:var(--card);border:1px solid var(--line);border-radius:var(--r);padding:1.15rem 1.2rem}
.card+.card{margin-top:.9rem}

/* ─── WELCOME ─────────────────────────────────────────── */
.hero{text-align:center;padding:.4rem 0 1.6rem}
.hero .eyebrow{font-size:.74rem;letter-spacing:.16em;text-transform:uppercase;color:var(--gold);font-weight:700;margin-bottom:.7rem}
.hero h1{font-size:1.72rem;line-height:1.18;font-weight:800;letter-spacing:-.01em;margin-bottom:.7rem}
.hero h1 i{font-style:normal;color:var(--gold)}
.hero p{color:var(--muted);font-size:.96rem;max-width:30rem;margin:0 auto}

.perks{display:grid;gap:.7rem;margin:1.5rem 0}
.perk{display:flex;gap:.9rem;align-items:flex-start;background:var(--card);border:1px solid var(--line);border-radius:var(--r);padding:.95rem 1.05rem}
.perk .ico{flex:none;width:42px;height:42px;border-radius:11px;display:grid;place-items:center;font-size:1.3rem;
  background:var(--gold-soft);border:1px solid var(--gold-line)}
.perk h3{font-size:.98rem;font-weight:700;margin-bottom:.2rem}
.perk p{color:var(--muted);font-size:.85rem;line-height:1.5}

/* limit explainer */
.limit-box{background:linear-gradient(180deg,var(--gold-soft),transparent 80%),var(--card);
  border:1px solid var(--gold-line);border-radius:var(--r);padding:1.2rem;margin:1.5rem 0}
.limit-box .badge{display:inline-flex;align-items:center;gap:.4rem;font-size:.74rem;font-weight:700;letter-spacing:.04em;
  text-transform:uppercase;color:var(--gold);background:var(--gold-soft);border:1px solid var(--gold-line);
  border-radius:999px;padding:.28rem .7rem;margin-bottom:.7rem}
.limit-box h2{font-size:1.12rem;margin-bottom:.5rem}
.limit-box p{color:var(--muted);font-size:.9rem;margin-bottom:.55rem}
.limit-box p b{color:var(--text)}
.limit-box .contact{color:var(--gold);font-weight:600;text-decoration:none;border-bottom:1px solid var(--gold-line)}

/* section heading */
.sec-h{display:flex;align-items:baseline;gap:.6rem;margin:1.7rem .2rem .7rem}
.sec-h h2{font-size:1.02rem;font-weight:700}
.sec-h span{color:var(--faint);font-size:.82rem}

/* Google button */
.gbtn{display:flex;align-items:center;justify-content:center;gap:.6rem;width:100%;padding:.92rem 1rem;
  background:#fff;color:#1f2024;border-radius:var(--r-sm);text-decoration:none;font-weight:600;font-size:.98rem;
  border:1px solid #e3e3e3;transition:transform .06s,opacity .15s}
.gbtn:active{transform:scale(.99);opacity:.9}
.gbtn svg{width:19px;height:19px;flex:none}
.login-card{text-align:center}
.login-card p{color:var(--muted);font-size:.86rem;margin-bottom:.85rem}

/* forms */
.fld{display:flex;flex-direction:column;gap:.32rem;margin-bottom:.85rem}
.fld:last-of-type{margin-bottom:0}
.fld label{font-size:.8rem;color:var(--muted);font-weight:500}
.fld label .req{color:var(--gold)}
.fld input,.fld textarea{
  background:var(--bg2);border:1px solid var(--line);border-radius:var(--r-sm);
  padding:.72rem .85rem;color:var(--text);font-size:16px;width:100%;transition:border-color .15s,box-shadow .15s;
  -webkit-appearance:none;appearance:none}
.fld input:focus{outline:none;border-color:var(--gold);box-shadow:0 0 0 3px var(--gold-soft)}
.fld input::placeholder{color:var(--faint)}
.fld .hint{font-size:.76rem;color:var(--faint);line-height:1.45}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:.6rem}
.subhead{font-size:.76rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--faint);margin:.4rem 0 .15rem}
.hp{position:absolute!important;left:-9999px!important;width:1px;height:1px;opacity:0;pointer-events:none}
.btn-primary{display:block;width:100%;background:linear-gradient(180deg,#d4a85f,var(--gold));color:#1a1305;border:none;
  border-radius:var(--r-sm);padding:.92rem;font-size:1rem;font-weight:700;cursor:pointer;margin-top:.3rem;
  transition:transform .06s,filter .15s}
.btn-primary:active{transform:scale(.99);filter:brightness(.96)}
.divider{display:flex;align-items:center;gap:.85rem;margin:1.4rem .2rem;color:var(--faint);font-size:.8rem}
.divider::before,.divider::after{content:'';flex:1;height:1px;background:var(--line)}
.fine{text-align:center;color:var(--faint);font-size:.78rem;line-height:1.5;margin-top:1.4rem}

/* ─── DASHBOARD ───────────────────────────────────────── */
.topbar{display:flex;align-items:center;justify-content:space-between;margin-bottom:1.1rem}
.topbar .who{font-size:.8rem;color:var(--muted)}
.topbar .who b{display:block;color:var(--text);font-size:.98rem;font-weight:700;margin-top:.05rem}
.logout{color:var(--muted);font-size:.82rem;text-decoration:none;border:1px solid var(--line);
  border-radius:9px;padding:.45rem .75rem;white-space:nowrap}
.logout:active{background:var(--card)}

.promo-card{background:linear-gradient(135deg,var(--card2),var(--card));border:1px solid var(--gold-line);
  border-radius:var(--r);padding:1.15rem 1.2rem;margin-bottom:.9rem;text-align:center}
.promo-card .l{font-size:.74rem;text-transform:uppercase;letter-spacing:.08em;color:var(--gold);margin-bottom:.45rem}
.promo-card .code{font-family:'Cinzel',Georgia,serif;font-weight:700;font-size:1.9rem;color:var(--text);
  letter-spacing:.04em;word-break:break-word;line-height:1.1}
.promo-card .sub{color:var(--muted);font-size:.82rem;margin-top:.5rem}

.period{display:flex;gap:.5rem;align-items:flex-end;flex-wrap:wrap;margin-bottom:.95rem}
.period .f{display:flex;flex-direction:column;flex:1 1 38%;gap:.25rem}
.period label{font-size:.72rem;color:var(--muted)}
.period input{background:var(--bg2);border:1px solid var(--line);border-radius:9px;padding:.6rem;color:var(--text);font-size:16px;width:100%}
.period button{flex:1 1 100%;background:var(--card2);color:var(--text);border:1px solid var(--line);
  border-radius:9px;padding:.65rem;font-size:.92rem;font-weight:600;cursor:pointer}
.period button:active{background:var(--card)}

.payout-hero{background:linear-gradient(180deg,var(--gold-soft),transparent),var(--card);
  border:1px solid var(--gold-line);border-radius:var(--r);padding:1.15rem 1.2rem;margin-bottom:.9rem;text-align:center}
.payout-hero .l{font-size:.76rem;text-transform:uppercase;letter-spacing:.06em;color:var(--gold);margin-bottom:.3rem}
.payout-hero .v{font-size:2.3rem;font-weight:800;color:var(--text);font-variant-numeric:tabular-nums;line-height:1.05}
.payout-hero .v .u{font-size:1.1rem;color:var(--muted);font-weight:600;margin-left:.2rem}
.payout-hero .meta{color:var(--muted);font-size:.82rem;margin-top:.5rem}

.stats{display:grid;grid-template-columns:repeat(2,1fr);gap:.65rem;margin-bottom:.4rem}
.stat{background:var(--card);border:1px solid var(--line);border-radius:var(--r-sm);padding:.85rem .95rem}
.stat .l{color:var(--muted);font-size:.72rem;text-transform:uppercase;letter-spacing:.03em}
.stat .v{font-size:1.28rem;font-weight:700;margin-top:.22rem;font-variant-numeric:tabular-nums}
.stat .v .u{font-size:.82rem;color:var(--muted);font-weight:600;margin-left:.12rem}
.stat.ok .v{color:var(--ok)}

/* limit / distribution editor */
.dist .limit-line{display:flex;align-items:center;justify-content:space-between;gap:.6rem;margin-bottom:.9rem;
  padding-bottom:.9rem;border-bottom:1px solid var(--line)}
.dist .limit-line .big{font-size:1.5rem;font-weight:800;color:var(--gold);font-variant-numeric:tabular-nums}
.dist .limit-line .lbl{font-size:.8rem;color:var(--muted)}
.pct-hint{font-size:.8rem;margin:.1rem 0 .2rem;font-weight:600}
.pct-hint.ok{color:var(--ok)}
.pct-hint.over{color:var(--err)}
.dist .raise{font-size:.82rem;color:var(--muted);margin-top:.85rem;line-height:1.5}
.dist .raise a{color:var(--gold);font-weight:600;text-decoration:none;border-bottom:1px solid var(--gold-line)}

/* checks table */
.tbl-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch;border:1px solid var(--line);border-radius:var(--r-sm)}
table{width:100%;border-collapse:collapse;font-size:.88rem}
thead th{background:var(--bg2);color:var(--muted);font-weight:600;font-size:.72rem;text-transform:uppercase;
  letter-spacing:.03em;padding:.6rem .85rem;text-align:right;white-space:nowrap;position:sticky;top:0}
thead th:first-child{text-align:left}
tbody tr+tr{border-top:1px solid var(--line)}
td{padding:.6rem .85rem;text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums}
td:first-child{text-align:left;color:var(--muted);font-size:.84rem}
.empty{padding:1.3rem;text-align:center;color:var(--faint);font-size:.86rem}
.foot{color:var(--faint);font-size:.78rem;line-height:1.5;margin-top:.7rem;text-align:center}
</style>
</head>
<body>
<div class="wrap">

<?php if ($mode === 'login'): /* ════════ WELCOME ════════ */ ?>

  <div class="brand">VERANDA</div>

  <?php if (!empty($flash['ok'])): ?><div class="flash ok"><?= $esc($flash['ok']) ?></div><?php endif; ?>
  <?php if (!empty($flash['err'])): ?><div class="flash err"><?= $esc($flash['err']) ?></div><?php endif; ?>

  <div class="hero">
    <div class="eyebrow">Программа для инфлюенсеров</div>
    <h1>Приводите гостей —<br>зарабатывайте с <i>Veranda</i></h1>
    <p>Личный промокод, кешбек с каждого визита и честный отчёт в один экран. Без созвонов, без ручных таблиц.</p>
  </div>

  <div class="perks">
    <div class="perk">
      <div class="ico">🎟️</div>
      <div><h3>Личный промокод</h3><p>Гость называет его на кассе и получает скидку. Визит автоматически засчитывается вам.</p></div>
    </div>
    <div class="perk">
      <div class="ico">💸</div>
      <div><h3>Кешбек с каждого чека</h3><p>Вы получаете процент с выручки каждого приведённого гостя. Больше гостей — больше доход.</p></div>
    </div>
    <div class="perk">
      <div class="ico">📊</div>
      <div><h3>Всё прозрачно</h3><p>В кабинете виден каждый чек, накопленный кешбек и выплаты. Никаких «поверьте на слово».</p></div>
    </div>
  </div>

  <div class="limit-box">
    <span class="badge">★ Старт — 5%</span>
    <h2>Как устроен ваш процент</h2>
    <p>Сразу после регистрации у вас <b>5%</b> — вы сами распределяете их между <b>скидкой гостям</b> и <b>своим кешбеком</b>. Этого достаточно, чтобы начать зарабатывать уже сегодня.</p>
    <p>Хотите больше? <a class="contact" href="/" target="_blank" rel="noopener">Свяжитесь с нами</a> любым удобным способом — обсудим персональные условия и поднимем ваш лимит. Контакты есть на сайте и в наших соцсетях.</p>
  </div>

  <!-- existing influencer -->
  <div class="card login-card">
    <p>Уже с нами?</p>
    <a class="gbtn" href="<?= $esc($googleUrl ?? '#') ?>">
      <svg viewBox="0 0 48 48"><path fill="#EA4335" d="M24 9.5c3.5 0 6.6 1.2 9.1 3.6l6.8-6.8C35.9 2.4 30.4 0 24 0 14.6 0 6.5 5.4 2.6 13.2l7.9 6.1C12.4 13.3 17.7 9.5 24 9.5z"/><path fill="#4285F4" d="M46.1 24.5c0-1.6-.1-3.1-.4-4.5H24v9h12.4c-.5 2.9-2.1 5.3-4.6 7l7.1 5.5c4.2-3.9 6.6-9.6 6.6-16z"/><path fill="#FBBC05" d="M10.5 28.3c-.5-1.4-.7-2.8-.7-4.3s.3-2.9.7-4.3l-7.9-6.1C1 16.8 0 20.3 0 24s1 7.2 2.6 10.4l7.9-6.1z"/><path fill="#34A853" d="M24 48c6.5 0 11.9-2.1 15.9-5.8l-7.1-5.5c-2 1.3-4.5 2.1-8.8 2.1-6.3 0-11.6-3.8-13.5-9.3l-7.9 6.1C6.5 42.6 14.6 48 24 48z"/></svg>
      Войти через Google
    </a>
  </div>

  <div class="divider">новый участник</div>

  <!-- registration -->
  <div class="card">
    <div class="sec-h" style="margin:.1rem 0 .9rem"><h2>Зарегистрироваться</h2><span>это бесплатно</span></div>
    <form method="post" action="/bloggers" autocomplete="off">
      <input type="hidden" name="register" value="1">
      <input type="hidden" name="csrf_token" value="<?= $esc($csrf) ?>">
      <input class="hp" type="text" name="website" tabindex="-1" autocomplete="off" aria-hidden="true">

      <div class="fld">
        <label>Ваше имя <span class="req">*</span></label>
        <input type="text" name="name" value="<?= $val('name') ?>" required maxlength="100" placeholder="Анна Иванова">
      </div>
      <div class="fld">
        <label>Email для входа через Google <span class="req">*</span></label>
        <input type="email" name="email" value="<?= $val('email') ?>" required placeholder="your@gmail.com">
        <span class="hint">На него вы будете входить в кабинет.</span>
      </div>
      <div class="fld">
        <label>Промокод <span class="req">*</span></label>
        <input type="text" name="promocode" value="<?= $val('promocode') ?>" required maxlength="50"
               placeholder="ANNA" pattern="[^\s]+" title="Без пробелов">
        <span class="hint">Короткое слово без пробелов — его гости называют на кассе.</span>
      </div>

      <div class="subhead">Соцсети — по желанию</div>
      <div class="grid2">
        <div class="fld"><label>Instagram</label><input type="text" name="ig" value="<?= $val('ig') ?>" placeholder="@handle"></div>
        <div class="fld"><label>TikTok</label><input type="text" name="tt" value="<?= $val('tt') ?>" placeholder="@handle"></div>
        <div class="fld"><label>Telegram</label><input type="text" name="tg" value="<?= $val('tg') ?>" placeholder="@username"></div>
        <div class="fld"><label>YouTube</label><input type="text" name="yt" value="<?= $val('yt') ?>" placeholder="ссылка / @канал"></div>
      </div>

      <button type="submit" class="btn-primary">Создать аккаунт</button>
    </form>
    <p class="fine">Регистрируясь, вы присоединяетесь к программе Veranda. Стартовый лимит — 5%, поднять можно в любой момент по договорённости.</p>
  </div>

<?php else: /* ════════ DASHBOARD ════════ */
  $limit   = (float) ($row['limit_pct'] ?? 15);
  $isStart = $limit <= 5.0;
?>

  <div class="topbar">
    <div class="who">кабинет инфлюенсера<b><?= $esc($name !== '' ? $name : ($row['name'] ?? 'Гость')) ?></b></div>
    <a class="logout" href="/bloggers/logout">Выйти</a>
  </div>

  <?php if (!empty($flash['ok'])): ?><div class="flash ok"><?= $esc($flash['ok']) ?></div><?php endif; ?>
  <?php if (!empty($flash['err'])): ?><div class="flash err"><?= $esc($flash['err']) ?></div><?php endif; ?>

  <?php if ($row === null): ?>
    <div class="card"><p class="empty" style="padding:.3rem 0">Не удалось загрузить данные. Обновите страницу позже.</p></div>
  <?php else: ?>

    <div class="promo-card">
      <div class="l">Ваш промокод</div>
      <div class="code"><?= $esc($row['promocode']) ?></div>
      <div class="sub">Называйте его гостям — скидка применится на кассе</div>
    </div>

    <form class="period" method="get" action="/bloggers">
      <div class="f"><label>Начало</label><input type="date" name="dateFrom" value="<?= $esc($dateFrom) ?>"></div>
      <div class="f"><label>Конец</label><input type="date" name="dateTo" value="<?= $esc($dateTo) ?>"></div>
      <button type="submit">Показать период</button>
    </form>

    <div class="payout-hero">
      <div class="l">К выплате</div>
      <div class="v"><?= $fmtVnd($row['topay']) ?><span class="u">₫</span></div>
      <div class="meta">Накоплено <?= $fmtVnd($row['cashback']) ?> ₫ · выплачено <?= $fmtVnd($row['paid']) ?> ₫</div>
    </div>

    <div class="stats">
      <div class="stat"><div class="l">Чеки</div><div class="v"><?= (int) $row['checks'] ?></div></div>
      <div class="stat"><div class="l">Выручка</div><div class="v"><?= $fmtVnd($row['revenue']) ?><span class="u">₫</span></div></div>
      <div class="stat"><div class="l">Начислено</div><div class="v"><?= $fmtVnd($row['cashback']) ?><span class="u">₫</span></div></div>
      <div class="stat ok"><div class="l">Выплачено</div><div class="v"><?= $fmtVnd($row['paid']) ?><span class="u">₫</span></div></div>
    </div>

    <!-- checks -->
    <div class="sec-h"><h2>Чеки за период</h2><span><?= count($checks) ?></span></div>
    <div class="tbl-wrap">
      <?php if (empty($checks)): ?>
        <div class="empty">Чеков пока нет — поделитесь промокодом 😉</div>
      <?php else: ?>
        <table>
          <thead><tr><th>Дата</th><th>Сумма&nbsp;₫</th><th>Скидка&nbsp;₫</th></tr></thead>
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

    <!-- distribution / settings -->
    <div class="sec-h"><h2>Промокод и распределение</h2></div>
    <div class="card dist">
      <div class="limit-line">
        <div class="lbl">Ваш лимит<br>скидка + кешбек</div>
        <div class="big"><?= $fmtPct($limit) ?>%</div>
      </div>
      <form method="post" action="/bloggers?dateFrom=<?= $esc($dateFrom) ?>&dateTo=<?= $esc($dateTo) ?>">
        <input type="hidden" name="save_self" value="1">
        <input type="hidden" name="csrf_token" value="<?= $esc($csrf) ?>">
        <div class="fld">
          <label>Промокод</label>
          <input type="text" name="promocode" value="<?= $esc($row['promocode']) ?>" required maxlength="50" pattern="[^\s]+" title="Без пробелов">
        </div>
        <div class="grid2">
          <div class="fld">
            <label>Скидка гостям, %</label>
            <input id="inp-disc" type="number" name="discount_pct" value="<?= $esc($fmtPct($row['discount_pct'] ?? 0)) ?>" min="0" max="<?= $esc($fmtPct($limit)) ?>" step="0.5" required>
          </div>
          <div class="fld">
            <label>Ваш кешбек, %</label>
            <input id="inp-cash" type="number" name="cashback_pct" value="<?= $esc($fmtPct($row['cashback_pct'] ?? 0)) ?>" min="0" max="<?= $esc($fmtPct($limit)) ?>" step="0.5" required>
          </div>
        </div>
        <div id="pct-hint" class="pct-hint ok" data-limit="<?= $esc($fmtPct($limit)) ?>"></div>
        <button type="submit" class="btn-primary">Сохранить</button>
      </form>
      <p class="raise">
        <?php if ($isStart): ?>
          Хотите процент выше 5%? <a href="/" target="_blank" rel="noopener">Свяжитесь с нами</a> — обсудим условия и поднимем лимит. Контакты на сайте.
        <?php else: ?>
          Нужен лимит выше? <a href="/" target="_blank" rel="noopener">Напишите нам</a> — всегда на связи.
        <?php endif; ?>
      </p>
    </div>

    <p class="foot">Кешбек считается от выручки после скидки. Выплаты — по договорённости.</p>
  <?php endif; ?>

<?php endif; ?>
</div>

<script>
(function () {
  var disc = document.getElementById('inp-disc');
  var cash = document.getElementById('inp-cash');
  var hint = document.getElementById('pct-hint');
  if (!disc || !cash || !hint) return;
  var limit = parseFloat(hint.getAttribute('data-limit')) || 15;
  function upd() {
    var s = (parseFloat(disc.value) || 0) + (parseFloat(cash.value) || 0);
    s = Math.round(s * 100) / 100;
    hint.textContent = 'Итого ' + s + '% из ' + limit + '% доступных';
    hint.className = 'pct-hint ' + (s > limit ? 'over' : 'ok');
  }
  disc.addEventListener('input', upd);
  cash.addEventListener('input', upd);
  upd();
})();
</script>
</body>
</html>
