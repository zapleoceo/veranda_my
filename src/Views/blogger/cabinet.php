<?php
/**
 * Blogger cabinet — standalone mobile-first page (no admin layout).
 *
 * @var string      $mode     'login' | 'report'
 * @var string      $googleUrl (login)
 * @var string      $name     (report) blogger display name
 * @var array|null  $row      (report) the blogger's report row
 * @var list<array> $checks   (report) individual checks from Poster
 * @var string      $dateFrom (report)
 * @var string      $dateTo   (report)
 * @var array       $flash    ['ok'=>string,'err'=>string]
 */
$mode     = $mode ?? 'login';
$esc      = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$fmtVnd   = static fn ($minor): string => number_format((int) round(((float) $minor) / 100), 0, '.', ' ');
$fmtPct   = static fn ($p): string => rtrim(rtrim(number_format((float) $p, 2, '.', ''), '0'), '.');
$row      = $row ?? null;
$checks   = $checks ?? [];
$dateFrom = $dateFrom ?? date('Y-m-01');
$dateTo   = $dateTo ?? date('Y-m-d');
$flash    = $flash ?? ['ok' => '', 'err' => ''];
?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Veranda — кабинет блогера</title>
<link rel="icon" type="image/svg+xml" href="/links/favicon.svg">
<style>
:root{--bg:#0f1117;--card:#1a1d27;--border:#2a2d3a;--text:#e2e8f0;--muted:#8a93a6;--accent:#B88746;--ok:#10b981;--err:#ef4444;--accent2:rgba(184,135,70,.14)}
*{box-sizing:border-box;margin:0;padding:0}
html,body{background:var(--bg);color:var(--text);font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;-webkit-text-size-adjust:100%}
body{min-height:100vh;padding:env(safe-area-inset-top) 0 env(safe-area-inset-bottom)}
.wrap{max-width:520px;margin:0 auto;padding:1.1rem}
.brand{font-family:'Cinzel',Georgia,serif;font-weight:700;letter-spacing:.18em;color:var(--accent);font-size:1.05rem}
.center{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:1.1rem}
.card{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:1.25rem}
.login-card{max-width:380px;width:100%;text-align:center}
.login-card h1{font-size:1.15rem;margin:.6rem 0 1.4rem;font-weight:600}
.gbtn{display:flex;align-items:center;justify-content:center;gap:.6rem;width:100%;padding:.9rem 1rem;
  background:#fff;color:#202124;border-radius:10px;text-decoration:none;font-weight:600;font-size:1rem;border:1px solid #dadce0}
.gbtn:active{opacity:.85}
.gbtn svg{width:20px;height:20px;flex:none}
.hdr{display:flex;align-items:center;justify-content:space-between;gap:.75rem;margin-bottom:1rem}
.hdr .who{min-width:0}
.hdr .promo{font-size:1.2rem;font-weight:700;color:var(--accent);word-break:break-word}
.hdr .nm{color:var(--muted);font-size:.82rem;margin-top:.1rem}
.logout{color:var(--muted);font-size:.8rem;text-decoration:none;border:1px solid var(--border);border-radius:8px;padding:.4rem .7rem;white-space:nowrap}
.period{display:flex;gap:.5rem;align-items:flex-end;flex-wrap:wrap;margin-bottom:1rem}
.period .f{display:flex;flex-direction:column;flex:1 1 38%}
.period label{font-size:.72rem;color:var(--muted);margin-bottom:.25rem}
.period input{background:#0d0f1a;border:1px solid var(--border);border-radius:9px;padding:.6rem;color:var(--text);font-size:1rem;width:100%}
.period button{flex:1 1 100%;background:var(--accent);color:#fff;border:none;border-radius:9px;padding:.7rem;font-size:1rem;font-weight:600;cursor:pointer}
.stats{display:grid;grid-template-columns:1fr 1fr;gap:.7rem;margin-bottom:1rem}
.stat{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:.9rem 1rem}
.stat .l{color:var(--muted);font-size:.74rem;text-transform:uppercase;letter-spacing:.04em}
.stat .v{font-size:1.45rem;font-weight:800;margin-top:.25rem;font-variant-numeric:tabular-nums}
.stat.full{grid-column:1 / -1}
.stat.topay{border-color:rgba(184,135,70,.5);background:var(--accent2)}
.stat.topay .v{color:var(--accent)}
.stat.paid .v{color:var(--ok)}
.unit{font-size:.9rem;color:var(--muted);font-weight:600;margin-left:.15rem}
.note{color:var(--muted);font-size:.8rem;line-height:1.5;margin-top:.75rem;text-align:center}
.flash-ok{background:rgba(16,185,129,.12);border:1px solid rgba(16,185,129,.3);color:#6ee7b7;padding:.6rem .8rem;border-radius:10px;margin-bottom:.9rem;font-size:.85rem}
.flash-err{background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.3);color:#fca5a5;padding:.6rem .8rem;border-radius:10px;margin-bottom:.9rem;font-size:.85rem}

/* Checks table */
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

/* Edit form */
details.edit-section{margin-top:1.25rem}
details.edit-section summary{display:flex;align-items:center;gap:.5rem;cursor:pointer;color:var(--muted);font-size:.82rem;padding:.5rem 0;list-style:none;user-select:none}
details.edit-section summary::-webkit-details-marker{display:none}
details.edit-section summary::before{content:'▶';font-size:.65rem;transition:transform .2s}
details.edit-section[open] summary::before{transform:rotate(90deg)}
.edit-card{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:1rem;margin-top:.5rem;display:flex;flex-direction:column;gap:.85rem}
.fld{display:flex;flex-direction:column;gap:.3rem}
.fld label{font-size:.74rem;color:var(--muted)}
.fld input[type=text],.fld input[type=number]{background:#0d0f1a;border:1px solid var(--border);border-radius:9px;padding:.6rem .75rem;color:var(--text);font-size:1rem;width:100%}
.fld input:focus{outline:none;border-color:var(--accent)}
.pct-row{display:grid;grid-template-columns:1fr 1fr;gap:.6rem}
.pct-hint{font-size:.76rem;margin-top:.1rem;transition:color .15s}
.pct-hint.over{color:var(--err)}
.pct-hint.ok{color:var(--ok)}
.save-btn{background:var(--accent);color:#fff;border:none;border-radius:9px;padding:.75rem;font-size:.95rem;font-weight:700;cursor:pointer;width:100%}
.save-btn:active{opacity:.85}
</style>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@700&display=swap" rel="stylesheet">
</head>
<body>

<?php if ($mode === 'login'): ?>
  <div class="center">
    <div class="card login-card">
      <div class="brand">VERANDA</div>
      <h1>Кабинет блогера</h1>
      <a class="gbtn" href="<?= $esc($googleUrl ?? '#') ?>">
        <svg viewBox="0 0 48 48"><path fill="#EA4335" d="M24 9.5c3.5 0 6.6 1.2 9.1 3.6l6.8-6.8C35.9 2.4 30.4 0 24 0 14.6 0 6.5 5.4 2.6 13.2l7.9 6.1C12.4 13.3 17.7 9.5 24 9.5z"/><path fill="#4285F4" d="M46.1 24.5c0-1.6-.1-3.1-.4-4.5H24v9h12.4c-.5 2.9-2.1 5.3-4.6 7l7.1 5.5c4.2-3.9 6.6-9.6 6.6-16z"/><path fill="#FBBC05" d="M10.5 28.3c-.5-1.4-.7-2.8-.7-4.3s.3-2.9.7-4.3l-7.9-6.1C1 16.8 0 20.3 0 24s1 7.2 2.6 10.4l7.9-6.1z"/><path fill="#34A853" d="M24 48c6.5 0 11.9-2.1 15.9-5.8l-7.1-5.5c-2 1.3-4.5 2.1-8.8 2.1-6.3 0-11.6-3.8-13.5-9.3l-7.9 6.1C6.5 42.6 14.6 48 24 48z"/></svg>
        Войти через Google
      </a>
      <p class="note">Доступ только для блогеров Veranda по приглашению. Вы увидите только свой отчёт.</p>
    </div>
  </div>
<?php else: ?>
  <div class="wrap">
    <div class="hdr">
      <div class="who">
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
      <div class="card"><p class="note" style="margin:0">За выбранный период данных нет.</p></div>
    <?php else: ?>
      <div class="stats">
        <div class="stat"><div class="l">Чеки</div><div class="v"><?= (int) $row['checks'] ?></div></div>
        <div class="stat"><div class="l">Выручка</div><div class="v"><?= $fmtVnd($row['revenue']) ?><span class="unit">₫</span></div></div>
        <div class="stat full"><div class="l">Начислено кешбека (<?= $fmtPct($row['cashback_pct']) ?>%)</div><div class="v"><?= $fmtVnd($row['cashback']) ?><span class="unit">₫</span></div></div>
        <div class="stat paid"><div class="l">Выплачено</div><div class="v"><?= $fmtVnd($row['paid']) ?><span class="unit">₫</span></div></div>
        <div class="stat topay"><div class="l">К выплате</div><div class="v"><?= $fmtVnd($row['topay']) ?><span class="unit">₫</span></div></div>
      </div>
      <p class="note">Скидка по промокоду: <b><?= $fmtPct($row['discount_pct']) ?>%</b> · Кешбек <?= $fmtPct($row['cashback_pct']) ?>% от выручки после скидки</p>

      <!-- Checks table -->
      <div class="section-title">Чеки за период</div>
      <div class="tbl-wrap">
        <?php if (empty($checks)): ?>
          <div class="no-checks">Чеков не найдено</div>
        <?php else: ?>
          <table>
            <thead>
              <tr>
                <th>Дата</th>
                <th>Сумма, ₫</th>
                <th>Скидка, ₫</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($checks as $ch):
                $dt  = (string) ($ch['date_close'] ?? '');
                $sum = (int) round((float) ($ch['payed_sum'] ?? 0) / 100);
                $dis = (int) round((float) ($ch['discount_sum'] ?? 0) / 100);
                $dtFmt = $dt !== '' ? date('d.m.y H:i', strtotime($dt)) : '—';
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

      <!-- Self-edit form -->
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
              <input id="inp-disc" type="number" name="discount_pct" value="<?= $esc($fmtPct($row['discount_pct'] ?? 0)) ?>" min="0" max="15" step="0.01" required>
            </div>
            <div class="fld">
              <label>Кешбек мне, %</label>
              <input id="inp-cash" type="number" name="cashback_pct" value="<?= $esc($fmtPct($row['cashback_pct'] ?? 0)) ?>" min="0" max="15" step="0.01" required>
            </div>
          </div>
          <div id="pct-hint" class="pct-hint"></div>

          <button type="submit" class="save-btn">Сохранить</button>
        </form>
      </details>
    <?php endif; ?>
  </div>
<?php endif; ?>

<script>
(function () {
  var disc = document.getElementById('inp-disc');
  var cash = document.getElementById('inp-cash');
  var hint = document.getElementById('pct-hint');
  if (!disc || !cash || !hint) return;
  function update() {
    var d = parseFloat(disc.value) || 0;
    var c = parseFloat(cash.value) || 0;
    var s = Math.round((d + c) * 100) / 100;
    if (s > 15) {
      hint.textContent = 'Итого ' + s.toFixed(2) + '% — превышает лимит 15%';
      hint.className = 'pct-hint over';
    } else {
      hint.textContent = 'Итого ' + s.toFixed(2) + '% из 15% максимум';
      hint.className = 'pct-hint ok';
    }
  }
  disc.addEventListener('input', update);
  cash.addEventListener('input', update);
  update();
})();
</script>
</body>
</html>
