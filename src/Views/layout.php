<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($pageTitle ?? 'Veranda Admin') ?></title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#f4f4f5;color:#1a1a1a;min-height:100vh}
nav{background:#1a1a2e;color:#fff;display:flex;align-items:center;gap:0;padding:0 1rem;position:sticky;top:0;z-index:100;box-shadow:0 2px 8px rgba(0,0,0,.3)}
nav .brand{font-weight:700;font-size:1.1rem;padding:.75rem .5rem;margin-right:.5rem;color:#e0e0ff;white-space:nowrap}
nav a{display:inline-block;padding:.75rem .9rem;color:#b0b0d0;text-decoration:none;font-size:.875rem;transition:background .15s,color .15s;border-bottom:3px solid transparent}
nav a:hover{background:rgba(255,255,255,.07);color:#fff}
nav a.active{color:#fff;border-bottom-color:#6c8ef5}
nav .spacer{flex:1}
nav .user{font-size:.8rem;color:#888;padding:.75rem .5rem}
main{max-width:1400px;margin:0 auto;padding:1.5rem}
.card{background:#fff;border-radius:8px;box-shadow:0 1px 4px rgba(0,0,0,.08);padding:1.25rem 1.5rem;margin-bottom:1rem}
.card h2{font-size:1rem;font-weight:600;margin-bottom:1rem;color:#333}
.msg-ok{background:#d1fae5;color:#065f46;padding:.5rem .875rem;border-radius:6px;margin-bottom:1rem;font-size:.875rem}
.msg-err{background:#fee2e2;color:#991b1b;padding:.5rem .875rem;border-radius:6px;margin-bottom:1rem;font-size:.875rem}
table{width:100%;border-collapse:collapse;font-size:.875rem}
th,td{padding:.5rem .75rem;text-align:left;border-bottom:1px solid #e5e7eb}
th{background:#f9fafb;font-weight:600;color:#374151}
tr:hover td{background:#f9fafb}
.btn{display:inline-block;padding:.4rem .9rem;border-radius:5px;font-size:.8rem;font-weight:600;cursor:pointer;border:none;text-decoration:none;transition:opacity .15s}
.btn:hover{opacity:.85}
.btn-primary{background:#6c8ef5;color:#fff}
.btn-danger{background:#ef4444;color:#fff}
.btn-sm{padding:.25rem .6rem;font-size:.75rem}
.btn-secondary{background:#6b7280;color:#fff}
input[type=text],input[type=email],select,textarea{border:1px solid #d1d5db;border-radius:5px;padding:.4rem .6rem;font-size:.875rem;width:100%}
input[type=text]:focus,input[type=email]:focus,select:focus{outline:none;border-color:#6c8ef5;box-shadow:0 0 0 2px rgba(108,142,245,.2)}
label{display:block;font-size:.8rem;font-weight:600;color:#374151;margin-bottom:.25rem}
</style>
<?= $headExtra ?? '' ?>
</head>
<body>
<nav>
    <span class="brand">Veranda</span>
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
    <a href="<?= $href ?>" class="<?= $active ?>"><?= $label ?></a>
    <?php endforeach; ?>
    <span class="spacer"></span>
    <span class="user"><?= htmlspecialchars($userEmail ?? '') ?></span>
    <a href="/logout" style="padding:.75rem .6rem;color:#888;font-size:.8rem">Выйти</a>
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
