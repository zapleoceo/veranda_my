<div class="card" style="max-width:560px">
    <h2>Настройки бронирований</h2>
    <form method="POST">
        <div style="display:grid;gap:.875rem">
            <div>
                <label>Столы (через запятую)</label>
                <input type="text" name="tables"
                    value="<?= htmlspecialchars(implode(', ', (array)($config['tables'] ?? []))) ?>"
                    placeholder="1, 2, 3, 4">
            </div>
            <div>
                <label>Порог "скоро" (часов)</label>
                <input type="number" name="soon_hours" value="<?= (int)($config['soon_hours'] ?? 2) ?>" min="0" max="24" style="width:100px">
            </div>
            <div>
                <label>Последнее время брони (будни)</label>
                <input type="text" name="latest_workday" value="<?= htmlspecialchars($config['latest_workday'] ?? '21:00') ?>" style="width:120px" placeholder="21:00">
            </div>
            <div>
                <label>Последнее время брони (выходные)</label>
                <input type="text" name="latest_weekend" value="<?= htmlspecialchars($config['latest_weekend'] ?? '22:00') ?>" style="width:120px" placeholder="22:00">
            </div>
        </div>
        <div style="margin-top:1rem">
            <button type="submit" name="save_config" class="btn btn-primary">Сохранить</button>
        </div>
    </form>
</div>
