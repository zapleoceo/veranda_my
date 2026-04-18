<div class="card" style="max-width: 1000px; margin: 0 auto;">
    <h3 style="margin:0 0 6px;">Синки (cron)</h3>
    <div class="muted">Это регламентные задачи на сервере. Колонка i — что делает синк.</div>
    <table>
        <thead>
            <tr>
                <th>Синк</th>
                <th>Cron</th>
                <th>Лог</th>
                <th>Статус</th>
                <th>Run</th>
                <th>i</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($syncJobs as $job): ?>
                <?php $fi = $fileInfo($logMap[$job['key']] ?? ''); ?>
                <?php $st = $syncStatus($fi); ?>
                <tr>
                    <td style="font-weight:700;"><?= htmlspecialchars($job['label']) ?></td>
                    <td><span class="pill ok" title="<?= htmlspecialchars($cronHuman($job['cron']) . ' (' . $job['cron'] . ')', ENT_QUOTES) ?>"><?= htmlspecialchars($job['cron']) ?></span></td>
                    <td><a href="?tab=logs&view=<?= urlencode($job['key']) ?>&lines=<?= (int)$lines ?>" style="text-decoration:none; color:var(--accent); font-weight:600;"><?= htmlspecialchars($job['log']) ?></a></td>
                    <td>
                        <?php if (!empty($fi['exists'])): ?>
                            <span class="pill <?= htmlspecialchars($st['kind']) ?>"><?= htmlspecialchars($st['label']) ?></span>
                            <span class="muted">mtime: <?= htmlspecialchars($fmtSpotMtime($fi['mtime'] ?? null)) ?></span>
                        <?php else: ?>
                            <span class="pill bad">ПРОБЛЕМА</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <form method="post" style="margin:0;">
                            <input type="hidden" name="action" value="run_sync">
                            <input type="hidden" name="key" value="<?= htmlspecialchars($job['key'], ENT_QUOTES) ?>">
                            <button class="btn" type="submit">Run</button>
                        </form>
                    </td>
                    <td><span class="info-icon" data-tip="<?= htmlspecialchars($cronHuman($job['cron']) . ': ' . $job['desc'], ENT_QUOTES) ?>">i</span></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="card" style="max-width: 1000px; margin: 20px auto 0;">
    <div class="filters">
    <form method="get" class="filters" style="margin-bottom:0;">
        <input type="hidden" name="tab" value="logs">
        <div>
            <label for="view">Лог</label>
            <select class="in" id="view" name="view">
                <option value="kitchen" <?= $view === 'kitchen' ? 'selected' : '' ?>>Kitchen cron</option>
                <option value="telegram" <?= $view === 'telegram' ? 'selected' : '' ?>>Telegram alerts</option>
                <option value="menu" <?= $view === 'menu' ? 'selected' : '' ?>>Menu cron</option>
                <option value="php" <?= $view === 'php' ? 'selected' : '' ?>>PHP errors</option>
            </select>
        </div>
        <div>
            <label for="lines">Строк</label>
            <input class="in" id="lines" type="number" name="lines" min="50" max="800" value="<?= (int)$lines ?>">
        </div>
        <button class="btn" type="submit">Показать</button>
        <div class="muted" style="margin-left:auto; align-self:center;">
            <?= is_file($path) ? htmlspecialchars(basename($path)) : 'Файл не найден' ?>
        </div>
    </form>
    <form method="post" class="filters" style="margin-bottom:0;">
        <input type="hidden" name="action" value="clear_log">
        <input type="hidden" name="view" value="<?= htmlspecialchars($view, ENT_QUOTES) ?>">
        <button class="btn-danger" type="submit">Очистить</button>
    </form>
    </div>

    <pre style="background: #1e1e1e; color: #d4d4d4; padding: 12px; border-radius: 8px; overflow-x: auto; white-space: pre-wrap; font-size: 13px;"><?= htmlspecialchars($content !== '' ? $content : '—') ?></pre>
</div>
