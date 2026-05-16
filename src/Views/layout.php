<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($pageTitle ?? 'Veranda Admin') ?></title>
<style>
:root {
    --bg:       #0f1117;
    --surface:  #1a1d27;
    --border:   #2a2d3a;
    --text:     #e2e8f0;
    --muted:    #6b7280;
    --accent:   #6c8ef5;
    --danger:   #ef4444;
    --ok:       #10b981;
    --card:     var(--surface);
    --accent2:  rgba(108,142,245,.15);
    --sb-w:     220px;
}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:var(--bg);color:var(--text);min-height:100vh}

/* ── Layout ─────────────────────────────────────────── */
.layout{display:flex;min-height:100vh}
.page-wrap{flex:1;min-width:0;display:flex;flex-direction:column}

/* ── Sidebar ─────────────────────────────────────────── */
.sidebar{
    width:var(--sb-w);min-width:var(--sb-w);
    background:#13151f;
    border-right:1px solid var(--border);
    display:flex;flex-direction:column;
    position:sticky;top:0;height:100vh;
    overflow-y:auto;
    scrollbar-width:thin;scrollbar-color:var(--border) transparent;
    z-index:100;
}
.sb-brand{
    padding:.9rem 1.25rem;font-weight:700;font-size:1.05rem;
    color:var(--accent);letter-spacing:.02em;
    border-bottom:1px solid var(--border);flex-shrink:0;
}
.sb-nav{flex:1;padding:.5rem 0}
.sb-section{margin-bottom:.125rem}
.sb-title{
    padding:.55rem 1.25rem .2rem;
    font-size:.63rem;font-weight:700;color:var(--muted);
    letter-spacing:.1em;text-transform:uppercase;
}
.sb-link{
    display:block;padding:.42rem 1.25rem;
    color:var(--muted);text-decoration:none;font-size:.84rem;
    border-left:2px solid transparent;
    transition:color .12s,background .12s;
    white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
}
.sb-link:hover{color:var(--text);background:rgba(255,255,255,.04)}
.sb-link.active{color:var(--text);border-left-color:var(--accent);background:rgba(108,142,245,.1)}
.sb-sep{height:1px;background:var(--border);margin:.375rem 0}
.sb-footer{border-top:1px solid var(--border);padding:.5rem;flex-shrink:0}

/* ── Mobile top bar ──────────────────────────────────── */
.mobile-bar{
    display:none;align-items:center;gap:.75rem;
    background:#13151f;border-bottom:1px solid var(--border);
    padding:.55rem 1rem;position:sticky;top:0;z-index:90;
}
.mobile-brand{font-weight:700;font-size:1rem;color:var(--accent);letter-spacing:.02em}
.hamburger{
    background:none;border:none;cursor:pointer;padding:.25rem;
    display:flex;flex-direction:column;gap:4px;
}
.hamburger span{display:block;width:20px;height:2px;background:var(--text);border-radius:2px;transition:transform .2s,opacity .2s}
.hamburger.open span:nth-child(1){transform:translateY(6px) rotate(45deg)}
.hamburger.open span:nth-child(2){opacity:0}
.hamburger.open span:nth-child(3){transform:translateY(-6px) rotate(-45deg)}
.sb-backdrop{display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:99}
.sb-backdrop.open{display:block}

/* ── Main ────────────────────────────────────────────── */
main{flex:1;padding:1.5rem;max-width:1440px;width:100%}

