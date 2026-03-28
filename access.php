<?php
require_once __DIR__ . '/auth_check.php';

if (!veranda_can('admin') && !veranda_can('access_admin')) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$message = '';
$error = '';

$usersTable = $db->t('users');

$usersCols = [];
try {
    $cols = $db->query("SHOW COLUMNS FROM {$usersTable}")->fetchAll();
    foreach ($cols as $c) {
        $f = strtolower((string)($c['Field'] ?? ''));
        if ($f !== '') $usersCols[$f] = true;
    }
} catch (\Throwable $e) {
    $usersCols = [];
}

$permissionKeys = [
    'dashboard' => 'Дашборд',
    'rawdata' => 'Сырые данные',
    'kitchen_online' => 'КухняOnline',
    'payday' => 'Payday',
    'admin' => 'УПРАВЛЕНИЕ',
    'access_admin' => 'Выдача доступов',
    'exclude_toggle' => 'Игнор + ✅ Принято (Telegram)',
    'telegram_ack' => '✅ Принято (Telegram)',
];

if (isset($_POST['save_user_permissions'])) {
    $targetEmail = trim((string)($_POST['perm_email'] ?? ''));
    if ($targetEmail !== '') {
        $perms = [];
        foreach ($permissionKeys as $k => $_label) {
            $perms[$k] = isset($_POST['perm_' . $k]) ? 1 : 0;
        }
        $perms['telegram_ack'] = !empty($perms['exclude_toggle']) ? 1 : 0;
        $tgUsername = strtolower(trim((string)($_POST['perm_tg_username'] ?? '')));
        $tgUsername = ltrim($tgUsername, '@');
        if ($tgUsername === '') {
            $tgUsername = null;
        }
        $setParts = [];
        $params = [];
        if (!empty($usersCols['permissions_json'])) {
            $setParts[] = "permissions_json = ?";
            $params[] = json_encode($perms, JSON_UNESCAPED_UNICODE);
        }
        if (!empty($usersCols['telegram_username'])) {
            $setParts[] = "telegram_username = ?";
            $params[] = $tgUsername;
        }
        if (!empty($setParts)) {
            $params[] = $targetEmail;
            $db->query("UPDATE {$usersTable} SET " . implode(', ', $setParts) . " WHERE email = ? LIMIT 1", $params);
            $message = "Права для {$targetEmail} сохранены.";
        } else {
            $error = 'В таблице users нет колонок для сохранения прав (permissions_json/telegram_username).';
        }
    }
}

if (isset($_POST['add_email'])) {
    $email = trim((string)($_POST['email'] ?? ''));
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        try {
            $db->query("INSERT INTO {$usersTable} (email) VALUES (?)", [$email]);
            $message = "Пользователь {$email} успешно добавлен.";
        } catch (\Throwable $e) {
            $error = "Ошибка при добавлении: " . $e->getMessage();
        }
    } else {
        $error = "Некорректный email.";
    }
}

if (isset($_POST['add_self'])) {
    $email = trim((string)($_SESSION['user_email'] ?? ''));
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        try {
            if (!empty($usersCols['is_active'])) {
                $db->query(
                    "INSERT INTO {$usersTable} (email, is_active) VALUES (?, 1)
                     ON DUPLICATE KEY UPDATE is_active = 1",
                    [$email]
                );
            } else {
                $db->query("INSERT INTO {$usersTable} (email) VALUES (?)", [$email]);
            }
            $message = "Пользователь {$email} успешно добавлен.";
        } catch (\Throwable $e) {
            $error = "Ошибка при добавлении: " . $e->getMessage();
        }
    } else {
        $error = "Некорректный email.";
    }
}

$users = [];
try {
    $q = "SELECT * FROM {$usersTable} ORDER BY email ASC";
    $users = $db->query($q)->fetchAll();
    if (!is_array($users)) $users = [];
} catch (\Throwable $e) {
    $users = [];
    $error = $error !== '' ? $error : $e->getMessage();
}

