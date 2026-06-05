<?php
$statusLabels = ['all' => 'Все', 'published' => 'Опубликованные', 'unpublished' => 'Неопубликованные'];
$currentStatus = $_GET['status'] ?? 'published';
$currentSearch = htmlspecialchars($_GET['q'] ?? '');
?>
<div class="card" style="padding:.75rem 1.5rem">
    <form method="GET" style="display:flex;flex-wrap:wrap;gap:.5rem;align-items:flex-end">
        <div style="flex:1;min-width:180px">
            <label>Поиск</label>
            <input type="text" name="q" value="<?= $currentSearch ?>" placeholder="Название или Poster ID">
        </div>
        <div>
            <label>Статус</label>
            <select name="status">
                <?php foreach ($statusLabels as $v => $l): ?>
                    <option value="<?= $v ?>" <?= $currentStatus === $v ? 'selected' : '' ?>><?= $l ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn btn-primary">Фильтр</button>
        <span style="flex:1"></span>
        <a href="?view=cats" class="btn btn-secondary">⚙ Категории/цеха</a>
        <form method="POST" style="margin:0">
            <button type="submit" name="sync_menu" class="btn btn-secondary"
                onclick="return confirm('Синхронизировать меню из Poster?')">↻ Синк из Poster</button>
        </form>
    </form>
    <?php if ($syncMeta['menu_last_sync_at']): ?>
        <div style="font-size:.75rem;color:#9ca3af;margin-top:.4rem">
            Последний синк: <?= htmlspecialchars($syncMeta['menu_last_sync_at']) ?>
            <?php if ($syncMeta['menu_last_sync_error']): ?>
                <span style="color:#dc2626"> — Ошибка: <?= htmlspecialchars($syncMeta['menu_last_sync_error']) ?></span>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<div class="card" style="padding:0">
    <div style="padding:.75rem 1rem;border-bottom:1px solid #e5e7eb;font-size:.8rem;color:#6b7280">
        Найдено: <?= $total ?>, страница <?= $page ?> из <?= $pages ?>
    </div>
    <div style="overflow-x:auto">
    <table>
        <thead>
            <tr>
                <th>Poster ID</th>
                <th>Название RU</th>
                <th>EN</th>
                <th>Цена</th>
                <th>Цех</th>
                <th>Категория</th>
                <th>Статус</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($items as $item): ?>
            <tr id="row-<?= $item['poster_id'] ?>">
                <td style="font-size:.75rem;color:#9ca3af"><?= (int)$item['poster_id'] ?></td>
                <td><?= htmlspecialchars((string)($item['title_ru'] ?? '')) ?></td>
                <td style="color:#6b7280"><?= htmlspecialchars((string)($item['title_en'] ?? '')) ?></td>
                <td style="white-space:nowrap"><?= number_format((float)($item['price'] ?? 0), 0, '.', ' ') ?> ₫</td>
                <td style="font-size:.75rem"><?= htmlspecialchars((string)($item['workshop_name'] ?? '')) ?></td>
                <td style="font-size:.75rem"><?= htmlspecialchars((string)($item['category_name'] ?? '')) ?></td>
                <td>
                    <label style="display:flex;align-items:center;gap:.3rem;cursor:pointer;font-size:.8rem">
                        <input type="checkbox" class="pub-toggle"
                               data-id="<?= (int)$item['poster_id'] ?>"
                               <?= $item['is_published'] ? 'checked' : '' ?>>
                        <span><?= $item['is_published'] ? 'Опубл.' : 'Скрыт' ?></span>
                    </label>
                </td>
                <td>
                    <a href="?view=edit&id=<?= (int)$item['poster_id'] ?>" class="btn btn-sm btn-secondary">Ред.</a>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($items)): ?>
            <tr><td colspan="8" style="text-align:center;color:#9ca3af;padding:1.5rem">Нет позиций</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
    <?php if ($pages > 1): ?>
    <div style="padding:.75rem 1rem;display:flex;gap:.25rem;flex-wrap:wrap">
        <?php for ($p = 1; $p <= $pages; $p++): ?>
            <?php $qs = array_merge($_GET, ['page' => $p]); ?>
            <a href="?<?= http_build_query($qs) ?>"
               class="btn btn-sm <?= $p === $page ? 'btn-primary' : 'btn-secondary' ?>"><?= $p ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</div>

<script>
document.querySelectorAll('.pub-toggle').forEach(cb => {
    cb.addEventListener('change', async function() {
        const id = this.dataset.id;
        const pub = this.checked ? 1 : 0;
        const span = this.nextElementSibling;
        try {
            const r = await fetch('?ajax=toggle_publish', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'poster_id=' + id + '&publish=' + pub,
            });
            const d = await r.json();
            if (d.ok) {
                span.textContent = pub ? 'Опубл.' : 'Скрыт';
            } else {
                this.checked = !this.checked;
                alert('Ошибка: ' + d.error);
            }
        } catch(e) {
            this.checked = !this.checked;
        }
    });
});
</script>