/* ── Shared components ───────────────────────────────── */
.card{background:var(--surface);border:1px solid var(--border);border-radius:8px;padding:1.25rem 1.5rem;margin-bottom:1rem}
.card h2{font-size:.95rem;font-weight:600;margin-bottom:1rem;color:var(--text)}
.msg-ok{background:rgba(16,185,129,.12);border:1px solid rgba(16,185,129,.3);color:#6ee7b7;padding:.5rem .875rem;border-radius:6px;margin-bottom:1rem;font-size:.85rem}
.msg-err{background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.3);color:#fca5a5;padding:.5rem .875rem;border-radius:6px;margin-bottom:1rem;font-size:.85rem}
table{width:100%;border-collapse:collapse;font-size:.85rem}
th,td{padding:.5rem .75rem;text-align:left;border-bottom:1px solid var(--border)}
th{background:rgba(255,255,255,.03);font-weight:600;color:var(--muted);font-size:.78rem;text-transform:uppercase;letter-spacing:.04em}
tr:hover td{background:rgba(255,255,255,.02)}
.btn{display:inline-block;padding:.4rem .9rem;border-radius:5px;font-size:.8rem;font-weight:600;cursor:pointer;border:none;text-decoration:none;transition:opacity .15s;line-height:1.4}
.btn:hover{opacity:.82}
.btn-primary{background:var(--accent);color:#fff}
.btn-danger{background:var(--danger);color:#fff}
.btn-sm{padding:.25rem .6rem;font-size:.75rem}
.btn-secondary{background:#2d3148;color:var(--text);border:1px solid var(--border)}
input[type=text],input[type=email],input[type=number],input[type=date],select,textarea{
    background:#0d0f1a;border:1px solid var(--border);border-radius:5px;
    padding:.4rem .6rem;font-size:.85rem;width:100%;color:var(--text);
}
input:focus,select:focus,textarea:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 2px rgba(108,142,245,.15)}
label{display:block;font-size:.78rem;font-weight:600;color:var(--muted);margin-bottom:.3rem}
code{background:#1e2030;border:1px solid var(--border);border-radius:3px;padding:.1rem .3rem;font-size:.8rem}
details summary{cursor:pointer;user-select:none}

/* ── Responsive ──────────────────────────────────────── */
@media(max-width:768px){
    .sidebar{
        position:fixed;left:0;top:0;height:100vh;
        transform:translateX(-100%);transition:transform .25s ease;
    }
    .sidebar.open{transform:translateX(0)}
    .mobile-bar{display:flex}
}
</style>
<?= $headExtra ?? '' ?>
<link rel="stylesheet" href="/assets/css/user_menu.css">
</head>
<body>
<?php
$navSections = [
    [
        'title' => 'Отчёты',
        'links' => [
            '/kitchen_online'     => 'Кухня: Онлайн',
            '/admin'              => 'Кухня: Дашборд',
            '/zapara'             => 'Кухня: Запара',
            '/rawdata'            => 'Таблица',
            '/banya'              => 'Баня',
            '/roma'               => 'Кальяны',
            '/reservations'       => 'Брони',
            '/employees'          => 'ЗП сотрудников',
            '/payday2'            => 'PayDay2',
        ],
    ],
    [
        'title' => 'Управление',
        'links' => [
            '/admin/sync'         => 'Синк',
            '/admin/access'       => 'Доступ',
            '/admin/menu'         => 'Меню',
            '/admin/telegram'     => 'Telegram',
            '/admin/logs'         => 'Логи',
            '/admin/reservations' => 'Брони (настройки)',
        ],
    ],
];
$currentPath = $currentPath ?? '/admin';
?>
<div class="sb-backdrop" id="sbBackdrop"></div>
<div class="layout">

  <aside class="sidebar" id="sidebar">
    <div class="sb-brand">▸ Veranda</div>
    <nav class="sb-nav">
      <?php foreach ($navSections as $i => $section): ?>
        <?php if ($i > 0): ?><div class="sb-sep"></div><?php endif; ?>
        <div class="sb-section">
          <div class="sb-title"><?= htmlspecialchars($section['title']) ?></div>
          <?php foreach ($section['links'] as $href => $label): ?>
            <a href="<?= $href ?>" class="sb-link<?= $currentPath === $href ? ' active' : '' ?>">
              <?= htmlspecialchars($label) ?>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    </nav>
    <div class="sb-footer">
      <?php require __DIR__ . '/partials/user_menu.php'; ?>
    </div>
  </aside>

  <div class="page-wrap">
    <div class="mobile-bar">
      <button class="hamburger" id="hamburgerBtn" aria-label="Меню">
        <span></span><span></span><span></span>
      </button>
      <span class="mobile-brand">▸ Veranda</span>
    </div>

    <main>
      <?php if (!empty($flashOk)): ?>
        <div class="msg-ok"><?= htmlspecialchars($flashOk) ?></div>
      <?php endif; ?>
      <?php if (!empty($flashErr)): ?>
        <div class="msg-err"><?= htmlspecialchars($flashErr) ?></div>
      <?php endif; ?>
      <?= $content ?? '' ?>
    </main>
  </div>

</div>

<script>
(function () {
    var sidebar  = document.getElementById('sidebar');
    var backdrop = document.getElementById('sbBackdrop');
    var btn      = document.getElementById('hamburgerBtn');
    function open()  { sidebar.classList.add('open'); backdrop.classList.add('open'); btn.classList.add('open'); }
    function close() { sidebar.classList.remove('open'); backdrop.classList.remove('open'); btn.classList.remove('open'); }
    btn.addEventListener('click', function () { sidebar.classList.contains('open') ? close() : open(); });
    backdrop.addEventListener('click', close);
})();
</script>
<script src="/assets/user_menu.js" defer></script>
</body>
</html>
