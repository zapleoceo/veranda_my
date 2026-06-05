<?php
/** @var array $workshops */
/** @var array $categories */
?>
<div style="margin-bottom:.75rem;display:flex;gap:.6rem;align-items:center;flex-wrap:wrap">
    <a href="/admin/menu" class="btn btn-sm btn-secondary">← К списку блюд</a>
    <h2 style="margin:0;font-size:1.1rem">Категории и цеха — маппинг сайта</h2>
</div>

<div class="card" style="padding:.75rem 1rem;margin-bottom:1rem;font-size:.8rem;color:#6b7280;line-height:1.5">
    Site-категории создаются из категорий Poster при синке (привязка по <code>poster_id</code>). Здесь можно задать
    <b>имя на сайте</b> (RU), привязать категорию к <b>цеху</b>, включить/выключить показ и порядок. Можно создать
    <b>свою</b> категорию (напр. «Кальян») — синк её не трогает.<br>
    Чтобы цех и категория появились на публичном меню, у обоих должно быть непустое <b>RU-имя</b> и включён <b>показ</b>,
    а у блюд — выбрана эта категория и стоять «Опубликовано».
</div>

<!-- ЦЕХА -->
<div class="card" style="padding:0;margin-bottom:1rem">
    <div style="padding:.6rem 1rem;border-bottom:1px solid #e5e7eb;font-weight:600">Цеха (верхний уровень)</div>
    <div style="overflow-x:auto">
    <table>
        <thead><tr><th>Poster</th><th>Имя на сайте (RU)</th><th>Показ</th><th>Порядок</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($workshops as $w): $wid = (int)$w['id']; ?>
            <tr data-ws="<?= $wid ?>">
                <td style="font-size:.75rem;color:#9ca3af;white-space:nowrap"><?= (int)$w['poster_id'] ?> · <?= htmlspecialchars((string)$w['name_raw']) ?></td>
                <td><input type="text" class="ws-name" value="<?= htmlspecialchars((string)$w['name_ru']) ?>" style="min-width:200px"></td>
                <td style="text-align:center"><input type="checkbox" class="ws-show" <?= $w['show_on_site'] ? 'checked' : '' ?>></td>
                <td><input type="number" class="ws-sort" value="<?= (int)$w['sort_order'] ?>" style="width:64px"></td>
                <td style="white-space:nowrap"><button class="btn btn-sm btn-secondary" onclick="saveWs(<?= $wid ?>)">Сохранить</button> <span class="ws-status" style="font-size:.8rem"></span></td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($workshops)): ?><tr><td colspan="5" style="text-align:center;color:#9ca3af;padding:1rem">Нет цехов</td></tr><?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<!-- КАТЕГОРИИ -->
<div class="card" style="padding:0;margin-bottom:1rem">
    <div style="padding:.6rem 1rem;border-bottom:1px solid #e5e7eb;font-weight:600">Категории</div>
    <div style="overflow-x:auto">
    <table>
        <thead><tr><th>Poster</th><th>Имя на сайте (RU)</th><th>Цех</th><th>Показ</th><th>Порядок</th><th>Блюд</th><th>Объединить в</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($categories as $c): $cid = (int)$c['id']; $isCustom = (int)$c['poster_id'] >= 900000; ?>
            <tr data-cat="<?= $cid ?>">
                <td style="font-size:.75rem;color:#9ca3af;white-space:nowrap">
                    <?php if ($isCustom): ?><span style="color:#2563eb">своя</span><?php else: ?><?= (int)$c['poster_id'] ?><?php endif; ?>
                    · <?= htmlspecialchars((string)$c['name_raw']) ?>
                </td>
                <td><input type="text" class="cat-name" value="<?= htmlspecialchars((string)$c['name_ru']) ?>" style="min-width:160px"></td>
                <td>
                    <select class="cat-ws">
                        <option value="0">— нет —</option>
                        <?php foreach ($workshops as $w): ?>
                            <option value="<?= (int)$w['id'] ?>"<?= (int)$c['workshop_id'] === (int)$w['id'] ? ' selected' : '' ?>><?= htmlspecialchars((string)($w['name_ru'] !== '' ? $w['name_ru'] : $w['name_raw'])) ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
                <td style="text-align:center"><input type="checkbox" class="cat-show" <?= $c['show_on_site'] ? 'checked' : '' ?>></td>
                <td><input type="number" class="cat-sort" value="<?= (int)$c['sort_order'] ?>" style="width:64px"></td>
                <td style="text-align:center;font-size:.8rem"><?= (int)$c['item_count'] ?></td>
                <td>
                    <select class="cat-merge" onchange="mergeCat(<?= $cid ?>, this)">
                        <option value="0">— не объединять —</option>
                        <?php foreach ($categories as $c2): if ((int)$c2['id'] === $cid) continue; ?>
                            <option value="<?= (int)$c2['id'] ?>"><?= htmlspecialchars((string)(($c2['name_ru'] ?? '') !== '' ? $c2['name_ru'] : $c2['name_raw'])) ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
                <td style="white-space:nowrap"><button class="btn btn-sm btn-secondary" onclick="saveCat(<?= $cid ?>)">Сохранить</button> <span class="cat-status" style="font-size:.8rem"></span></td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($categories)): ?><tr><td colspan="8" style="text-align:center;color:#9ca3af;padding:1rem">Нет категорий</td></tr><?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<!-- СОЗДАТЬ -->
