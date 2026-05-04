<div style="margin-top: 14px;">
        <a href="?tab=menu&view=list" style="text-decoration:none; font-weight:600; color:var(--accent);">← Назад к списку</a>
    </div>

    <form method="POST" style="margin-top: 14px;">
        <input type="hidden" name="poster_id" value="<?= (int)$menuEdit['poster_id'] ?>">
        <?php
            $posterPrice = $menuEdit['price_raw'] ?? null;
            $posterCost = $menuEdit['cost_raw'] ?? null;
            $posterStation = (string)($menuEdit['station_name'] ?? '');
            $posterCategory = (string)($menuEdit['main_category_name'] ?? '');
            $posterSubCategory = (string)($menuEdit['sub_category_name'] ?? '');
            $photo = '';
            $photoOrigin = '';
            $raw = $menuEdit['raw_json'] ?? null;
            if (is_string($raw)) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $photo = (string)($decoded['photo'] ?? '');
                    $photoOrigin = (string)($decoded['photo_origin'] ?? '');
                }
            }
            $formatMoney = function ($v): string {
                if ($v === null || $v === '') return '—';
                if (is_numeric($v)) {
                    $n = (float)$v;
                    if (abs($n - round($n)) < 0.00001) {
                        return number_format((int)round($n), 0, '.', ' ');
                    }
                    return number_format($n, 2, '.', ' ');
                }
                return (string)$v;
            };
            $selectedCategoryId = (int)($menuEdit['category_id'] ?? 0);
            $workshopNameById = [];
            foreach ($menuWorkshops as $w) {
                $wid = (int)($w['id'] ?? 0);
                if ($wid <= 0) continue;
                $workshopNameById[$wid] = $stripNumberPrefix((string)($w['name_ru'] ?? $w['name_raw']));
            }
            $categoriesByWorkshop = [];
            foreach ($menuCategories as $cat) {
                $wid = (int)($cat['workshop_id'] ?? 0);
                if (!isset($categoriesByWorkshop[$wid])) {
                    $categoriesByWorkshop[$wid] = [];
                }
                $categoriesByWorkshop[$wid][] = $cat;
            }
        ?>

        <div style="border:1px solid #eee; border-radius: 10px; padding: 14px;">
            <div class="muted">Данные из Poster (read-only)</div>
            <div style="margin-top: 8px; line-height: 1.8;">
                <div><b>Poster ID:</b> <?= (int)$menuEdit['poster_id'] ?></div>
                <div><b>Название Poster:</b> <?= htmlspecialchars((string)$menuEdit['name_raw']) ?></div>
                <div><b>Станция Poster:</b> <?= htmlspecialchars($posterStation !== '' ? $posterStation : '—') ?></div>
                <div><b>Категория Poster:</b> <?= htmlspecialchars($posterCategory !== '' ? $posterCategory : '—') ?></div>
                <div><b>Подкатегория Poster:</b> <?= htmlspecialchars($posterSubCategory !== '' ? $posterSubCategory : '—') ?></div>
                <div><b>Цена:</b> <?= htmlspecialchars($formatMoney($posterPrice)) ?> <span class="muted">VND</span></div>
                <div><b>Cost:</b> <?= htmlspecialchars($formatMoney($posterCost)) ?> <span class="muted">VND</span></div>
                <div><b>Active:</b> <?= (int)$menuEdit['is_active'] === 1 ? 'yes' : 'no' ?></div>
                <?php if ($photo !== '' || $photoOrigin !== ''): ?>
                    <div><b>Фото Poster:</b> <?= htmlspecialchars($photo !== '' ? $photo : $photoOrigin) ?></div>
                <?php endif; ?>
            </div>
        </div>

        <div style="margin-top: 12px; border:1px solid #eee; border-radius: 10px; padding: 14px;">
            <div class="muted">Общее (не зависит от языка)</div>
            <div class="settings-grid" style="grid-template-columns: 2fr 2fr 1fr 1fr; margin-top: 10px;">
                <div class="form-group">
                    <label>Категория</label>
                    <select name="category_id">
                        <option value="">—</option>
                        <?php foreach ($menuWorkshops as $w): ?>
                            <?php
                                $wid = (int)($w['id'] ?? 0);
                                $wlabel = $stripNumberPrefix((string)($w['name_ru'] ?? $w['name_raw']));
                                $cats = $categoriesByWorkshop[$wid] ?? [];
                                if ($wid <= 0 || empty($cats)) continue;
                            ?>
                            <optgroup label="<?= htmlspecialchars($wlabel !== '' ? $wlabel : ('workshop ' . $wid)) ?>">
                                <?php foreach ($cats as $c): ?>
                                    <?php $cid = (int)($c['id'] ?? 0); $clabel = $stripNumberPrefix((string)($c['name_ru'] ?? $c['name_raw'])); ?>
                                    <option value="<?= $cid ?>" <?= $selectedCategoryId === $cid ? 'selected' : '' ?>><?= htmlspecialchars($clabel !== '' ? $clabel : ('category ' . $cid)) ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Картинка (Image URL)</label>
                    <input name="image_url" value="<?= htmlspecialchars((string)($menuEdit['image_url'] ?? '')) ?>" />
                </div>
                <div class="form-group">
                    <label>Порядок сортировки</label>
                    <input type="number" name="sort_order" value="<?= (int)($menuEdit['sort_order'] ?? 0) ?>" />
                </div>
                <div class="form-group">
                    <label style="display:block;">Опубликовано</label>
                    <label style="display:flex; align-items:center; gap:8px; font-size: 14px; margin-top: 10px;">
                        <input type="checkbox" name="is_published" value="1" <?= !empty($menuEdit['is_published']) && (int)$menuEdit['is_active'] === 1 ? 'checked' : '' ?> <?= (int)$menuEdit['is_active'] === 1 ? '' : 'disabled' ?>>
                    </label>
                    <?php if ((int)$menuEdit['is_active'] === 0): ?>
                        <div class="muted" style="margin-top:6px;">Не найдено в Poster: публикация запрещена.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="settings-grid" style="grid-template-columns: 1fr 1fr 1fr 1fr; margin-top: 12px;">
            <div>
                <h4 style="margin: 0 0 10px;">RU</h4>
                <div class="form-group">
                    <label>Название</label>
                    <input name="ru_title" value="<?= htmlspecialchars((string)($menuEdit['ru_title'] ?? '')) ?>" />
                </div>
                <div class="form-group">
                    <label>Описание</label>
                    <textarea name="ru_description" rows="8"><?= htmlspecialchars((string)($menuEdit['ru_description'] ?? '')) ?></textarea>
                </div>
            </div>

            <div>
                <h4 style="margin: 0 0 10px;">EN</h4>
                <div class="form-group">
                    <label>Title</label>
                    <input name="en_title" value="<?= htmlspecialchars((string)($menuEdit['en_title'] ?? '')) ?>" />
                </div>
                <div class="form-group">
                    <label>Description</label>
                    <textarea name="en_description" rows="8"><?= htmlspecialchars((string)($menuEdit['en_description'] ?? '')) ?></textarea>
                </div>
            </div>

            <div>
                <h4 style="margin: 0 0 10px;">VN</h4>
                <div class="form-group">
                    <label>Tên</label>
                    <input name="vn_title" value="<?= htmlspecialchars((string)($menuEdit['vn_title'] ?? '')) ?>" />
                </div>
                <div class="form-group">
                    <label>Mô tả</label>
                    <textarea name="vn_description" rows="8"><?= htmlspecialchars((string)($menuEdit['vn_description'] ?? '')) ?></textarea>
                </div>
            </div>

            <div>
                <h4 style="margin: 0 0 10px;">KO</h4>
                <div class="form-group">
                    <label>이름</label>
                    <input name="ko_title" value="<?= htmlspecialchars((string)($menuEdit['ko_title'] ?? '')) ?>" />
                </div>
                <div class="form-group">
                    <label>설명</label>
                    <textarea name="ko_description" rows="8"><?= htmlspecialchars((string)($menuEdit['ko_description'] ?? '')) ?></textarea>
                </div>
            </div>
        </div>
        <div style="margin-top: 14px;">
            <button type="submit" name="save_menu_item">Сохранить блюдо</button>
        </div>
    </form>

