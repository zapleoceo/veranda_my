<div class="card">
            <div class="menu-actions"><div class="left">
                    <h3 style="margin:0;">Меню</h3>
                    <form method="POST" style="margin:0;">
                        <button type="submit" name="sync_menu" title="Синк из Poster: только обновляет слепок poster_menu_items и справочники по poster_id. Не трогает переводы и ручные привязки/публикацию.">Обновить меню из Poster</button>
                    </form>
                    <form method="POST" style="margin:0;">
                        <button type="submit" name="autofill_menu" title="Разовая привязка по ID: связывает menu_items.category_id и menu_categories.workshop_id из данных Poster там, где сейчас пусто. Не трогает переводы и ручные значения.">Привязать ID (разово)</button>
                    </form>
                    <a href="?tab=menu&export=csv" style="text-decoration:none; font-weight:600; color:var(--accent);" title="Выгрузка CSV со всеми активными позициями и текущими переводами/категориями.">CSV меню</a>
                    <a href="?tab=menu&export=categories_csv" style="text-decoration:none; font-weight:600; color:var(--accent);" title="Выгрузка CSV справочников цехов и категорий с переводами.">CSV категорий</a>
                    <?php if (!empty($menuSyncMeta['last_sync_at'])): ?>
                        <span class="muted">Последняя синхронизация: <span class="js-local-dt" data-iso="<?= htmlspecialchars($menuSyncAtIso) ?>"><?= htmlspecialchars($menuSyncMeta['last_sync_at']) ?></span></span>
                    <?php endif; ?>
                </div>
            </div>
            <?php if (!empty($menuSyncMeta['last_sync_error'])): ?>
                <div style="margin-top:12px;" class="error"><?= htmlspecialchars($menuSyncMeta['last_sync_error']) ?></div>
            <?php endif; ?>

            <?php if ($menuView === 'edit'): ?>
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
            
