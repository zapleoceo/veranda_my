<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($pageTitle ?? 'Veranda Admin') ?></title>
<style>
:root {
    --bg:      #0f1117;
    --surface: #1a1d27;
    --border:  #2a2d3a;
    --text:    #e2e8f0;
    --muted:   #6b7280;
    --accent:  #6c8ef5;
    --danger:  #ef4444;
    --ok:      #10b981;
}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:var(--bg);color:var(--text);min-height:100vh}
nav{background:#13151f;border-bottom:1px solid var(--border);display:flex;align-items:center;padding:0 1rem;position:sticky;top:0;z-index:100}
nav .brand{font-weight:700;font-size:1.05rem;padding:.7rem .5rem;margin-right:.5rem;color:var(--accent);white-space:nowrap;letter-spacing:.02em}
nav a{display:inline-block;padding:.7rem .85rem;color:var(--muted);text-decoration:none;font-size:.85rem;transition:color .15s;border-bottom:2px solid transparent}
nav a:hover{color:var(--text)}
nav a.active{color:var(--text);border-bottom-color:var(--accent)}
nav .spacer{flex:1}
nav .user{font-size:.75rem;color:var(--muted);padding:.7rem .5rem}
nav a.logout{color:var(--muted);font-size:.78rem;padding:.7rem .5rem}
main{max-width:1440px;margin:0 auto;padding:1.5rem}
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
</style>
<?= $headExtra ?? '' ?>
</head>
<body>
<nav>
    <span class="brand">▸ Veranda</span>
    <?php
    $tabs = [
        '/admin'              => 'Dashboard',
        '/admin/access'       => 'Доступ',
        '/admin/logs'         => 'Логи',
        '/admin/reservations' => 'Брони',
        '/admin/menu'         => 'Меню',
        '/admin/telegram'     => 'Telegram',
        '/admin/sync'         => 'Синк',
    ];
    $currentPath = $currentPath ?? '/admin';
    foreach ($tabs as $href => $label):
        $active = $currentPath === $href ? ' active' : '';
    ?>
    <a href="<?= $href ?>" class="<?= trim($active) ?>"><?= $label ?></a>
    <?php endforeach; ?>
    <span class="spacer"></span>
    <span class="user"><?= htmlspecialchars($userEmail ?? '') ?></span>
    <a href="/logout" class="logout">Выйти</a>
</nav>
<main>
<?php if (!empty($flashOk)): ?>
    <div class="msg-ok"><?= htmlspecialchars($flashOk) ?></div>
<?php endif; ?>
<?php if (!empty($flashErr)): ?>
    <div class="msg-err"><?= htmlspecialchars($flashErr) ?></div>
<?php endif; ?>
<?= $content ?? '' ?>
</main>
</body>
</html>
