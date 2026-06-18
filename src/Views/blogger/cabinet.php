<?php
/**
 * Blogger cabinet — standalone mobile-first page (no admin layout).
 *
 * @var string      $mode     'login' | 'report'
 * @var string      $googleUrl (login)
 * @var string      $name     (report) blogger display name
 * @var array|null  $row      (report) the blogger's report row
 * @var string      $dateFrom (report)
 * @var string      $dateTo   (report)
 * @var string      $err      (report) optional error message
 */
$mode     = $mode ?? 'login';
$esc      = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$fmtVnd   = static fn ($minor): string => number_format((int) round(((float) $minor) / 100), 0, '.', ' ');
$fmtPct   = static fn ($p): string => rtrim(rtrim(number_format((float) $p, 2, '.', ''), '0'), '.');
$row      = $row ?? null;
$dateFrom = $dateFrom ?? date('Y-m-01');
$dateTo   = $dateTo ?? date('Y-m-d');
?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Veranda — кабинет блогера</title>
<link rel="icon" type="image/svg+xml" href="/links/favicon.svg">
<style>
:root{--bg:#0f1117;--card:#1a1d27;--border:#2a2d3a;--text:#e2e8f0;--muted:#8a93a6;--accent:#B88746;--ok:#10b981;--accent2:rgba(184,135,70,.14)}
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
.stats{display:grid;grid-template-columns:1fr 1fr;gap:.7rem}
.stat{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:.9rem 1rem}
.stat .l{color:var(--muted);font-size:.74rem;text-transform:uppercase;letter-spacing:.04em}
.stat .v{font-size:1.45rem;font-weight:800;margin-top:.25rem;font-variant-numeric:tabular-nums}
.stat.full{grid-column:1 / -1}
.stat.topay{border-color:rgba(184,135,70,.5);background:var(--accent2)}
.stat.topay .v{color:var(--accent)}
.stat.paid .v{color:var(--ok)}
.unit{font-size:.9rem;color:var(--muted);font-weight:600;margin-left:.15rem}
.note{color:var(--muted);font-size:.8rem;line-height:1.5;margin-top:1rem;text-align:center}
.err{background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.3);color:#fca5a5;padding:.6rem .8rem;border-radius:10px;margin-bottom:1rem;font-size:.85rem}
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
      <a class="logout" href="/blogger/logout">Выйти</a>
    </div>

    <?php if (!empty($err)): ?><div class="err"><?= $esc($err) ?></div><?php endif; ?>

    <form class="period" method="get" action="/blogger">
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
      <p class="note">Скидка гостям по промокоду: <b><?= $fmtPct($row['discount_pct']) ?>%</b>. Кешбек = выручка после скидки × <?= $fmtPct($row['cashback_pct']) ?>%.</p>
    <?php endif; ?>
  </div>
<?php endif; ?>

</body>
</html>
