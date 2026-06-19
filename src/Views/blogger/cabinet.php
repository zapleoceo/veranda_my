<?php
/**
 * Blogger cabinet — standalone mobile-first page.
 *
 * @var string      $mode      'login' | 'report'
 * @var string      $googleUrl  (login) OAuth URL
 * @var array       $flash      ['ok'=>string,'err'=>string]
 * @var array       $regData    (login) form pre-fill on error
 * @var string      $name       (report) blogger display name
 * @var array|null  $row        (report) report row
 * @var list<array> $checks     (report) individual checks
 * @var string      $dateFrom   (report)
 * @var string      $dateTo     (report)
 */
$mode     = $mode ?? 'login';
$row      = $row ?? null;
$checks   = $checks ?? [];
$regData  = $regData ?? [];
$dateFrom = $dateFrom ?? date('Y-m-01');
$dateTo   = $dateTo ?? date('Y-m-d');
$flash    = $flash ?? ['ok' => '', 'err' => ''];
$esc      = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
// Arrow fn auto-captures $esc and $regData by value — both defined above.
$val      = static fn (string $k): string => $esc($regData[$k] ?? '');
$fmtVnd   = static fn ($n): string => number_format((int) round((float) $n / 100), 0, '.', ' ');
$fmtPct   = static fn ($p): string => rtrim(rtrim(number_format((float) $p, 2, '.', ''), '0'), '.');
?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Veranda — кабинет блогера</title>
<link rel="icon" type="image/svg+xml" href="/links/favicon.svg">
<style>
:root{--bg:#0f1117;--card:#1a1d27;--card2:#14172030;--border:#2a2d3a;--text:#e2e8f0;--muted:#8a93a6;--accent:#B88746;--ok:#10b981;--err:#ef4444;--accent2:rgba(184,135,70,.14)}
*{box-sizing:border-box;margin:0;padding:0}
html,body{background:var(--bg);color:var(--text);font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;-webkit-text-size-adjust:100%}
body{min-height:100vh;padding:env(safe-area-inset-top) 0 calc(env(safe-area-inset-bottom) + 1.5rem)}
.wrap{max-width:520px;margin:0 auto;padding:1.1rem}

/* Flash */
.flash-ok{background:rgba(16,185,129,.12);border:1px solid rgba(16,185,129,.3);color:#6ee7b7;padding:.7rem .9rem;border-radius:12px;margin-bottom:1rem;font-size:.88rem;line-height:1.5}
.flash-err{background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.3);color:#fca5a5;padding:.7rem .9rem;border-radius:12px;margin-bottom:1rem;font-size:.88rem;line-height:1.5}

/* ── Welcome / login page ─────────────────── */
.brand{font-family:'Cinzel',Georgia,serif;font-weight:700;letter-spacing:.18em;color:var(--accent);font-size:1rem;text-align:center;margin-bottom:2rem;margin-top:.5rem}
.hero{text-align:center;margin-bottom:2rem}
.hero h1{font-size:1.5rem;font-weight:800;line-height:1.25;margin-bottom:.6rem}
.hero h1 span{color:var(--accent)}
.hero p{color:var(--muted);font-size:.9rem;line-height:1.6;max-width:360px;margin:0 auto}
.steps{display:flex;flex-direction:column;gap:.65rem;margin-bottom:2rem}
.step{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:.85rem 1rem;display:flex;gap:.8rem;align-items:flex-start}
.step-icon{font-size:1.3rem;flex:none;margin-top:.05rem}
.step-body .t{font-weight:700;font-size:.92rem;margin-bottom:.2rem}
.step-body .d{color:var(--muted);font-size:.8rem;line-height:1.45}
.login-section{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:1.2rem;margin-bottom:1rem;text-align:center}
.login-section h2{font-size:.95rem;font-weight:700;margin-bottom:.8rem}
.gbtn{display:flex;align-items:center;justify-content:center;gap:.6rem;width:100%;padding:.9rem 1rem;
  background:#fff;color:#202124;border-radius:10px;text-decoration:none;font-weight:600;font-size:.95rem;border:1px solid #dadce0}
.gbtn:active{opacity:.85}
.gbtn svg{width:20px;height:20px;flex:none}

/* Registration */
details.reg{border:1px solid var(--border);border-radius:16px;overflow:hidden}
details.reg summary{background:var(--card);padding:1rem 1.2rem;cursor:pointer;font-weight:700;font-size:.95rem;display:flex;align-items:center;gap:.5rem;user-select:none;list-style:none}
details.reg summary::-webkit-details-marker{display:none}
details.reg summary::before{content:'▶';font-size:.65rem;color:var(--muted);transition:transform .2s;flex:none}
details.reg[open] summary::before{transform:rotate(90deg)}
details.reg summary .badge{margin-left:auto;font-size:.72rem;font-weight:600;background:var(--accent2);color:var(--accent);border-radius:20px;padding:.2rem .6rem;border:1px solid rgba(184,135,70,.3)}
.reg-form{background:var(--card);padding:1.1rem 1.2rem;display:flex;flex-direction:column;gap:.8rem}
.reg-form h3{font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--muted);margin-top:.4rem}
.fld{display:flex;flex-direction:column;gap:.3rem}
.fld label{font-size:.75rem;color:var(--muted)}
.fld input{background:#0d0f1a;border:1px solid var(--border);border-radius:9px;padding:.65rem .8rem;color:var(--text);font-size:1rem;width:100%}
.fld input:focus{outline:none;border-color:var(--accent)}
.fld .hint{font-size:.72rem;color:var(--muted);line-height:1.4}
.two-col{display:grid;grid-template-columns:1fr 1fr;gap:.6rem}
.reg-btn{background:var(--accent);color:#fff;border:none;border-radius:10px;padding:.8rem;font-size:1rem;font-weight:700;cursor:pointer;width:100%}
.reg-btn:active{opacity:.85}
.or-sep{display:flex;align-items:center;gap:.75rem;margin:.25rem 0;color:var(--muted);font-size:.8rem}
.or-sep::before,.or-sep::after{content:'';flex:1;height:1px;background:var(--border)}

/* ── Report page ─────────────────────────── */
.hdr{display:flex;align-items:center;justify-content:space-between;gap:.75rem;margin-bottom:1rem}
.hdr .promo{font-size:1.2rem;font-weight:700;color:var(--accent);word-break:break-word}
.hdr .nm{color:var(--muted);font-size:.82rem;margin-top:.1rem}
.logout{color:var(--muted);font-size:.8rem;text-decoration:none;border:1px solid var(--border);border-radius:8px;padding:.4rem .7rem;white-space:nowrap}
.period{display:flex;gap:.5rem;align-items:flex-end;flex-wrap:wrap;margin-bottom:1rem}
.period .f{display:flex;flex-direction:column;flex:1 1 38%}
.period label{font-size:.72rem;color:var(--muted);margin-bottom:.25rem}
.period input{background:#0d0f1a;border:1px solid var(--border);border-radius:9px;padding:.6rem;color:var(--text);font-size:1rem;width:100%}
.period button{flex:1 1 100%;background:var(--accent);color:#fff;border:none;border-radius:9px;padding:.7rem;font-size:1rem;font-weight:600;cursor:pointer}
.stats{display:grid;grid-template-columns:1fr 1fr;gap:.7rem;margin-bottom:.75rem}
.stat{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:.9rem 1rem}
.stat .l{color:var(--muted);font-size:.74rem;text-transform:uppercase;letter-spacing:.04em}
.stat .v{font-size:1.45rem;font-weight:800;margin-top:.25rem;font-variant-numeric:tabular-nums}
.stat.full{grid-column:1 / -1}
.stat.topay{border-color:rgba(184,135,70,.5);background:var(--accent2)}
.stat.topay .v{color:var(--accent)}
.stat.paid .v{color:var(--ok)}
.unit{font-size:.9rem;color:var(--muted);font-weight:600;margin-left:.15rem}
.note{color:var(--muted);font-size:.8rem;line-height:1.5;margin-top:.5rem;text-align:center}
.section-title{font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--muted);margin:1.2rem 0 .5rem}
.tbl-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch;border-radius:14px;border:1px solid var(--border)}
table{width:100%;border-collapse:collapse;font-size:.88rem}
thead th{background:#141620;color:var(--muted);font-weight:600;font-size:.75rem;text-transform:uppercase;letter-spacing:.04em;padding:.55rem .8rem;text-align:right;white-space:nowrap}
thead th:first-child{text-align:left}
tbody tr{border-top:1px solid var(--border)}
tbody tr:hover{background:rgba(255,255,255,.03)}
td{padding:.55rem .8rem;color:var(--text);text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums}
td:first-child{text-align:left;color:var(--muted);font-size:.82rem}
.no-checks{padding:1rem;text-align:center;color:var(--muted);font-size:.85rem}
details.edit-section{margin-top:1.25rem}
details.edit-section summary{display:flex;align-items:center;gap:.5rem;cursor:pointer;color:var(--muted);font-size:.82rem;padding:.5rem 0;list-style:none;user-select:none}
details.edit-section summary::-webkit-details-marker{display:none}
details.edit-section summary::before{content:'▶';font-size:.65rem;transition:transform .2s}
details.edit-section[open] summary::before{transform:rotate(90deg)}
.edit-card{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:1rem;margin-top:.5rem;display:flex;flex-direction:column;gap:.85rem}
.fld input[type=text],.fld input[type=number]{background:#0d0f1a;border:1px solid var(--border);border-radius:9px;padding:.6rem .75rem;color:var(--text);font-size:1rem;width:100%}
.fld input:focus{outline:none;border-color:var(--accent)}
.pct-row{display:grid;grid-template-columns:1fr 1fr;gap:.6rem}
.pct-hint{font-size:.76rem;margin-top:.1rem;transition:color .15s}
.pct-hint.over{color:var(--err)}
.pct-hint.ok{color:var(--ok)}
.save-btn{background:var(--accent);color:#fff;border:none;border-radius:9px;padding:.75rem;font-size:.95rem;font-weight:700;cursor:pointer;width:100%}
</style>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@700&display=swap" rel="stylesheet">
</head>
<body>
<div class="wrap">

<?php if ($mode === 'login'): ?>

  <div class="brand">VERANDA</div>

  <?php if (!empty($flash['ok'])): ?><div class="flash-ok"><?= $esc($flash['ok']) ?></div><?php endif; ?>
  <?php if (!empty($flash['err'])): ?><div class="flash-err"><?= $esc($flash['err']) ?></div><?php endif; ?>

  <div class="hero">
    <h1>Инфлюенсерская<br>программа <span>Veranda</span></h1>
    <p>Делитесь промокодом с подписчиками — они получают скидку, вы зарабатываете кешбек с каждого их визита.</p>
  </div>

  <div class="steps">
    <div class="step">
      <div class="step-icon">🎯</div>
      <div class="step-body">
        <div class="t">Ваш уникальный промокод</div>
        <div class="d">Гость называет ваше имя на кассе — скидка применяется автоматически, визит засчитывается вам.</div>
      </div>
    </div>
    <div class="step">
      <div class="step-icon">💰</div>
      <div class="step-body">
        <div class="t">Кешбек с каждого чека</div>
        <div class="d">Процент от выручки каждого гостя переводится вам. Чем больше гостей — тем больше доход.</div>
      </div>
    </div>
    <div class="step">
      <div class="step-icon">📊</div>
      <div class="step-body">
        <div class="t">Прозрачный отчёт</div>
        <div class="d">В личном кабинете видно каждый чек, начисленный кешбек и историю выплат.</div>
      </div>
    </div>
  </div>

  <!-- Existing blogger login -->
  <div class="login-section">
    <h2>Уже участвуете в программе?</h2>
    <a class="gbtn" href="<?= $esc($googleUrl ?? '#') ?>">
      <svg viewBox="0 0 48 48"><path fill="#EA4335" d="M24 9.5c3.5 0 6.6 1.2 9.1 3.6l6.8-6.8C35.9 2.4 30.4 0 24 0 14.6 0 6.5 5.4 2.6 13.2l7.9 6.1C12.4 13.3 17.7 9.5 24 9.5z"/><path fill="#4285F4" d="M46.1 24.5c0-1.6-.1-3.1-.4-4.5H24v9h12.4c-.5 2.9-2.1 5.3-4.6 7l7.1 5.5c4.2-3.9 6.6-9.6 6.6-16z"/><path fill="#FBBC05" d="M10.5 28.3c-.5-1.4-.7-2.8-.7-4.3s.3-2.9.7-4.3l-7.9-6.1C1 16.8 0 20.3 0 24s1 7.2 2.6 10.4l7.9-6.1z"/><path fill="#34A853" d="M24 48c6.5 0 11.9-2.1 15.9-5.8l-7.1-5.5c-2 1.3-4.5 2.1-8.8 2.1-6.3 0-11.6-3.8-13.5-9.3l-7.9 6.1C6.5 42.6 14.6 48 24 48z"/></svg>
      Войти через Google
    </a>
  </div>

  <div class="or-sep">или</div>

  <!-- Self-registration -->
  <details class="reg" <?= !empty($flash['err']) ? 'open' : '' ?>>
    <summary>
      Хочу стать инфлюенсером Veranda
      <span class="badge">Заявка</span>
    </summary>
    <form class="reg-form" method="post" action="/bloggers">
      <input type="hidden" name="register" value="1">

      <div class="fld">
        <label>Ваше имя *</label>
        <input type="text" name="name" value="<?= $val('name') ?>" required maxlength="100" placeholder="Анна Иванова">
      </div>

      <div class="fld">
        <label>Email для входа (Google) *</label>
        <input type="email" name="email" value="<?= $val('email') ?>" required placeholder="your@gmail.com">
        <span class="hint">Этот email будет использован для входа через Google</span>
      </div>

      <div class="fld">
        <label>Промокод *</label>
        <input type="text" name="promocode" value="<?= $val('promocode') ?>" required maxlength="50"
               placeholder="ANNA2025" pattern="[^\s]+" title="Без пробелов">
        <span class="hint">Уникальное слово без пробелов — гости называют его на кассе</span>
      </div>

      <h3>Соцсети (необязательно)</h3>

      <div class="two-col">
        <div class="fld">
          <label>Instagram</label>
          <input type="text" name="ig" value="<?= $val('ig') ?>" placeholder="@handle">
        </div>
        <div class="fld">
          <label>TikTok</label>
          <input type="text" name="tt" value="<?= $val('tt') ?>" placeholder="@handle">
        </div>
        <div class="fld">
          <label>Telegram</label>
          <input type="text" name="tg" value="<?= $val('tg') ?>" placeholder="@username">
        </div>
        <div class="fld">
          <label>YouTube</label>
          <input type="text" name="yt" value="<?= $val('yt') ?>" placeholder="ссылка или @канал">
        </div>
      </div>

      <button type="submit" class="reg-btn">Отправить заявку</button>
    </form>
  </details>

<?php else: /* ── Authenticated report ── */ ?>

  <div class="hdr">
    <div>
      <div class="promo"><?= $row ? $esc($row['promocode']) : 'Блогер' ?></div>
      <div class="nm"><?= $esc($name ?? ($row['name'] ?? '')) ?></div>
    </div>
    <a class="logout" href="/bloggers/logout">Выйти</a>
  </div>

  <?php if (!empty($flash['ok'])): ?><div class="flash-ok"><?= $esc($flash['ok']) ?></div><?php endif; ?>
  <?php if (!empty($flash['err'])): ?><div class="flash-err"><?= $esc($flash['err']) ?></div><?php endif; ?>

  <form class="period" method="get" action="/bloggers">
    <div class="f"><label>Начало</label><input type="date" name="dateFrom" value="<?= $esc($dateFrom) ?>"></div>
    <div class="f"><label>Конец</label><input type="date" name="dateTo" value="<?= $esc($dateTo) ?>"></div>
    <button type="submit">Показать</button>
  </form>

  <?php if ($row === null): ?>
    <p class="note">За выбранный период данных нет.</p>
  <?php else: ?>
    <div class="stats">
      <div class="stat"><div class="l">Чеки</div><div class="v"><?= (int) $row['checks'] ?></div></div>
      <div class="stat"><div class="l">Выручка</div><div class="v"><?= $fmtVnd($row['revenue']) ?><span class="unit">₫</span></div></div>
      <div class="stat full"><div class="l">Начислено кешбека (<?= $fmtPct($row['cashback_pct']) ?>%)</div><div class="v"><?= $fmtVnd($row['cashback']) ?><span class="unit">₫</span></div></div>
      <div class="stat paid"><div class="l">Выплачено</div><div class="v"><?= $fmtVnd($row['paid']) ?><span class="unit">₫</span></div></div>
      <div class="stat topay"><div class="l">К выплате</div><div class="v"><?= $fmtVnd($row['topay']) ?><span class="unit">₫</span></div></div>
    </div>
    <p class="note">Скидка по промокоду: <b><?= $fmtPct($row['discount_pct']) ?>%</b> · Кешбек <?= $fmtPct($row['cashback_pct']) ?>% от выручки после скидки</p>

    <div class="section-title">Чеки за период</div>
    <div class="tbl-wrap">
      <?php if (empty($checks)): ?>
        <div class="no-checks">Чеков не найдено</div>
      <?php else: ?>
        <table>
          <thead><tr><th>Дата</th><th>Сумма, ₫</th><th>Скидка, ₫</th></tr></thead>
          <tbody>
            <?php foreach ($checks as $ch):
              $dt     = (string) ($ch['date_close'] ?? '');
              $sum    = (int) round((float) ($ch['payed_sum']    ?? 0) / 100);
              $dis    = (int) round((float) ($ch['discount_sum'] ?? 0) / 100);
              $dtFmt  = $dt !== '' ? date('d.m.y H:i', strtotime($dt)) : '—';
            ?>
            <tr>
              <td><?= $esc($dtFmt) ?></td>
              <td><?= number_format($sum, 0, '.', ' ') ?></td>
              <td><?= $dis > 0 ? number_format($dis, 0, '.', ' ') : '—' ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

    <?php $limit = (float) ($row['limit_pct'] ?? 15); ?>
    <details class="edit-section">
      <summary>Редактировать параметры</summary>
      <form class="edit-card" method="post" action="/bloggers?dateFrom=<?= $esc($dateFrom) ?>&dateTo=<?= $esc($dateTo) ?>">
        <input type="hidden" name="save_self" value="1">
        <div class="fld">
          <label>Промокод (имя для кассира)</label>
          <input type="text" name="promocode" value="<?= $esc($row['promocode'] ?? '') ?>" required maxlength="50" placeholder="MYPROMOKOD">
        </div>
        <div class="pct-row">
          <div class="fld">
            <label>Скидка гостям, %</label>
            <input id="inp-disc" type="number" name="discount_pct" value="<?= $esc($fmtPct($row['discount_pct'] ?? 0)) ?>" min="0" max="<?= $esc($fmtPct($limit)) ?>" step="0.01" required>
          </div>
          <div class="fld">
            <label>Кешбек мне, %</label>
            <input id="inp-cash" type="number" name="cashback_pct" value="<?= $esc($fmtPct($row['cashback_pct'] ?? 0)) ?>" min="0" max="<?= $esc($fmtPct($limit)) ?>" step="0.01" required>
          </div>
        </div>
        <div id="pct-hint" class="pct-hint" data-limit="<?= $esc($fmtPct($limit)) ?>"></div>
        <button type="submit" class="save-btn">Сохранить</button>
      </form>
    </details>
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
  function update() {
    var s = Math.round((parseFloat(disc.value) + parseFloat(cash.value)) * 100) / 100;
    if (isNaN(s)) s = 0;
    hint.textContent = 'Итого ' + s.toFixed(2) + '% из ' + limit + '% максимум';
    hint.className   = 'pct-hint ' + (s > limit ? 'over' : 'ok');
  }
  disc.addEventListener('input', update);
  cash.addEventListener('input', update);
  update();
})();
</script>
</body>
</html>
