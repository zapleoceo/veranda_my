<?php
$content = file_get_contents('/workspace/admin.php');
$start = strpos($content, '<h2 style="margin:0 0 10px;">Брони — доступные столы</h2>');
$end = strpos($content, '<?php elseif ($tab === \'menu\'): ?>');
if ($start !== false && $end !== false) {
    $new_html = <<<HTML
<h2 style="margin:0 0 10px;">Брони — настройки времени</h2>
                <form method="post" action="admin.php?tab=reservations" style="display:flex; gap: 12px; align-items:flex-end; margin-bottom: 12px; flex-wrap: wrap;">
                    <input type="hidden" name="save_reservation_soon_hours" value="1">
                    <label style="display:grid; gap:6px;">
                        <div class="small-muted">Запас часов</div>
                        <input type="number" name="soon_hours" value="<?= (int)\$resSoonHours ?>" min="0" max="24" step="1" style="width: 120px; padding: 8px 10px; border-radius: 10px; border: 1px solid #e5e7eb;">
                    </label>
                    <label style="display:grid; gap:6px;">
                        <div class="small-muted">Поздняя бронь (Пн-Чт)</div>
                        <input type="time" name="latest_workday" value="<?= htmlspecialchars(\$resLatestWorkday) ?>" style="width: 150px; padding: 8px 10px; border-radius: 10px; border: 1px solid #e5e7eb;">
                    </label>
                    <label style="display:grid; gap:6px;">
                        <div class="small-muted">Поздняя бронь (Пт-Вс)</div>
                        <input type="time" name="latest_weekend" value="<?= htmlspecialchars(\$resLatestWeekend) ?>" style="width: 150px; padding: 8px 10px; border-radius: 10px; border: 1px solid #e5e7eb;">
                    </label>
                    <button type="submit" class="pill ok" style="border:0; cursor:pointer;">Сохранить</button>
                </form>
            </div>
        
HTML;
    $content = substr_replace($content, $new_html, $start, $end - $start);
    file_put_contents('/workspace/admin.php', $content);
}
