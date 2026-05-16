<div class="card">
    <h2>Добавить пользователя</h2>
    <form method="POST" style="display:flex;gap:.5rem;align-items:flex-end">
        <div style="flex:1"><label>Email</label><input type="email" name="email" required placeholder="user@example.com"></div>
        <button type="submit" name="add_email" class="btn btn-primary">Добавить</button>
    </form>
</div>

<div class="card">
    <h2>Пользователи</h2>
    <table>
        <thead>
            <tr>
                <th>Email</th>
                <th>Имя</th>
                <th>Telegram</th>
                <th>Права</th>
                <th>Добавлен</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($users as $u):
            $perms = json_decode((string)($u['permissions_json'] ?? '{}'), true) ?: [];
            $activePerms = array_filter($perms, fn($v) => $v);
            $permLabels = array_intersect_key($permissionKeys, $activePerms);
        ?>
            <tr>
                <td><?= htmlspecialchars((string)$u['email']) ?></td>
                <td><?= htmlspecialchars((string)($u['name'] ?? '')) ?></td>
                <td><?= htmlspecialchars((string)($u['telegram_username'] ?? '')) ?></td>
                <td style="font-size:.75rem;color:#6b7280"><?= htmlspecialchars(implode(', ', $permLabels)) ?: '—' ?></td>
                <td style="font-size:.75rem;color:#9ca3af"><?= $u['created_at'] ? date('d.m.Y', strtotime($u['created_at'])) : '—' ?></td>
                <td>
                    <button class="btn btn-sm btn-secondary" onclick="openPerms(<?= htmlspecialchars(json_encode($u), ENT_QUOTES) ?>)">Права</button>
                    <a href="?delete=<?= urlencode((string)$u['email']) ?>"
                       class="btn btn-sm btn-danger"
                       onclick="return confirm('Удалить <?= htmlspecialchars((string)$u['email'], ENT_QUOTES) ?>?')">✕</a>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($users)): ?>
            <tr><td colspan="6" style="text-align:center;color:#9ca3af;padding:1rem">Нет пользователей</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Permissions modal -->
<div id="permsModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center">
    <div style="background:#fff;border-radius:10px;padding:1.5rem;width:480px;max-height:90vh;overflow-y:auto">
        <h2 style="margin-bottom:1rem;font-size:1rem">Права пользователя</h2>
        <form method="POST" id="permsForm">
            <input type="hidden" name="perm_email" id="permEmail">
            <div style="margin-bottom:.75rem">
                <label>Telegram username</label>
                <input type="text" name="perm_tg_username" id="permTg" placeholder="username без @">
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:.4rem;margin-bottom:1rem">
            <?php foreach ($permissionKeys as $key => $label): ?>
                <label style="display:flex;align-items:center;gap:.4rem;font-weight:400;font-size:.8rem;cursor:pointer">
                    <input type="checkbox" name="perm_<?= $key ?>" id="perm_<?= $key ?>" value="1">
                    <?= htmlspecialchars($label) ?>
                </label>
            <?php endforeach; ?>
            </div>
            <div style="display:flex;gap:.5rem">
                <button type="submit" name="save_user_permissions" class="btn btn-primary">Сохранить</button>
                <button type="button" onclick="closePerms()" class="btn btn-secondary">Отмена</button>
            </div>
        </form>
    </div>
</div>

<script>
const PERM_KEYS = <?= json_encode(array_keys($permissionKeys)) ?>;
function openPerms(u) {
    document.getElementById('permEmail').value = u.email;
    document.getElementById('permTg').value = u.telegram_username || '';
    const perms = JSON.parse(u.permissions_json || '{}');
    PERM_KEYS.forEach(k => {
        const cb = document.getElementById('perm_' + k);
        if (cb) cb.checked = !!perms[k];
    });
    document.getElementById('permsModal').style.display = 'flex';
}
function closePerms() {
    document.getElementById('permsModal').style.display = 'none';
}
document.getElementById('permsModal').addEventListener('click', e => {
    if (e.target === e.currentTarget) closePerms();
});
</script>
