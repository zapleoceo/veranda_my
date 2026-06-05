<?php
$langs = ['ru' => 'RU 🇷🇺', 'en' => 'EN 🇬🇧', 'vn' => 'VN 🇻🇳', 'ko' => 'KO 🇰🇷'];
$tr = $item['translations'] ?? [];
?>
<div style="margin-bottom:.75rem">
    <a href="/admin/menu" class="btn btn-sm btn-secondary">← Назад</a>
</div>

<div class="card" style="max-width:800px">
    <h2><?= htmlspecialchars((string)($item['title_ru'] ?? 'Позиция #' . $item['poster_id'])) ?></h2>
    <div style="font-size:.8rem;color:#6b7280;margin-bottom:1rem">
        Poster ID: <?= (int)$item['poster_id'] ?> &nbsp;|&nbsp;
        Poster-цех: <?= htmlspecialchars((string)($item['workshop_name'] ?? '—')) ?> &nbsp;|&nbsp;
        Poster-категория: <?= htmlspecialchars((string)($item['category_name'] ?? '—')) ?> &nbsp;|&nbsp;
        Цена: <?= number_format((float)($item['price'] ?? 0), 0, '.', ' ') ?> ₫
    </div>

    <?php
    $catsByWs = [];
    foreach (($siteCategories ?? []) as $sc) { $catsByWs[(string)($sc['ws_name'] ?? '')][] = $sc; }
    $curCat = (int)($item['site_category_id'] ?? 0);
    ?>
    <div style="border:1px solid #e5e7eb;border-radius:8px;padding:1rem;margin-bottom:1.25rem">
        <div style="font-weight:600;margin-bottom:.5rem;font-size:.9rem">Размещение на сайте</div>
        <div style="font-size:.8rem;margin-bottom:.6rem">
            Сейчас:
            <?php if ($curCat > 0): ?>
                <b><?= htmlspecialchars((string)($item['site_workshop_name'] ?? '—')) ?> → <?= htmlspecialchars((string)($item['site_category_name'] ?? '—')) ?></b>
            <?php else: ?>
                <span style="color:#b45309">не привязано — на сайте не показывается</span>
            <?php endif; ?>
        </div>
        <label>Категория сайта</label>
        <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">
            <select id="siteCategory" style="flex:1;min-width:240px">
                <option value="0"<?= $curCat === 0 ? ' selected' : '' ?>>— не привязано —</option>
                <?php foreach ($catsByWs as $wsName => $cats): ?>
                    <optgroup label="<?= htmlspecialchars((string)$wsName) ?>">
                        <?php foreach ($cats as $sc): ?>
                            <option value="<?= (int)$sc['id'] ?>"<?= $curCat === (int)$sc['id'] ? ' selected' : '' ?>><?= htmlspecialchars((string)($sc['cat_name'] ?? '')) ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-sm btn-primary" onclick="setCategory()">Привязать</button>
            <span id="catStatus" style="font-size:.8rem"></span>
        </div>
        <div style="font-size:.72rem;color:#9ca3af;margin-top:.4rem">Без категории сайта позицию нельзя опубликовать.</div>
    </div>

    <div style="display:grid;gap:1.25rem">
    <?php foreach ($langs as $lang => $label): ?>
        <div style="border:1px solid #e5e7eb;border-radius:8px;padding:1rem">
            <div style="font-weight:600;margin-bottom:.75rem;font-size:.875rem"><?= $label ?></div>
            <div style="display:grid;gap:.5rem">
                <div>
                    <label>Название</label>
                    <input type="text" id="title_<?= $lang ?>"
                           value="<?= htmlspecialchars((string)($tr[$lang]['title'] ?? $item["title_{$lang}"] ?? '')) ?>">
                </div>
                <div>
                    <label>Описание</label>
                    <textarea id="desc_<?= $lang ?>" rows="2"
                              style="resize:vertical"><?= htmlspecialchars((string)($tr[$lang]['description'] ?? '')) ?></textarea>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
    </div>

    <div style="margin-top:1rem;display:flex;gap:.5rem;align-items:center">
        <button class="btn btn-primary" onclick="saveEdit()">Сохранить</button>
        <label style="display:flex;align-items:center;gap:.4rem;font-size:.875rem;cursor:pointer">
            <input type="checkbox" id="pubToggle" <?= $item['is_published'] ? 'checked' : '' ?>>
            Опубликовано
        </label>
        <span id="saveStatus" style="font-size:.8rem;color:#065f46"></span>
    </div>
</div>

<script>
const posterId = <?= (int)$item['poster_id'] ?>;

async function setCategory() {
    const sel = document.getElementById('siteCategory');
    const st = document.getElementById('catStatus');
    st.textContent = 'Сохраняем...'; st.style.color = '#6b7280';
    try {
        const r = await fetch('?ajax=set_category', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
            body: 'poster_id=' + posterId + '&category_id=' + encodeURIComponent(sel.value)});
        const d = await r.json();
        if (d.ok) {
            st.textContent = '✅ Сохранено'; st.style.color = '#065f46';
            if (sel.value === '0') { const pt = document.getElementById('pubToggle'); if (pt) pt.checked = false; }
        } else { st.textContent = '❌ ' + d.error; st.style.color = '#991b1b'; }
    } catch(e) { st.textContent = '❌ ' + e.message; st.style.color = '#991b1b'; }
}

async function saveEdit() {
    const status = document.getElementById('saveStatus');
    status.textContent = 'Сохраняем...';
    const params = new URLSearchParams({poster_id: posterId});
    ['ru','en','vn','ko'].forEach(l => {
        params.set('title_' + l, document.getElementById('title_' + l).value);
        params.set('desc_' + l, document.getElementById('desc_' + l).value);
    });
    try {
        const r = await fetch('?ajax=save_edit', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: params});
        const d = await r.json();
        status.textContent = d.ok ? '✅ Сохранено' : '❌ ' + d.error;
        status.style.color = d.ok ? '#065f46' : '#991b1b';
    } catch(e) { status.textContent = '❌ ' + e.message; status.style.color = '#991b1b'; }
}

document.getElementById('pubToggle').addEventListener('change', async function() {
    const r = await fetch('?ajax=toggle_publish', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body: 'poster_id=' + posterId + '&publish=' + (this.checked ? 1 : 0)});
    const d = await r.json();
    if (!d.ok) { this.checked = !this.checked; alert('Ошибка: ' + d.error); }
});
</script>
