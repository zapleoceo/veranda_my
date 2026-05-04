        <div class="card">
            <div style="display:flex; align-items:flex-start; justify-content: space-between; gap: 16px; flex-wrap: wrap;">
                <div>
                    <h3 style="margin:0 0 6px;">Telegram</h3>
                    <div class="muted">Алерты по просроченным блюдам из Kitchen Online. Сообщение одно на чек.</div>
                </div>
                <div class="muted" style="text-align:right;">
                    <div>Последний запуск: <b><?= htmlspecialchars($telegramMeta['telegram_last_run_at'] !== '' ? $telegramMeta['telegram_last_run_at'] : '—') ?></b></div>
                    <?php if (!empty($telegramMeta['telegram_last_run_error'])): ?>
                        <div style="color:#b91c1c; font-weight:800; margin-top:4px;">Ошибка: <?= htmlspecialchars((string)$telegramMeta['telegram_last_run_error']) ?></div>
                    <?php else: ?>
                        <div style="margin-top:4px;"><?= htmlspecialchars($telegramMeta['telegram_last_run_result'] ?? '') ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <form method="POST" style="margin-top: 14px;">
                <div class="settings-grid" style="grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); max-width: 820px;">
                    <div class="form-group">
                        <label>Тайминг (низк. нагр.), мин</label>
                        <input type="number" name="alert_timing_low_load" value="<?= $settings['alert_timing_low_load'] ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Порог чеков</label>
                        <input type="number" name="alert_load_threshold" value="<?= $settings['alert_load_threshold'] ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Тайминг (выс. нагр.), мин</label>
                        <input type="number" name="alert_timing_high_load" value="<?= $settings['alert_timing_high_load'] ?>" required>
                    </div>
                </div>
                <div style="margin-top: 10px; display:flex; gap: 12px; align-items:center; flex-wrap: wrap;">
                    <label style="display:flex; align-items:center; gap: 8px; font-size: 14px; font-weight: 800;">
                        <input type="hidden" name="exclude_partners_from_load" value="0">
                        <input type="checkbox" name="exclude_partners_from_load" value="1" <?= !empty($settings['exclude_partners_from_load']) ? 'checked' : '' ?>>
                        Не учитывать стол Partners в нагрузке
                    </label>
                    <button type="submit" name="save_settings">Сохранить</button>
                </div>
            </form>

            <details style="margin-top: 14px;">
                <summary style="cursor:pointer; font-weight: 900;">Логика работы</summary>
                <div class="muted" style="margin-top: 10px; line-height: 1.55;">
                    <div><b>Кандидаты</b>: позиции из Kitchen Online, которые обводятся красным (ticket_sent_at старше лимита, чек открыт, позиция не удалена и не в “Игнор” на табло).</div>
                    <div><b>Один алерт = один чек</b>: если в чеке несколько просроченных блюд — всё в одном сообщении.</div>
                    <div><b>Обновление</b>: при изменениях состав/время — бот редактирует сообщение; если просроченных блюд в чеке не осталось — сообщение удаляется.</div>
                    <div><b>Принято</b>: кнопка “Принято” ставит игнор по конкретному блюду до готовности (бессрочно).</div>
                    <div><b>Доступ</b>: нажимать “Принято” можно только при наличии права “Игнор + ✅ Принято (Telegram)” и заполненном Telegram username.</div>
                </div>
            </details>

            <details style="margin-top: 14px;">
                <summary style="cursor:pointer; font-weight: 900;">Формат сообщения</summary>
                <div class="muted" style="margin-top: 10px; white-space: pre-wrap;">
Чек:(номерчека)|Стол(номер стола)
Имя официанта
Название блюда — сколько уже готовится
                </div>
            </details>
        </div>
