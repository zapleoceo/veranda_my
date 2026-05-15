<div class="card">
    <h2>Статус синков</h2>
    <table>
        <thead>
            <tr><th>Синк</th><th>Последний запуск</th><th>Результат</th><th>Ошибка</th><th>Описание</th></tr>
        </thead>
        <tbody>
        <?php foreach ($syncDefs as $d): ?>
            <tr>
                <td style="font-weight:600;white-space:nowrap"><?= htmlspecialchars($d['label']) ?></td>
                <td style="white-space:nowrap;font-size:.8rem"><?= htmlspecialchars($meta[$d['at_key']] ?: '—') ?></td>
                <td style="font-size:.8rem"><?= htmlspecialchars($meta[$d['result_key']] ?: '—') ?></td>
                <td style="font-size:.75rem;color:#dc2626"><?= htmlspecialchars($meta[$d['error_key']] ?: '') ?></td>
                <td style="font-size:.75rem;color:#6b7280"><?= htmlspecialchars($d['desc']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="card">
    <h2>Запуск вручную</h2>
    <?php if (!$canExec): ?>
        <div class="msg-err">exec() отключён на сервере — запуск недоступен.</div>
    <?php else: ?>
    <form method="POST" style="display:flex;flex-wrap:wrap;gap:.5rem;align-items:flex-end">
        <input type="hidden" name="run_script" value="1">
        <div style="flex:1;min-width:220px">
            <label>Скрипт</label>
            <select name="script_name" id="scriptSel">
                <option value="kitchen_cron">Кухня: синк за сегодня</option>
                <option value="kitchen_resync_range">Кухня: пересинк диапазон (фон)</option>
                <option value="tg_alerts">Telegram: уведомления</option>
            </select>
        </div>
        <div id="dateRange" style="display:none;gap:.5rem">
            <div><label>От</label><input type="date" name="date_from" value="<?= date('Y-m-d') ?>"></div>
            <div><label>До</label><input type="date" name="date_to" value="<?= date('Y-m-d') ?>"></div>
        </div>
        <button type="submit" class="btn btn-primary" onclick="return confirm('Запустить?')">▶ Запустить</button>
    </form>
    <?php endif; ?>
    <?= $runResultHtml ?>
</div>

<script>
document.getElementById('scriptSel')?.addEventListener('change', function() {
    document.getElementById('dateRange').style.display =
        this.value === 'kitchen_resync_range' ? 'flex' : 'none';
});
</script>
