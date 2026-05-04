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
        <select name="workshop_id" class="menu-filter-select" data-autowidth="1">
            <option value="">Все</option>
            <?php foreach ($menuWorkshops as $w): ?>
                <?php $id = (int)$w['id']; $name = $stripNumberPrefix((string)($w['name_ru'] ?? $w['name_raw'])); ?>
                <option value="<?= $id ?>" <?= $filterWorkshop === $id ? 'selected' : '' ?>><?= htmlspecialchars($name) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-group">
        <label>Категория</label>
        <select name="category_id" class="menu-filter-select" data-autowidth="1">
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
    <div class="form-group menu-filter-grow">
        <label>Поиск</label>
        <input name="q" value="<?= htmlspecialchars($filterQ) ?>" placeholder="name_raw / RU / EN / VN / KO" />
    </div>
    <div class="form-group">
        <label>Статус</label>
        <select name="status" class="menu-filter-select" data-autowidth="1">
            <option value="" <?= $filterStatus === '' ? 'selected' : '' ?>>Все</option>
            <option value="published" <?= $filterStatus === 'published' ? 'selected' : '' ?>>Опубликовано</option>
            <option value="hidden" <?= $filterStatus === 'hidden' ? 'selected' : '' ?>>Скрыто</option>
            <option value="not_found" <?= $filterStatus === 'not_found' ? 'selected' : '' ?>>Не найдено в Poster</option>
            <option value="unadapted" <?= $filterStatus === 'unadapted' ? 'selected' : '' ?>>Неадаптировано</option>
        </select>
    </div>
    <div class="menu-filters-actions">
        <button type="submit">Применить</button>
        <a href="?tab=menu&view=list" class="menu-reset-link">Сбросить</a>
        <span class="muted">Всего: <?= (int)$menuTotal ?></span>
    </div>
</form>

