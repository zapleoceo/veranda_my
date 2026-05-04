<div class="card">
    <?php require __DIR__ . '/menu/_actions.php'; ?>

                         <div style="margin-top: 18px;">
                    <h4 style="margin: 0 0 10px;">Импорт цехов/категорий (CSV)</h4>
                    <div class="muted" style="margin-bottom: 10px;">Формат: Тип;Poster ID;Parent Poster ID;Raw;RU;EN;VN;KO;Отображать;Sort</div>
                    <form method="POST">
                        <textarea name="categories_csv" rows="8" style="width:100%; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace;"><?= htmlspecialchars((string)($_POST['categories_csv'] ?? '')) ?></textarea>
                        <div style="margin-top: 10px;">
                            <button type="submit" name="import_categories_csv">Импортировать категории</button>
                        </div>
                    </form>
                </div>
                <form method="POST" style="margin-top: 18px;">
                    <h4 style="margin: 0 0 10px;">Цехи</h4>
                    <div class="table-wrap">
                    <table class="menu-table">
                        <thead>
                            <tr>
                                <th>Poster ID</th>
                                <th>Raw</th>
                                <th>RU</th>
                                <th>EN</th>
                                <th>VN</th>
                                <th>KO</th>
                                <th>Блюд</th>
                                <th>Отображать</th>
                                <th>Sort</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($menuWorkshops as $c): ?>
                                <tr>
                                    <td><?= (int)$c['poster_id'] ?></td>
                                    <td><?= htmlspecialchars($c['name_raw']) ?></td>
                                    <td><input name="workshop_tr[<?= (int)$c['id'] ?>][ru]" value="<?= htmlspecialchars($c['name_ru'] ?? '') ?>" /></td>
                                    <td><input name="workshop_tr[<?= (int)$c['id'] ?>][en]" value="<?= htmlspecialchars($c['name_en'] ?? '') ?>" /></td>
                                    <td><input name="workshop_tr[<?= (int)$c['id'] ?>][vn]" value="<?= htmlspecialchars($c['name_vn'] ?? '') ?>" /></td>
                                    <td><input name="workshop_tr[<?= (int)$c['id'] ?>][ko]" value="<?= htmlspecialchars($c['name_ko'] ?? '') ?>" /></td>
                                    <?php $cnt = (int)($mainItemCounts[(int)$c['id']] ?? 0); ?>
                                    <td style="width:80px; text-align:right;"><?= $cnt ?></td>
                                    <td style="width:110px; text-align:center;">
                                        <input type="checkbox" name="workshop_show[<?= (int)$c['id'] ?>]" value="1" <?= !empty($c['show_on_site']) ? 'checked' : '' ?>>
                                    </td>
                                    <td style="width:90px;"><input type="number" name="workshop_sort[<?= (int)$c['id'] ?>]" value="<?= (int)$c['sort_order'] ?>" /></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>

                    <h4 style="margin: 18px 0 10px;">Категории</h4>
                    <div class="table-wrap">
                    <table class="menu-table">
                        <thead>
                            <tr>
                                <th>Poster ID</th>
                                <th>Raw</th>
                                <th>Цех</th>
                                <th>RU</th>
                                <th>EN</th>
                                <th>VN</th>
                                <th>KO</th>
                                <th>Отображать</th>
                                <th>Sort</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($menuCategories as $c): ?>
                                <tr>
                                    <td><?= (int)$c['poster_id'] ?></td>
                                    <td><?= htmlspecialchars($c['name_raw']) ?></td>
                                    <td style="min-width: 220px;">
                                        <select name="category_parent[<?= (int)$c['id'] ?>]">
                                            <option value="">—</option>
                                            <?php foreach ($menuWorkshops as $m): ?>
                                                <?php
                                                    $mid = (int)$m['id'];
                                                    $mname = $stripNumberPrefix((string)($m['name_ru'] ?? $m['name_raw']));
                                                ?>
                                                <option value="<?= $mid ?>" <?= (int)($c['workshop_id'] ?? 0) === $mid ? 'selected' : '' ?>><?= htmlspecialchars($mname) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td><input name="category_tr[<?= (int)$c['id'] ?>][ru]" value="<?= htmlspecialchars($c['name_ru'] ?? '') ?>" /></td>
                                    <td><input name="category_tr[<?= (int)$c['id'] ?>][en]" value="<?= htmlspecialchars($c['name_en'] ?? '') ?>" /></td>
                                    <td><input name="category_tr[<?= (int)$c['id'] ?>][vn]" value="<?= htmlspecialchars($c['name_vn'] ?? '') ?>" /></td>
                                    <td><input name="category_tr[<?= (int)$c['id'] ?>][ko]" value="<?= htmlspecialchars($c['name_ko'] ?? '') ?>" /></td>
                                    <td style="width:110px; text-align:center;">
                                        <input type="checkbox" name="category_show[<?= (int)$c['id'] ?>]" value="1" <?= !empty($c['show_on_site']) ? 'checked' : '' ?>>
                                    </td>
                                    <td style="width:90px;"><input type="number" name="category_sort[<?= (int)$c['id'] ?>]" value="<?= (int)$c['sort_order'] ?>" /></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>

                    <div style="margin-top: 14px;">
                        <button type="submit" name="save_categories">Сохранить категории</button>
                    </div>
                </form>