<?php else: ?>

                <?php
                    $filterWorkshop = ($_GET['workshop_id'] ?? '') !== '' ? (int)$_GET['workshop_id'] : null;
                    $filterCategory = ($_GET['category_id'] ?? '') !== '' ? (int)$_GET['category_id'] : null;
                    $filterQ = trim((string)($_GET['q'] ?? ''));
                    if (array_key_exists('status', $_GET)) {
                        $filterStatus = trim((string)($_GET['status'] ?? ''));
                    } else {
                        $filterStatus = 'published';
                    }
                    $sort = strtolower(trim((string)($_GET['sort'] ?? 'main_sort')));
                    if ($sort === 'station') {
                        $sort = 'poster_station';
                    }
                    if ($sort === 'poster_category') {
                        $sort = 'poster_station';
                    }
                    if ($sort === 'poster_subcategory') {
                        $sort = 'poster_category';
                    }
                    if (preg_match('/^adapted_category_(ru|en|vn|ko)$/', $sort, $m)) {
                        $sort = 'adapted_workshop_' . $m[1];
                    }
                    if (preg_match('/^adapted_subcategory_(ru|en|vn|ko)$/', $sort, $m)) {
                        $sort = 'adapted_category_' . $m[1];
                    }
                    $dir = strtolower(trim((string)($_GET['dir'] ?? 'asc')));
                    if (!in_array($dir, ['asc', 'desc'], true)) {
                        $dir = 'asc';
                    }
                    $colsParam = trim((string)($_GET['cols'] ?? ''));
                    if ($colsParam !== '') {
                        $parts = array_filter(array_map('trim', explode(',', $colsParam)), static fn($v) => $v !== '');
                        $mapped = [];
                        foreach ($parts as $c) {
                            if ($c === 'poster_category') $c = 'poster_station';
                            if ($c === 'poster_subcategory') $c = 'poster_category';
                            if (preg_match('/^adapted_category_(ru|en|vn|ko)$/', $c, $m)) $c = 'adapted_workshop_' . $m[1];
                            if (preg_match('/^adapted_subcategory_(ru|en|vn|ko)$/', $c, $m)) $c = 'adapted_category_' . $m[1];
                            $mapped[$c] = true;
                        }
                        $colsHidden = implode(',', array_keys($mapped));
                    } else {
                        $colsHidden = '';
                    }
                    $buildSortHref = function (string $key) use ($sort, $dir): string {
                        $qs = $_GET;
                        $qs['tab'] = 'menu';
                        $qs['view'] = 'list';
                        $qs['page'] = 1;
                        if ($sort === $key) {
                            $qs['dir'] = $dir === 'asc' ? 'desc' : 'asc';
                        } else {
                            $qs['dir'] = 'asc';
                        }
                        $qs['sort'] = $key;
                        return 'admin.php?' . http_build_query($qs);
                    };
                    $sortArrow = function (string $key) use ($sort, $dir): string {
                        if ($sort !== $key) return '';
                        return $dir === 'asc' ? '▲' : '▼';
                    };
                    $columnDefs = [
                        'poster_id' => ['label' => 'Poster ID', 'default' => true],
                        'title_ru' => ['label' => 'Название RU', 'default' => true],
                        'title_en' => ['label' => 'Название EN', 'default' => false],
                        'title_vn' => ['label' => 'Название VN', 'default' => false],
                        'title_ko' => ['label' => 'Название KO', 'default' => false],
                        'price' => ['label' => 'Цена', 'default' => true],
                        'poster_station' => ['label' => 'Станция Poster', 'default' => true],
                        'poster_workshop' => ['label' => 'Цех Poster', 'default' => false],
                        'poster_category' => ['label' => 'Категория Poster', 'default' => true],
                        'adapted_workshop_ru' => ['label' => 'Цех адапт. RU', 'default' => false],
                        'adapted_workshop_en' => ['label' => 'Цех адапт. EN', 'default' => false],
                        'adapted_workshop_vn' => ['label' => 'Цех адапт. VN', 'default' => false],
                        'adapted_workshop_ko' => ['label' => 'Цех адапт. KO', 'default' => false],
                        'adapted_category_ru' => ['label' => 'Категория адапт. RU', 'default' => false],
                        'adapted_category_en' => ['label' => 'Категория адапт. EN', 'default' => false],
                        'adapted_category_vn' => ['label' => 'Категория адапт. VN', 'default' => false],
                        'adapted_category_ko' => ['label' => 'Категория адапт. KO', 'default' => false],
                        'status' => ['label' => 'Статус', 'default' => true],
                    ];
                    $pages = max(1, (int)ceil($menuTotal / $menuPerPage));
                ?>
                <form method="GET" class="menu-filters">
                    <input type="hidden" name="tab" value="menu">
                    <input type="hidden" name="view" value="list">
                    <input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>">
                    <input type="hidden" name="dir" value="<?= htmlspecialchars($dir) ?>">
                    <input type="hidden" name="cols" value="<?= htmlspecialchars($colsHidden) ?>">
                    <div class="form-group">
                        <label>Цех</label>
                        <select name="workshop_id">
                            <option value="">Все</option>
                            <?php foreach ($menuWorkshops as $w): ?>
                                <?php $id = (int)$w['id']; $name = $stripNumberPrefix((string)($w['name_ru'] ?? $w['name_raw'])); ?>
                                <option value="<?= $id ?>" <?= $filterWorkshop === $id ? 'selected' : '' ?>><?= htmlspecialchars($name) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Категория</label>
                        <select name="category_id">
                            <option value="">Все</option>
                            <?php foreach ($menuCategories as $c): ?>
                                <?php
                                    $id = (int)$c['id'];
                                    $catName = $stripNumberPrefix((string)($c['name_ru'] ?? $c['name_raw']));
                                    $wid = (int)($c['workshop_id'] ?? 0);
                                    $wName = '';
                                    foreach ($menuWorkshops as $w) {
                                        if ((int)($w['id'] ?? 0) === $wid) {
                                            $wName = $stripNumberPrefix((string)($w['name_ru'] ?? $w['name_raw']));
                                            break;
                                        }
                                    }
                                    $label = $wName !== '' ? ($wName . ' / ' . $catName) : $catName;
                                ?>
                                <option value="<?= $id ?>" <?= $filterCategory === $id ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Поиск</label>
                        <input name="q" value="<?= htmlspecialchars($filterQ) ?>" placeholder="name_raw / RU / EN / VN / KO" />
                    </div>
                    <div class="form-group">
                        <label>Статус</label>
                        <select name="status">
                            <option value="" <?= $filterStatus === '' ? 'selected' : '' ?>>Все</option>
                            <option value="published" <?= $filterStatus === 'published' ? 'selected' : '' ?>>Опубликовано</option>
                            <option value="hidden" <?= $filterStatus === 'hidden' ? 'selected' : '' ?>>Скрыто</option>
                            <option value="not_found" <?= $filterStatus === 'not_found' ? 'selected' : '' ?>>Не найдено в Poster</option>
                            <option value="unadapted" <?= $filterStatus === 'unadapted' ? 'selected' : '' ?>>Неадаптировано</option>
                        </select>
                    </div>
                    <div style="grid-column: 1 / -1; display:flex; gap:10px; align-items:center;">
                        <button type="submit">Применить</button>
                        <a href="?tab=menu&view=list" style="text-decoration:none; color:var(--muted); font-weight:600;">Сбросить</a>
                        <span class="muted">Всего: <?= (int)$menuTotal ?></span>
                    </div>
                </form>

                <details style="margin-top: 12px;">
                    <summary style="cursor:pointer; font-weight:700; color:var(--accent);">Поля таблицы</summary>
                    <div style="margin-top: 10px; display:flex; flex-wrap:wrap; gap: 10px;">
                        <?php foreach ($columnDefs as $key => $def): ?>
                            <label style="display:flex; align-items:center; gap: 8px; font-size: 13px; border:1px solid #eee; padding: 8px 10px; border-radius: 999px; background:#fafafa;">
                                <input type="checkbox" class="col-toggle" data-col="<?= htmlspecialchars($key) ?>" data-default="<?= !empty($def['default']) ? '1' : '0' ?>">
                                <?= htmlspecialchars($def['label']) ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <div class="muted" style="margin-top: 8px;">Выбор сохраняется в браузере и в URL (cols=...).</div>
                </details>

                <div class="table-wrap">
                <table class="menu-table">
                    <thead>
                        <tr>
                            <th data-col="poster_id"><a class="sort-link" href="<?= htmlspecialchars($buildSortHref('poster_id')) ?>">Poster ID <span class="sort-arrow"><?= $sortArrow('poster_id') ?></span></a></th>
                            <th data-col="title_ru"><a class="sort-link" href="<?= htmlspecialchars($buildSortHref('title_ru')) ?>">Название RU <span class="sort-arrow"><?= $sortArrow('title_ru') ?></span></a></th>
                            <th data-col="title_en"><a class="sort-link" href="<?= htmlspecialchars($buildSortHref('title_en')) ?>">EN <span class="sort-arrow"><?= $sortArrow('title_en') ?></span></a></th>
                            <th data-col="title_vn"><a class="sort-link" href="<?= htmlspecialchars($buildSortHref('title_vn')) ?>">VN <span class="sort-arrow"><?= $sortArrow('title_vn') ?></span></a></th>
                            <th data-col="title_ko"><a class="sort-link" href="<?= htmlspecialchars($buildSortHref('title_ko')) ?>">KO <span class="sort-arrow"><?= $sortArrow('title_ko') ?></span></a></th>
                            <th data-col="price"><a class="sort-link" href="<?= htmlspecialchars($buildSortHref('price')) ?>">Цена <span class="sort-arrow"><?= $sortArrow('price') ?></span></a></th>
                            <th data-col="poster_station"><a class="sort-link" href="<?= htmlspecialchars($buildSortHref('poster_station')) ?>">Станция Poster <span class="sort-arrow"><?= $sortArrow('poster_station') ?></span></a></th>
                            <th data-col="poster_workshop"><a class="sort-link" href="<?= htmlspecialchars($buildSortHref('poster_workshop')) ?>">Цех Poster <span class="sort-arrow"><?= $sortArrow('poster_workshop') ?></span></a></th>
                            <th data-col="poster_category"><a class="sort-link" href="<?= htmlspecialchars($buildSortHref('poster_category')) ?>">Категория Poster <span class="sort-arrow"><?= $sortArrow('poster_category') ?></span></a></th>
                            <th data-col="adapted_workshop_ru"><a class="sort-link" href="<?= htmlspecialchars($buildSortHref('adapted_workshop_ru')) ?>">Цех адапт. RU <span class="sort-arrow"><?= $sortArrow('adapted_workshop_ru') ?></span></a></th>
                            <th data-col="adapted_workshop_en"><a class="sort-link" href="<?= htmlspecialchars($buildSortHref('adapted_workshop_en')) ?>">Цех адапт. EN <span class="sort-arrow"><?= $sortArrow('adapted_workshop_en') ?></span></a></th>
                            <th data-col="adapted_workshop_vn"><a class="sort-link" href="<?= htmlspecialchars($buildSortHref('adapted_workshop_vn')) ?>">Цех адапт. VN <span class="sort-arrow"><?= $sortArrow('adapted_workshop_vn') ?></span></a></th>
                            <th data-col="adapted_workshop_ko"><a class="sort-link" href="<?= htmlspecialchars($buildSortHref('adapted_workshop_ko')) ?>">Цех адапт. KO <span class="sort-arrow"><?= $sortArrow('adapted_workshop_ko') ?></span></a></th>
                            <th data-col="adapted_category_ru"><a class="sort-link" href="<?= htmlspecialchars($buildSortHref('adapted_category_ru')) ?>">Категория адапт. RU <span class="sort-arrow"><?= $sortArrow('adapted_category_ru') ?></span></a></th>
                            <th data-col="adapted_category_en"><a class="sort-link" href="<?= htmlspecialchars($buildSortHref('adapted_category_en')) ?>">Категория адапт. EN <span class="sort-arrow"><?= $sortArrow('adapted_category_en') ?></span></a></th>
                            <th data-col="adapted_category_vn"><a class="sort-link" href="<?= htmlspecialchars($buildSortHref('adapted_category_vn')) ?>">Категория адапт. VN <span class="sort-arrow"><?= $sortArrow('adapted_category_vn') ?></span></a></th>
                            <th data-col="adapted_category_ko"><a class="sort-link" href="<?= htmlspecialchars($buildSortHref('adapted_category_ko')) ?>">Категория адапт. KO <span class="sort-arrow"><?= $sortArrow('adapted_category_ko') ?></span></a></th>
                            <th data-col="status"><a class="sort-link" href="<?= htmlspecialchars($buildSortHref('status')) ?>">Статус <span class="sort-arrow"><?= $sortArrow('status') ?></span></a></th>
                            <th>Скрыть</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($menuItems as $it): ?>
                            <?php
                                $isActive = (int)$it['is_active'] === 1;
                                $isPublished = (int)($it['is_published'] ?? 0) === 1;
                                $ruTitle = trim((string)($it['ru_title'] ?? ''));
                                $enTitle = trim((string)($it['en_title'] ?? ''));
                                $vnTitle = trim((string)($it['vn_title'] ?? ''));
                                $koTitle = trim((string)($it['ko_title'] ?? ''));
                                $isUnadapted = ($ruTitle === '' || $enTitle === '' || $vnTitle === '');
                                $posterStation = trim((string)($it['station_name'] ?? ''));
                                if ($posterStation === '' && (int)($it['station_id'] ?? 0) > 0) {
                                    $posterStation = 'workshop ' . (int)$it['station_id'];
                                }
                                $posterWorkshop = $stripNumberPrefix((string)($it['main_category_name'] ?? ''));
                                $posterCategory = $stripNumberPrefix((string)($it['sub_category_name'] ?? ''));
                                $statusPills = [];
                                $statusPills[] = $isActive ? '<span class="pill ok">Poster</span>' : '<span class="pill bad">Не найдено</span>';
                                $statusPills[] = $isPublished && $isActive ? '<span class="pill ok">Опублик.</span>' : '<span class="pill warn">Скрыто</span>';
                                if ($isUnadapted) $statusPills[] = '<span class="pill warn">!</span>';
                                $hideChecked = !$isPublished || !$isActive;
                            ?>
                            <tr>
                                <td data-col="poster_id"><?= (int)$it['poster_id'] ?></td>
                                <td data-col="title_ru">
                                    <div style="font-weight:700;"><?= htmlspecialchars($ruTitle !== '' ? $ruTitle : (string)$it['name_raw']) ?></div>
                                    <div class="muted"><?= htmlspecialchars((string)$it['name_raw']) ?></div>
                                </td>
                                <td data-col="title_en"><?= htmlspecialchars($enTitle) ?></td>
                                <td data-col="title_vn"><?= htmlspecialchars($vnTitle) ?></td>
                                <td data-col="title_ko"><?= htmlspecialchars($koTitle) ?></td>
                                <td data-col="price"><?= htmlspecialchars((string)($it['price_raw'] ?? '')) ?></td>
                                <td data-col="poster_station"><?= htmlspecialchars($posterStation) ?></td>
                                <td data-col="poster_workshop"><?= htmlspecialchars($posterWorkshop) ?></td>
                                <td data-col="poster_category"><?= htmlspecialchars($posterCategory) ?></td>
                                <td data-col="adapted_workshop_ru"><?= htmlspecialchars((string)($it['adapted_workshop_ru'] ?? '')) ?></td>
                                <td data-col="adapted_workshop_en"><?= htmlspecialchars((string)($it['adapted_workshop_en'] ?? '')) ?></td>
                                <td data-col="adapted_workshop_vn"><?= htmlspecialchars((string)($it['adapted_workshop_vn'] ?? '')) ?></td>
                                <td data-col="adapted_workshop_ko"><?= htmlspecialchars((string)($it['adapted_workshop_ko'] ?? '')) ?></td>
                                <td data-col="adapted_category_ru"><?= htmlspecialchars((string)($it['adapted_category_ru'] ?? '')) ?></td>
                                <td data-col="adapted_category_en"><?= htmlspecialchars((string)($it['adapted_category_en'] ?? '')) ?></td>
                                <td data-col="adapted_category_vn"><?= htmlspecialchars((string)($it['adapted_category_vn'] ?? '')) ?></td>
                                <td data-col="adapted_category_ko"><?= htmlspecialchars((string)($it['adapted_category_ko'] ?? '')) ?></td>
                                <td data-col="status"><?= implode(' ', $statusPills) ?></td>
                                <td>
                                    <input type="checkbox"
                                           class="publish-toggle"
                                           data-poster-id="<?= (int)$it['poster_id'] ?>"
                                           <?= $hideChecked ? 'checked' : '' ?>
                                           <?= !$isActive ? 'disabled' : '' ?>>
                                </td>
                                <td>
                                    <a href="?tab=menu&view=edit&poster_id=<?= (int)$it['poster_id'] ?>" style="text-decoration:none; color:var(--accent); font-weight:800;" title="Редактировать">&#9998;</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>

                <?php if ($pages > 1): ?>
                    <div style="margin-top: 14px; display:flex; gap:10px; flex-wrap:wrap; align-items:center; justify-content:center;">
                        <?php for ($p=1; $p<=$pages; $p++): ?>
                            <?php
                                $qs = $_GET;
                                $qs['tab'] = 'menu';
                                $qs['view'] = 'list';
                                $qs['page'] = $p;
                                $href = 'admin.php?' . http_build_query($qs);
                            ?>
                            <a href="<?= htmlspecialchars($href) ?>" class="<?= $p === $menuPage ? 'pill ok' : 'pill warn' ?>" style="text-decoration:none;"><?= $p ?></a>
                        <?php endfor; ?>
                    </div>
                <?php endif; ?>

                
            <?php endif; ?>
        </div>