<details style="margin-top: 12px;">
    <summary style="cursor:pointer; font-weight:700; color:var(--accent);">Поля таблицы</summary>
    <div style="margin-top: 10px; display:flex; flex-wrap:wrap; gap: 10px;">
        <?php foreach ($columnDefs as $key => $def): ?>
            <label class="col-chip">
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
            <th data-col="poster_id"><a class="sort-link js-sort" data-sort-key="poster_id" href="<?= htmlspecialchars($buildSortHref('poster_id')) ?>">Poster ID <span class="sort-arrow"></span></a></th>
            <th data-col="title_ru"><a class="sort-link js-sort" data-sort-key="title_ru" href="<?= htmlspecialchars($buildSortHref('title_ru')) ?>">Название RU <span class="sort-arrow"></span></a></th>
            <th data-col="title_en"><a class="sort-link js-sort" data-sort-key="title_en" href="<?= htmlspecialchars($buildSortHref('title_en')) ?>">EN <span class="sort-arrow"></span></a></th>
            <th data-col="title_vn"><a class="sort-link js-sort" data-sort-key="title_vn" href="<?= htmlspecialchars($buildSortHref('title_vn')) ?>">VN <span class="sort-arrow"></span></a></th>
            <th data-col="title_ko"><a class="sort-link js-sort" data-sort-key="title_ko" href="<?= htmlspecialchars($buildSortHref('title_ko')) ?>">KO <span class="sort-arrow"></span></a></th>
            <th data-col="price"><a class="sort-link js-sort" data-sort-key="price" href="<?= htmlspecialchars($buildSortHref('price')) ?>">Цена <span class="sort-arrow"></span></a></th>
            <th data-col="poster_station"><a class="sort-link js-sort" data-sort-key="poster_station" href="<?= htmlspecialchars($buildSortHref('poster_station')) ?>">Станция Poster <span class="sort-arrow"></span></a></th>
            <th data-col="poster_workshop"><a class="sort-link js-sort" data-sort-key="poster_workshop" href="<?= htmlspecialchars($buildSortHref('poster_workshop')) ?>">Цех Poster <span class="sort-arrow"></span></a></th>
            <th data-col="poster_category"><a class="sort-link js-sort" data-sort-key="poster_category" href="<?= htmlspecialchars($buildSortHref('poster_category')) ?>">Категория Poster <span class="sort-arrow"></span></a></th>
            <th data-col="adapted_workshop_ru"><a class="sort-link js-sort" data-sort-key="adapted_workshop_ru" href="<?= htmlspecialchars($buildSortHref('adapted_workshop_ru')) ?>">Цех адапт. RU <span class="sort-arrow"></span></a></th>
            <th data-col="adapted_workshop_en"><a class="sort-link js-sort" data-sort-key="adapted_workshop_en" href="<?= htmlspecialchars($buildSortHref('adapted_workshop_en')) ?>">Цех адапт. EN <span class="sort-arrow"></span></a></th>
            <th data-col="adapted_workshop_vn"><a class="sort-link js-sort" data-sort-key="adapted_workshop_vn" href="<?= htmlspecialchars($buildSortHref('adapted_workshop_vn')) ?>">Цех адапт. VN <span class="sort-arrow"></span></a></th>
            <th data-col="adapted_workshop_ko"><a class="sort-link js-sort" data-sort-key="adapted_workshop_ko" href="<?= htmlspecialchars($buildSortHref('adapted_workshop_ko')) ?>">Цех адапт. KO <span class="sort-arrow"></span></a></th>
            <th data-col="adapted_category_ru"><a class="sort-link js-sort" data-sort-key="adapted_category_ru" href="<?= htmlspecialchars($buildSortHref('adapted_category_ru')) ?>">Категория адапт. RU <span class="sort-arrow"></span></a></th>
            <th data-col="adapted_category_en"><a class="sort-link js-sort" data-sort-key="adapted_category_en" href="<?= htmlspecialchars($buildSortHref('adapted_category_en')) ?>">Категория адапт. EN <span class="sort-arrow"></span></a></th>
            <th data-col="adapted_category_vn"><a class="sort-link js-sort" data-sort-key="adapted_category_vn" href="<?= htmlspecialchars($buildSortHref('adapted_category_vn')) ?>">Категория адапт. VN <span class="sort-arrow"></span></a></th>
            <th data-col="adapted_category_ko"><a class="sort-link js-sort" data-sort-key="adapted_category_ko" href="<?= htmlspecialchars($buildSortHref('adapted_category_ko')) ?>">Категория адапт. KO <span class="sort-arrow"></span></a></th>
            <th data-col="status"><a class="sort-link js-sort" data-sort-key="status" href="<?= htmlspecialchars($buildSortHref('status')) ?>">Статус <span class="sort-arrow"></span></a></th>
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
                $statusParts = [];
                $statusParts[] = $isActive ? '<span class="status-ind status-ok">Poster</span>' : '<span class="status-ind status-bad">Не найдено</span>';
                $statusParts[] = ($isPublished && $isActive) ? '<span class="status-ind status-ok">Опублик.</span>' : '<span class="status-ind status-warn">Скрыто</span>';
                if ($isUnadapted) $statusParts[] = '<span class="status-ind status-warn">!</span>';
                $hideChecked = !$isPublished || !$isActive;
                $posterIdInt = (int)$it['poster_id'];
                $priceNum = is_numeric($it['price_raw'] ?? null) ? (float)$it['price_raw'] : null;
                $priceText = ($priceNum !== null) ? number_format($priceNum, 0, '.', ' ') : (string)($it['price_raw'] ?? '');
            ?>
            <tr data-poster-id="<?= $posterIdInt ?>">
                <td data-col="poster_id"><?= $posterIdInt ?></td>
                <td data-col="title_ru">
                    <div style="font-weight:700;"><?= htmlspecialchars($ruTitle !== '' ? $ruTitle : (string)$it['name_raw']) ?></div>
                    <div class="muted"><?= htmlspecialchars((string)$it['name_raw']) ?></div>
                </td>
                <td data-col="title_en"><?= htmlspecialchars($enTitle) ?></td>
                <td data-col="title_vn"><?= htmlspecialchars($vnTitle) ?></td>
                <td data-col="title_ko"><?= htmlspecialchars($koTitle) ?></td>
                <td data-col="price"><?= htmlspecialchars($priceText) ?></td>
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
                <td data-col="status" data-status-active="<?= $isActive ? '1' : '0' ?>" data-status-published="<?= $isPublished ? '1' : '0' ?>" data-status-unadapted="<?= $isUnadapted ? '1' : '0' ?>"><?= implode(' ', $statusParts) ?></td>
                <td>
                    <label class="switch" title="Скрыть/Показать">
                        <input type="checkbox"
                               class="publish-toggle"
                               data-poster-id="<?= $posterIdInt ?>"
                               <?= $hideChecked ? 'checked' : '' ?>
                               <?= !$isActive ? 'disabled' : '' ?>>
                        <span class="slider"></span>
                    </label>
                </td>
                <td>
                    <a href="?tab=menu&view=edit&poster_id=<?= $posterIdInt ?>" class="icon-btn js-edit-btn" data-poster-id="<?= $posterIdInt ?>" title="Редактировать">&#9998;</a>
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
