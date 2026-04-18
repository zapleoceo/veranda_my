<div class="card" style="max-width: 1100px; margin: 0 auto;">
                <h2 style="margin:0 0 10px;">Брони — доступные столы</h2>
                <div class="small-muted" style="margin: 0 0 14px;">
                    Здесь выбираются номера столов, которые доступны для бронирования в публичной форме.
                </div>

                <form method="post" action="?tab=reservations&hall_id=<?= (int)$resHallId ?>&spot_id=<?= (int)$resSpotId ?>" style="display:flex; gap: 12px; align-items:flex-end; margin-bottom: 12px; flex-wrap: wrap;">
                    <input type="hidden" name="save_reservation_soon_hours" value="1">
                    <label style="display:grid; gap:6px;">
                        <div class="small-muted">Запас часов</div>
                        <input type="number" name="soon_hours" value="<?= (int)$resSoonHours ?>" min="0" max="24" step="1" style="width: 120px; padding: 8px 10px; border-radius: 10px; border: 1px solid #e5e7eb;">
                    </label>
                    <label style="display:grid; gap:6px;">
                        <div class="small-muted">Поздняя бронь (Пн-Чт)</div>
                        <input type="time" name="latest_workday" value="<?= htmlspecialchars($resLatestWorkday) ?>" style="width: 150px; padding: 8px 10px; border-radius: 10px; border: 1px solid #e5e7eb;">
                    </label>
                    <label style="display:grid; gap:6px;">
                        <div class="small-muted">Поздняя бронь (Пт-Вс)</div>
                        <input type="time" name="latest_weekend" value="<?= htmlspecialchars($resLatestWeekend) ?>" style="width: 150px; padding: 8px 10px; border-radius: 10px; border: 1px solid #e5e7eb;">
                    </label>
                    <button type="submit" class="pill ok" style="border:0; cursor:pointer;">Сохранить</button>
                </form>

                <form method="post" action="?tab=reservations&hall_id=<?= (int)$resHallId ?>&spot_id=<?= (int)$resSpotId ?>" style="display:grid; gap: 12px;">
                    <input type="hidden" name="save_reservation_tables" value="1">
                    <div style="display:flex; gap: 12px; flex-wrap: wrap; align-items: flex-end;">
                        <label style="display:grid; gap:6px;">
                            <div class="small-muted">Spot ID</div>
                            <input type="number" name="spot_id" value="<?= (int)$resSpotId ?>" min="1" style="width: 120px; padding: 8px 10px; border-radius: 10px; border: 1px solid #e5e7eb;">
                        </label>
                        <label style="display:grid; gap:6px;">
                            <div class="small-muted">Hall ID</div>
                            <input type="number" name="hall_id" value="<?= (int)$resHallId ?>" min="1" style="width: 120px; padding: 8px 10px; border-radius: 10px; border: 1px solid #e5e7eb;">
                        </label>
                        <div style="display:flex; gap: 10px; flex-wrap: wrap; align-items:center;">
                            <button type="button" class="pill ok" data-select-all style="border:0; cursor:pointer;">Отметить все</button>
                            <button type="button" class="pill warn" data-select-none style="border:0; cursor:pointer;">Снять все</button>
                        </div>
                        <div style="margin-left:auto;">
                            <button type="submit" class="pill ok" style="border:0; cursor:pointer;">Сохранить</button>
                        </div>
                    </div>

                    <?php if (empty($resTables)): ?>
                        <div class="small-muted">Нет данных по столам (или ошибка Poster API).</div>
                    <?php else: ?>
                        <label style="display:flex; gap:10px; align-items:center; user-select:none;">
                            <input type="checkbox" id="hideEmptyCaps">
                            <span class="small-muted">Скрыть пустые лимиты</span>
                        </label>
                        <div style="overflow:auto; border:1px solid var(--border); border-radius: 12px; background: var(--card2);">
                            <table id="resTablesTable" style="width:100%; border-collapse: collapse; min-width: 860px;">
                                <thead>
                                    <tr style="background: var(--card);">
                                        <th style="text-align:left; padding:10px 12px; border-bottom:1px solid var(--border);">Доступен</th>
                                        <th style="text-align:left; padding:10px 12px; border-bottom:1px solid var(--border);">Номер на схеме</th>
                                        <th style="text-align:left; padding:10px 12px; border-bottom:1px solid var(--border);">👤</th>
                                        <th style="text-align:left; padding:10px 12px; border-bottom:1px solid var(--border);">Table ID</th>
                                        <th style="text-align:left; padding:10px 12px; border-bottom:1px solid var(--border);">table_num</th>
                                        <th id="resTablesSortTitle" data-sort-key="title" style="text-align:left; padding:10px 12px; border-bottom:1px solid var(--border); cursor:pointer; user-select:none;">table_title <span id="resTablesSortArrow">▲</span></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($resTables as $r): ?>
                                        <tr data-row="1">
                                            <td style="padding:10px 12px; border-bottom:1px solid var(--border);">
                                                <?php if (($r['scheme_num'] ?? '') !== ''): ?>
                                                    <input type="checkbox" name="allowed_nums[]" value="<?= htmlspecialchars((string)$r['scheme_num']) ?>" <?= !empty($r['is_allowed']) ? 'checked' : '' ?>>
                                                <?php else: ?>
                                                    <span class="small-muted">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="padding:10px 12px; border-bottom:1px solid var(--border); font-weight:700;">
                                                <?= htmlspecialchars((string)($r['scheme_num'] ?? '—')) ?>
                                            </td>
                                            <td style="padding:10px 12px; border-bottom:1px solid var(--border);">
                                                <?php if (($r['scheme_num'] ?? '') !== ''): ?>
                                                    <input type="number" class="cap-input" name="caps[<?= htmlspecialchars((string)$r['scheme_num']) ?>]" value="<?= (int)($r['cap'] ?? 0) ?>" min="0" max="999" style="width: 56px; padding: 6px 8px; border-radius: 10px; border: 1px solid var(--border); background: var(--card); color: var(--text);">
                                                <?php else: ?>
                                                    <span class="small-muted">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="padding:10px 12px; border-bottom:1px solid var(--border);">
                                                <?= htmlspecialchars((string)($r['table_id'] ?? '—')) ?>
                                            </td>
                                            <td style="padding:10px 12px; border-bottom:1px solid var(--border);">
                                                <?= htmlspecialchars((string)($r['table_num'] ?? '—')) ?>
                                            </td>
                                            <td style="padding:10px 12px; border-bottom:1px solid var(--border);">
                                                <?= htmlspecialchars((string)($r['table_title'] ?? '—')) ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </form>
            </div>

            <script src="/assets/js/admin.js?v=20260411_0635"></script>