<div class="card" style="padding:1rem;margin-bottom:1rem">
    <div style="font-weight:600;margin-bottom:.6rem">Создать свою категорию</div>
    <div style="display:flex;gap:.6rem;align-items:flex-end;flex-wrap:wrap">
        <div><label>Название (RU)</label><br><input type="text" id="newCatName" placeholder="напр. Кальян"></div>
        <div><label>Цех</label><br>
            <select id="newCatWs">
                <?php foreach ($workshops as $w): ?>
                    <option value="<?= (int)$w['id'] ?>"><?= htmlspecialchars((string)($w['name_ru'] !== '' ? $w['name_ru'] : $w['name_raw'])) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div><label>Порядок</label><br><input type="number" id="newCatSort" value="0" style="width:72px"></div>
        <button class="btn btn-primary" onclick="createCat()">Создать</button>
        <span id="createStatus" style="font-size:.8rem"></span>
    </div>
</div>

<script>
async function postForm(ajax, data, statusEl) {
    if (statusEl) { statusEl.textContent = '…'; statusEl.style.color = '#6b7280'; }
    try {
        const r = await fetch('?ajax=' + ajax, {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: new URLSearchParams(data)});
        const d = await r.json();
        if (statusEl) { statusEl.textContent = d.ok ? '✅' : ('❌ ' + (d.error || 'ошибка')); statusEl.style.color = d.ok ? '#065f46' : '#991b1b'; }
        return d;
    } catch (e) { if (statusEl) { statusEl.textContent = '❌ ' + e.message; statusEl.style.color = '#991b1b'; } return {ok:false}; }
}
function saveWs(id) {
    const row = document.querySelector('tr[data-ws="' + id + '"]');
    postForm('ws_save', {id: id, name_ru: row.querySelector('.ws-name').value, show_on_site: row.querySelector('.ws-show').checked ? 1 : 0, sort_order: row.querySelector('.ws-sort').value}, row.querySelector('.ws-status'));
}
function saveCat(id) {
    const row = document.querySelector('tr[data-cat="' + id + '"]');
    postForm('cat_save', {id: id, name_ru: row.querySelector('.cat-name').value, workshop_id: row.querySelector('.cat-ws').value, show_on_site: row.querySelector('.cat-show').checked ? 1 : 0, sort_order: row.querySelector('.cat-sort').value}, row.querySelector('.cat-status'));
}
async function mergeCat(fromId, sel) {
    const toId = parseInt(sel.value, 10) || 0;
    if (!toId) return;
    const toName = sel.options[sel.selectedIndex].text.trim();
    const row = document.querySelector('tr[data-cat="' + fromId + '"]');
    const fromName = (row.querySelector('.cat-name').value || '').trim() || ('#' + fromId);
    if (!confirm('Перенести все блюда из «' + fromName + '» в «' + toName + '» и скрыть «' + fromName + '»?')) { sel.value = '0'; return; }
    const d = await postForm('cat_merge', {from_id: fromId, to_id: toId}, row.querySelector('.cat-status'));
    if (d.ok) {
        row.querySelector('.cat-status').textContent = '✅ перенесено: ' + (d.moved ?? 0);
        setTimeout(() => location.reload(), 800);
    } else {
        sel.value = '0';
    }
}
async function createCat() {
    const st = document.getElementById('createStatus');
    const d = await postForm('cat_create', {name_ru: document.getElementById('newCatName').value, workshop_id: document.getElementById('newCatWs').value, sort_order: document.getElementById('newCatSort').value}, st);
    if (d.ok) { st.textContent = '✅ создано, обновляю…'; setTimeout(() => location.reload(), 600); }
}
</script>
