<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($pageTitle ?? 'Veranda Admin') ?></title>
<link rel="icon" type="image/svg+xml" href="/links/favicon.svg">
<style>
:root {
    --bg:       #0f1117;
    --surface:  #1a1d27;
    --border:   #2a2d3a;
    --text:     #e2e8f0;
    --muted:    #6b7280;
    --accent:   #B88746;
    --danger:   #ef4444;
    --ok:       #10b981;
    --card:     var(--surface);
    --accent2:  rgba(184,135,70,.15);
    --card2:    rgba(255,255,255,.07);
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
    overflow:hidden;
    z-index:100;
    transition:width .2s ease,min-width .2s ease,border-color .2s ease;
}
.sb-nav{flex:1;overflow-y:auto;overflow-x:hidden;padding:.5rem 0;scrollbar-width:thin;scrollbar-color:var(--border) transparent;min-height:0}
.sidebar.sb-collapsed{width:0;min-width:0;border-right-color:transparent}
.sb-brand{
    padding:.9rem 1.25rem;
    border-bottom:1px solid var(--border);flex-shrink:0;
    display:flex;align-items:center;gap:.5rem;white-space:nowrap;
}
.sb-brand-name{
    font-family:'Cinzel',Georgia,serif;font-weight:700;font-size:1rem;
    color:var(--accent);letter-spacing:.2em;
}
.sb-collapse-btn{
    margin-left:auto;background:none;border:none;cursor:pointer;
    color:var(--muted);padding:.15rem .3rem;font-size:.9rem;line-height:1;
    transition:color .12s;flex-shrink:0;
}
.sb-collapse-btn:hover{color:var(--text)}
.sb-section{margin-bottom:.125rem}
.sb-title{
    padding:.55rem 1.25rem .2rem;
    font-size:.63rem;font-weight:700;color:var(--muted);
    letter-spacing:.1em;text-transform:uppercase;white-space:nowrap;
}
.sb-link{
    display:block;padding:.42rem 1.25rem;
    color:var(--muted);text-decoration:none;font-size:.84rem;
    border-left:2px solid transparent;
    transition:color .12s,background .12s;
    white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
}
.sb-link:hover{color:var(--text);background:rgba(255,255,255,.04)}
.sb-link.active{color:var(--text);border-left-color:var(--accent);background:rgba(184,135,70,.1)}
.sb-link.sb-off{color:var(--border);pointer-events:none;cursor:default}
.sb-sep{height:1px;background:var(--border);margin:.375rem 0}
.sb-footer{border-top:1px solid var(--border);padding:.5rem;flex-shrink:0}
.sb-user{display:flex;align-items:center;gap:.5rem;padding:.3rem .5rem}
.sb-user-icon{width:26px;height:26px;border-radius:50%;background:var(--accent2);display:inline-flex;align-items:center;justify-content:center;color:var(--accent);font-weight:800;font-size:11px;flex-shrink:0;overflow:hidden}
.sb-user-icon img{width:100%;height:100%;object-fit:cover;display:block}
.sb-user-info{display:flex;flex-direction:column;gap:1px;min-width:0}
.sb-user-name{font-size:.75rem;font-weight:600;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.sb-user-logout{font-size:.72rem;color:var(--muted);text-decoration:none;line-height:1.3}
.sb-user-logout:hover{color:var(--danger)}

/* ── Sidebar re-open button (desktop, collapsed state) ── */
.sb-show-btn{
    display:none;
    position:fixed;left:0;top:0;z-index:101;
    width:32px;height:32px;
    background:#13151f;border:none;
    border-right:1px solid var(--border);border-bottom:1px solid var(--border);
    border-radius:0 0 6px 0;
    color:var(--muted);cursor:pointer;font-size:.9rem;
    align-items:center;justify-content:center;
    transition:color .12s;
}
.sb-show-btn:hover{color:var(--text)}
.sb-show-btn.visible{display:flex}

/* ── Mobile top bar ──────────────────────────────────── */
.mobile-bar{
    display:none;align-items:center;gap:.75rem;
    background:#13151f;border-bottom:1px solid var(--border);
    padding:.55rem 1rem;position:sticky;top:0;z-index:90;
}
.mobile-brand{font-size:.95rem}
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
    padding:.4rem .6rem;font-size:.85rem;color:var(--text);
}
/* Admin form pattern: label then input/select as siblings → fill container */
label+input[type=text],label+input[type=email],label+input[type=number],label+input[type=date],
label+select,label+textarea{display:block;width:100%}
input:focus,select:focus,textarea:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 2px rgba(184,135,70,.15)}
label{display:block;font-size:.78rem;font-weight:600;color:var(--muted);margin-bottom:.3rem}
code{background:#1e2030;border:1px solid var(--border);border-radius:3px;padding:.1rem .3rem;font-size:.8rem}
details summary{cursor:pointer;user-select:none}

/* ── Responsive ──────────────────────────────────────── */
@media(max-width:768px){
    .sidebar{
        position:fixed;left:0;top:0;height:100vh;
        transform:translateX(-100%);transition:transform .25s ease;
        width:var(--sb-w) !important;min-width:var(--sb-w) !important;
        border-right-color:var(--border) !important;
    }
    .sidebar.open{transform:translateX(0)}
    .mobile-bar{display:flex}
    .sb-collapse-btn{display:none}
    .sb-show-btn{display:none !important}
}
</style>
<?= $headExtra ?? '' ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/user_menu.css">
</head>
<body>
<?php
$_sbCan = function(string $p): bool {
    $perms = $_SESSION['user_permissions'] ?? null;
    return !is_array($perms) || !empty($perms[$p]);
};

$navSections = [
    [
        'title' => 'Кухня',
        'links' => [
            '/admin'          => ['label' => 'Дашборд',        'perm' => 'dashboard'],
            '/rawdata'        => ['label' => 'Таблица',         'perm' => 'rawdata'],
            '/kitchen_online' => ['label' => 'Онлайн',          'perm' => 'kitchen_online'],
        ],
    ],
    [
        'title' => 'Отчёты',
        'links' => [
            '/zapara'       => ['label' => 'Запара',          'perm' => 'zapara'],
            '/banya'        => ['label' => 'Баня',            'perm' => 'banya'],
            '/roma'         => ['label' => 'Кальяны',         'perm' => 'roma'],
            '/payday2'      => ['label' => 'PayDay2',         'perm' => 'payday'],
            '/payday3'      => ['label' => 'PayDay3',         'perm' => 'payday'],
            '/employees'    => ['label' => 'ЗП сотрудников',  'perm' => 'employees'],
            '/reservations' => ['label' => 'Брони',           'perm' => 'reservations'],
        ],
    ],
    [
        'title' => 'Управление',
        'links' => [
            '/admin/sync'         => ['label' => 'Синк',     'perm' => 'admin'],
            '/admin/access'       => ['label' => 'Доступы',  'perm' => 'admin'],
            '/admin/menu'         => ['label' => 'Меню',     'perm' => 'admin'],
            '/admin/telegram'     => ['label' => 'Telegram', 'perm' => 'admin'],
            '/admin/logs'         => ['label' => 'Логи',     'perm' => 'admin'],
            '/admin/reservations' => ['label' => 'Брони',    'perm' => 'admin'],
        ],
    ],
];
$currentPath = $currentPath ?? '/admin';
?>
<button class="sb-show-btn" id="sbShowBtn" title="Показать меню">&#9658;</button>
<div class="sb-backdrop" id="sbBackdrop"></div>
<div class="layout">

  <aside class="sidebar" id="sidebar">
    <div class="sb-brand">
      <span class="sb-brand-name">Veranda 2</span>
      <button class="sb-collapse-btn" id="sbCollapseBtn" title="Скрыть меню">&#9664;</button>
    </div>
    <nav class="sb-nav">
      <?php $_sbFirst = true; foreach ($navSections as $section): ?>
        <?php if (!$_sbFirst): ?><div class="sb-sep"></div><?php endif; ?>
        <?php $_sbFirst = false; ?>
        <div class="sb-section">
          <div class="sb-title"><?= htmlspecialchars($section['title']) ?></div>
          <?php foreach ($section['links'] as $href => $item):
              $can = $_sbCan($item['perm']);
          ?>
            <a href="<?= $href ?>" class="sb-link<?= $currentPath === $href ? ' active' : '' ?><?= $can ? '' : ' sb-off' ?>"><?= htmlspecialchars($item['label']) ?></a>
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
      <span class="mobile-brand sb-brand-name">Veranda 2</span>
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
    var sidebar   = document.getElementById('sidebar');
    var backdrop  = document.getElementById('sbBackdrop');
    var hamburger = document.getElementById('hamburgerBtn');
    var collapseBtn = document.getElementById('sbCollapseBtn');
    var showBtn   = document.getElementById('sbShowBtn');

    // Mobile open/close
    function mobileOpen()  { sidebar.classList.add('open'); backdrop.classList.add('open'); hamburger.classList.add('open'); }
    function mobileClose() { sidebar.classList.remove('open'); backdrop.classList.remove('open'); hamburger.classList.remove('open'); }
    hamburger.addEventListener('click', function () { sidebar.classList.contains('open') ? mobileClose() : mobileOpen(); });
    backdrop.addEventListener('click', mobileClose);

    // Desktop collapse
    var STORAGE_KEY = 'sb_collapsed';
    function isDesktop() { return window.innerWidth > 768; }

    function desktopCollapse() {
        sidebar.classList.add('sb-collapsed');
        showBtn.classList.add('visible');
        try { localStorage.setItem(STORAGE_KEY, '1'); } catch(e) {}
    }
    function desktopExpand() {
        sidebar.classList.remove('sb-collapsed');
        showBtn.classList.remove('visible');
        try { localStorage.setItem(STORAGE_KEY, '0'); } catch(e) {}
    }

    collapseBtn.addEventListener('click', function () {
        if (!isDesktop()) return;
        desktopCollapse();
    });
    showBtn.addEventListener('click', function () {
        desktopExpand();
    });

    // Restore state on load
    if (isDesktop()) {
        try {
            if (localStorage.getItem(STORAGE_KEY) === '1') desktopCollapse();
        } catch(e) {}
    }
})();
</script>
</body>
</html>