$getPerms = function ($row) use ($permissionKeys): array {
    $raw = (string)($row['permissions_json'] ?? '');
    $decoded = [];
    if ($raw !== '') {
        $tmp = json_decode($raw, true);
        if (is_array($tmp)) $decoded = $tmp;
    }
    $out = [];
    foreach ($permissionKeys as $k => $_label) {
        $out[$k] = !empty($decoded[$k]);
    }
    if (!empty($out['exclude_toggle']) || !empty($out['telegram_ack'])) {
        $out['exclude_toggle'] = true;
        $out['telegram_ack'] = true;
    }
    if (!empty($out['admin'])) {
        $out['access_admin'] = true;
    }
    return $out;
};

?><!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Выдача доступов</title>
    <style>
        body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; margin:0; background:#f5f5f5; color:#111827; }
        .wrap { max-width: 1100px; margin: 0 auto; padding: 16px; }
        .card { background: #fff; border: 1px solid #e5e7eb; border-radius: 14px; padding: 12px; box-shadow: 0 4px 10px rgba(0,0,0,0.04); }
        h1 { margin:0; font-size: 20px; }
        .muted { color:#6b7280; font-size: 12px; }
        .top { display:flex; align-items:flex-end; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
        .row { display:flex; gap: 10px; flex-wrap: wrap; align-items:center; }
        input[type="email"], input[type="text"] { padding: 10px 12px; border:1px solid #d1d5db; border-radius: 10px; min-width: 260px; }
        button { padding: 10px 14px; border-radius: 10px; border: 1px solid #111827; background:#111827; color:#fff; font-weight: 800; cursor:pointer; }
        button.secondary { background:#fff; color:#111827; }
        .msg { margin-top: 10px; padding: 10px 12px; border-radius: 10px; border:1px solid rgba(46,125,50,0.35); background: rgba(46,125,50,0.08); }
        .err { margin-top: 10px; padding: 10px 12px; border-radius: 10px; border:1px solid rgba(211,47,47,0.35); background: rgba(211,47,47,0.08); }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { padding: 10px; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
        th { text-align:left; font-size: 12px; letter-spacing: 0.06em; text-transform: uppercase; color:#6b7280; background:#f9fafb; }
        .pill { display:inline-flex; align-items:center; gap:6px; font-size:12px; padding: 4px 8px; border-radius: 999px; border:1px solid #e5e7eb; background:#fff; margin-right: 6px; margin-bottom: 6px; }
        .perm-modal { position: fixed; inset: 0; background: rgba(0,0,0,0.55); display:none; align-items:center; justify-content:center; padding: 14px; }
        .perm-modal-inner { width: min(720px, 100%); }
        .perm-modal-title { font-weight: 900; font-size: 18px; }
        .perm-grid { display:grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 10px; }
        .perm-row { display:flex; gap: 10px; align-items:center; padding: 8px 10px; border: 1px solid #e5e7eb; border-radius: 12px; }
        .perm-list { display:grid; gap: 8px; }
        @media (max-width: 720px) { .perm-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <div class="top">
            <div>
                <h1>Выдача доступов</h1>
                <div class="muted">Эта страница отдельно управляется правом “Выдача доступов”.</div>
            </div>
            <div class="row">
                <form method="post" class="row" style="margin:0;">
                    <input type="email" name="email" placeholder="email пользователя" required>
                    <button type="submit" name="add_email" value="1">Добавить</button>
                </form>
                <form method="post" style="margin:0;">
                    <button class="secondary" type="submit" name="add_self" value="1">Добавить себя</button>
                </form>
            </div>
        </div>

        <?php if ($message !== ''): ?>
            <div class="msg"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
            <div class="err"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <table>
            <thead>
            <tr>
                <th>Email</th>
                <th>Telegram</th>
                <th>Права</th>
                <th style="width: 120px;"></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($users as $u): ?>
                <?php
                    $email = (string)($u['email'] ?? '');
                    $tg = (string)($u['telegram_username'] ?? '');
                    $perms = $getPerms($u);
                    $permsView = [];
                    foreach ($permissionKeys as $k => $label) {
                        if ($k === 'telegram_ack') continue;
                        if (!empty($perms[$k])) $permsView[] = $label;
                    }
                ?>
                <tr>
                    <td><?= htmlspecialchars($email) ?></td>
                    <td><?= htmlspecialchars($tg) ?></td>
                    <td>
                        <?php foreach ($permsView as $p): ?>
                            <span class="pill"><?= htmlspecialchars($p) ?></span>
                        <?php endforeach; ?>
                    </td>
                    <td>
                        <button type="button" class="secondary editPermBtn"
                                data-email="<?= htmlspecialchars($email) ?>"
                                data-tg="<?= htmlspecialchars($tg) ?>"
                                data-perms="<?= htmlspecialchars(json_encode($perms, JSON_UNESCAPED_UNICODE)) ?>">Права</button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="perm-modal" id="permModal">
    <div class="card perm-modal-inner">
        <div style="display:flex; align-items:center; justify-content: space-between; gap: 10px;">
            <div class="perm-modal-title">Права доступа</div>
            <button type="button" class="secondary" id="permClose">Закрыть</button>
        </div>
        <form method="post" style="margin-top: 10px;">
            <input type="hidden" name="save_user_permissions" value="1">
            <input type="hidden" name="perm_email" id="permEmail">
            <div class="perm-grid">
                <div>
                    <div class="muted" style="font-weight:800; text-transform:uppercase;">Telegram username</div>
                    <input type="text" name="perm_tg_username" id="permTgUsername" placeholder="например: oleh_sapiens">
                    <div class="muted" style="margin-top:6px;">Нужен для кнопки “Принято” в Telegram. Пиши без @.</div>
                </div>
                <div class="perm-list">
                    <?php foreach ($permissionKeys as $k => $label): ?>
                        <?php if ($k === 'telegram_ack') continue; ?>
                        <label class="perm-row">
                            <input type="checkbox" name="perm_<?= htmlspecialchars($k) ?>" id="perm_<?= htmlspecialchars($k) ?>" value="1">
                            <?= htmlspecialchars($label) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div style="display:flex; justify-content:flex-end; margin-top: 12px;">
                <button type="submit">Сохранить</button>
            </div>
        </form>
    </div>
</div>

<script>
    const modal = document.getElementById('permModal');
    const emailEl = document.getElementById('permEmail');
    const tgEl = document.getElementById('permTgUsername');
    const closeBtn = document.getElementById('permClose');

    const defaultPerms = {
        dashboard: true,
        rawdata: true,
        kitchen_online: true,
        payday: false,
        admin: true,
        access_admin: false,
        exclude_toggle: true,
    };

    const open = (email, perms, tg) => {
        emailEl.value = email;
        tgEl.value = (tg || '').trim();
        const p = Object.assign({}, defaultPerms, perms || {});
        if (p.telegram_ack && !p.exclude_toggle) p.exclude_toggle = true;
        Object.keys(defaultPerms).forEach((k) => {
            const cb = document.getElementById('perm_' + k);
            if (cb) cb.checked = !!p[k];
        });
        modal.style.display = 'flex';
    };

    document.querySelectorAll('.editPermBtn').forEach((btn) => {
        btn.addEventListener('click', () => {
            let perms = {};
            try { perms = JSON.parse(btn.dataset.perms || '{}'); } catch (_) { perms = {}; }
            open(btn.dataset.email || '', perms || {}, btn.dataset.tg || '');
        });
    });

    closeBtn.addEventListener('click', () => { modal.style.display = 'none'; });
    modal.addEventListener('click', (e) => { if (e.target === modal) modal.style.display = 'none'; });
</script>
</body>
</html>

