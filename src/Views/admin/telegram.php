<div class="card" style="max-width:700px">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap;margin-bottom:1rem">
        <div>
            <h2 style="margin-bottom:.25rem">Telegram алерты</h2>
            <p style="color:#6b7280;font-size:.8rem;margin:0">Уведомления по просроченным блюдам из Kitchen Online.</p>
        </div>
        <div style="text-align:right;font-size:.8rem;color:#6b7280">
            <div>Последний запуск: <b><?= htmlspecialchars($telegramMeta['telegram_last_run_at'] ?: '—') ?></b></div>
            <?php if (!empty($telegramMeta['telegram_last_run_error'])): ?>
                <div style="color:#dc2626;font-weight:700">Ошибка: <?= htmlspecialchars($telegramMeta['telegram_last_run_error']) ?></div>
            <?php elseif (!empty($telegramMeta['telegram_last_run_result'])): ?>
                <div><?= htmlspecialchars($telegramMeta['telegram_last_run_result']) ?></div>
            <?php endif; ?>
        </div>
    </div>

    <form method="POST">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:.75rem;margin-bottom:1rem">
            <div>
                <label>Тайминг (низкая нагрузка), мин</label>
                <input type="number" name="alert_timing_low_load" value="<?= $settings['alert_timing_low_load'] ?>" required>
            </div>
            <div>
                <label>Порог чеков</label>
                <input type="number" name="alert_load_threshold" value="<?= $settings['alert_load_threshold'] ?>" required>
            </div>
            <div>
                <label>Тайминг (высокая нагрузка), мин</label>
                <input type="number" name="alert_timing_high_load" value="<?= $settings['alert_timing_high_load'] ?>" required>
            </div>
            <div>
                <label>Снуз "Принято", мин</label>
                <input type="number" name="alert_ack_snooze_minutes" value="<?= $settings['alert_ack_snooze_minutes'] ?>">
            </div>
        </div>
        <div style="display:flex;gap:1rem;align-items:center;flex-wrap:wrap">
            <label style="display:flex;align-items:center;gap:.4rem;font-weight:400;font-size:.875rem;cursor:pointer">
                <input type="hidden" name="exclude_partners_from_load" value="0">
                <input type="checkbox" name="exclude_partners_from_load" value="1" <?= !empty($settings['exclude_partners_from_load']) ? 'checked' : '' ?>>
                Не учитывать стол Partners в нагрузке
            </label>
            <button type="submit" name="save_settings" class="btn btn-primary">Сохранить</button>
        </div>
    </form>
</div>

<div class="card" style="max-width:700px">
    <h2>Тест отправки</h2>
    <div style="display:flex;gap:.5rem;align-items:flex-end">
        <div style="flex:1">
            <label>Текст сообщения</label>
            <input type="text" id="testText" value="Тест: статус проверки">
        </div>
        <button class="btn btn-primary" onclick="sendTest()">Отправить</button>
    </div>
    <div id="testResult" style="margin-top:.5rem;font-size:.8rem"></div>
</div>

<div class="card" style="max-width:700px">
    <details>
        <summary style="cursor:pointer;font-weight:600;font-size:.875rem">Логика работы алертов</summary>
        <div style="margin-top:.75rem;font-size:.8rem;color:#4b5563;line-height:1.6">
            <p><b>Кандидаты</b>: позиции, у которых ticket_sent_at старше лимита, чек открыт, позиция не удалена и не в "Игнор".</p>
            <p><b>Один алерт = один чек</b>: если несколько просроченных блюд — всё в одном сообщении.</p>
            <p><b>Обновление</b>: бот редактирует сообщение при изменениях; удаляет когда блюда готовы.</p>
            <p><b>Принято</b>: кнопка ставит игнор по конкретному блюду (право: "Игнор + ✅ Принято").</p>
        </div>
    </details>
</div>

<script>
async function sendTest() {
    const text = document.getElementById('testText').value;
    const res  = document.getElementById('testResult');
    res.textContent = 'Отправляем...';
    try {
        const r = await fetch('?ajax=telegram_test', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'text=' + encodeURIComponent(text),
        });
        const d = await r.json();
        res.textContent = d.ok ? '✅ Отправлено (ID: ' + d.message_id + ')' : '❌ ' + d.error;
        res.style.color = d.ok ? '#065f46' : '#991b1b';
    } catch(e) {
        res.textContent = '❌ ' + e.message;
        res.style.color = '#991b1b';
    }
}
</script>
