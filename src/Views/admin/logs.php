<?php
$logLabels = ['kitchen' => 'Kitchen', 'telegram' => 'Telegram', 'menu' => 'Menu', 'php' => 'PHP errors'];
?>
<div class="card" style="padding:.75rem 1.5rem">
    <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
        <?php foreach ($logLabels as $k => $lbl): ?>
            <a href="?view=<?= $k ?>&lines=<?= $lines ?>"
               class="btn btn-sm <?= $view === $k ? 'btn-primary' : 'btn-secondary' ?>"><?= $lbl ?></a>
        <?php endforeach; ?>
        <span style="flex:1"></span>
        <select onchange="location='?view=<?= $view ?>&lines='+this.value" style="width:auto">
            <?php foreach ([50,100,200,400,800] as $n): ?>
                <option value="<?= $n ?>" <?= $lines === $n ? 'selected' : '' ?>><?= $n ?> строк</option>
            <?php endforeach; ?>
        </select>
    </div>
</div>

<div class="card">
    <div style="display:flex;gap:.5rem;margin-bottom:.75rem;align-items:center">
        <h2 style="margin:0"><?= $logLabels[$view] ?? $view ?></h2>
        <span style="flex:1"></span>
        <?php $info = $fileInfo($logMap[$view] ?? ''); ?>
        <?php if ($info['exists']): ?>
            <span style="font-size:.75rem;color:#9ca3af">
                <?= round($info['size'] / 1024, 1) ?> KB,
                обновлён <?= date('d.m H:i', $info['mtime']) ?>
            </span>
        <?php endif; ?>
        <?php if (in_array($view, ['kitchen','telegram','menu'])): ?>
        <form method="POST" style="margin:0">
            <input type="hidden" name="action" value="run_sync">
            <input type="hidden" name="key" value="<?= $view ?>">
            <button class="btn btn-sm btn-primary" onclick="return confirm('Запустить синк?')">▶ Запустить</button>
        </form>
        <?php endif; ?>
        <form method="POST" style="margin:0">
            <input type="hidden" name="action" value="clear_log">
            <button class="btn btn-sm btn-danger" onclick="return confirm('Очистить лог?')">Очистить</button>
        </form>
    </div>
    <pre style="background:#0f172a;color:#e2e8f0;padding:1rem;border-radius:6px;font-size:.75rem;line-height:1.5;overflow:auto;max-height:65vh;white-space:pre-wrap;word-break:break-all"><?= htmlspecialchars($content_raw ?: '(пусто)') ?></pre>
</div>

<div class="card">
    <h2>Задания cron</h2>
    <table>
        <thead><tr><th>Задание</th><th>Расписание</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($syncJobs as $key => $job): ?>
            <tr>
                <td><?= htmlspecialchars($job['label']) ?></td>
                <td><code><?= htmlspecialchars($job['cron']) ?></code></td>
                <td>
                    <?php $i = $fileInfo($logMap[$key] ?? ''); ?>
                    <?php if ($i['exists']): ?>
                        <span style="font-size:.75rem;color:#9ca3af">лог: <?= date('d.m H:i', (int)$i['mtime']) ?></span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
