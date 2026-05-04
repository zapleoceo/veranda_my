            <div class="card">
                <h3>Статус синков</h3>
                <table class="menu-table">
                    <tr>
                        <th>Синк</th>
                        <th>Последний запуск</th>
                        <th>Результат</th>
                        <th>Ошибка</th>
                        <th>Описание</th>
                    </tr>
                    <?php foreach ($syncDefs as $d): ?>
                        <tr>
                            <td style="font-weight:700;"><?= htmlspecialchars($d['label']) ?></td>
                            <td><?= htmlspecialchars($meta[$d['at_key']] !== '' ? $meta[$d['at_key']] : '—') ?></td>
                            <td><?= htmlspecialchars($meta[$d['result_key']] !== '' ? $meta[$d['result_key']] : '—') ?></td>
                            <td><?= htmlspecialchars($meta[$d['error_key']] !== '' ? $meta[$d['error_key']] : '—') ?></td>
                            <td class="muted"><?= htmlspecialchars($d['desc']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            </div>

            <div class="card">
                <h3>Запуск руками</h3>
                <div class="muted">Запускает серверные скрипты. Рекомендуется использовать редко и осознанно.</div>
                <form method="post" style="margin-top: 12px; display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end;">
                    <input type="hidden" name="run_script" value="1">
                    <div class="form-group" style="flex:1; min-width:220px; margin-bottom:0;">
                        <label for="script_name">Скрипт</label>
                        <select name="script_name" id="script_name">
                            <option value="kitchen_cron" data-desc="cron.php — синк кухни за сегодня (kitchen_stats), обновляет Kitchen Online / Dashboard / Rawdata.">Кухня: синк за сегодня</option>
                            <option value="kitchen_resync_range" data-desc="scripts/kitchen/resync_range.php — пересинк кухни за диапазон дат (фоновой запуск, чтобы не ловить 504).">Кухня: пересинк диапазон</option>
                            <option value="kitchen_prob_close" data-desc="scripts/kitchen/backfill_prob_close_at.php — пересчёт логического закрытия (ProbCloseTime).">Пересчёт ВрЛогЗакр</option>
                            <option value="menu_cron" data-desc="menu_cron.php — синк меню из Poster (poster_menu_items + справочники).">Меню: синк из Poster</option>
                            <option value="tg_alerts" data-desc="telegram_alerts.php — отправка/обновление Telegram уведомлений.">Telegram: уведомления</option>
                        </select>
                    </div>
                    <div class="form-group" style="margin-bottom:0;">
                        <label for="date_from">От</label>
                        <input type="date" name="date_from" id="date_from" value="<?= htmlspecialchars(date('Y-m-d')) ?>">
                    </div>
                    <div class="form-group" style="margin-bottom:0;">
                        <label for="date_to">До</label>
                        <input type="date" name="date_to" id="date_to" value="<?= htmlspecialchars(date('Y-m-d')) ?>">
                    </div>
                    <button type="submit" <?= $canExec ? '' : 'disabled' ?>>Запустить</button>
                </form>
                <?php if (!$canExec): ?>
                    <div class="error" style="margin-top:12px;">Запуск недоступен: на сервере отключена функция exec().</div>
                <?php endif; ?>
                <div id="script_desc" class="muted" style="margin-top:10px;"></div>
                <?= $runResultHtml ?>
                <script src="/assets/js/admin_2.js"></script>
            </div>
