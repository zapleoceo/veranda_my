<?php
/** @var array $config */
/** @var array $clients */
$clients = $clients ?? [];
$h = static fn($s) => htmlspecialchars((string) ($s ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

// Форматтер даты Y-m-d H:i:s → 27.05.2026 14:30, пустые значения → «—».
$fmt = static function (?string $s) {
    if (!$s) return '—';
    $ts = strtotime($s);
    return $ts ? date('d.m.Y H:i', $ts) : $s;
};
?>
<div class="card" style="max-width:560px;margin-bottom:1.25rem">
    <h2>Настройки бронирований</h2>
    <form method="POST">
        <div style="display:grid;gap:.875rem">
            <div>
                <label>Столы (через запятую)</label>
                <input type="text" name="tables"
                    value="<?= $h(implode(', ', (array)($config['tables'] ?? []))) ?>"
                    placeholder="1, 2, 3, 4">
            </div>
            <div>
                <label>Порог "скоро" (часов)</label>
                <input type="number" name="soon_hours" value="<?= (int)($config['soon_hours'] ?? 2) ?>" min="0" max="24" style="width:100px">
            </div>
            <div>
                <label>Последнее время брони (будни)</label>
                <input type="text" name="latest_workday" value="<?= $h($config['latest_workday'] ?? '21:00') ?>" style="width:120px" placeholder="21:00">
            </div>
            <div>
                <label>Последнее время брони (выходные)</label>
                <input type="text" name="latest_weekend" value="<?= $h($config['latest_weekend'] ?? '22:00') ?>" style="width:120px" placeholder="22:00">
            </div>
        </div>
        <div style="margin-top:1rem">
            <button type="submit" name="save_config" class="btn btn-primary">Сохранить</button>
        </div>
    </form>
</div>

<!-- ─── Клиенты ─────────────────────────────────────────────────── -->
<div class="card">
    <div style="display:flex;align-items:center;gap:1rem;justify-content:space-between;flex-wrap:wrap">
        <div>
            <h2 style="margin:0">Клиенты</h2>
            <div class="muted" style="font-size:.85rem;margin-top:.25rem">
                Уникальные клиенты по номеру телефона из всех броней (кроме удалённых).
                Всего: <strong><?= count($clients) ?></strong>
            </div>
        </div>
        <a href="/admin/reservations?export=clients"
           class="btn btn-primary"
           download
           title="Скачать CSV (UTF-8 с BOM, открывается в Excel и Google Sheets)">
            Скачать CSV
        </a>
    </div>

    <?php if (!$clients): ?>
        <div class="muted" style="padding:1.5rem 0">
            Клиентов пока нет. Они появятся здесь как только в системе будут оформлены брони.
        </div>
    <?php else: ?>
        <div style="margin-top:1rem;overflow-x:auto">
            <table style="width:100%;border-collapse:collapse;font-size:.9rem">
                <thead>
                    <tr style="text-align:left;border-bottom:1px solid var(--border)">
                        <th style="padding:.5rem .75rem">Имя</th>
                        <th style="padding:.5rem .75rem">Телефон</th>
                        <th style="padding:.5rem .75rem">WhatsApp</th>
                        <th style="padding:.5rem .75rem">Zalo</th>
                        <th style="padding:.5rem .75rem;text-align:right">Броней</th>
                        <th style="padding:.5rem .75rem">Последняя</th>
                        <th style="padding:.5rem .75rem">Первая</th>
                        <th style="padding:.5rem .75rem">Язык</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($clients as $c): ?>
                        <tr style="border-bottom:1px solid var(--border)">
                            <td style="padding:.5rem .75rem"><?= $h($c['name']) ?: '<span class="muted">—</span>' ?></td>
                            <td style="padding:.5rem .75rem;font-family:ui-monospace,Menlo,Consolas,monospace"><?= $h($c['phone']) ?></td>
                            <td style="padding:.5rem .75rem;font-family:ui-monospace,Menlo,Consolas,monospace"><?= $h($c['whatsapp_phone']) ?: '<span class="muted">—</span>' ?></td>
                            <td style="padding:.5rem .75rem;font-family:ui-monospace,Menlo,Consolas,monospace"><?= $h($c['zalo_phone']) ?: '<span class="muted">—</span>' ?></td>
                            <td style="padding:.5rem .75rem;text-align:right;font-variant-numeric:tabular-nums"><?= (int)($c['reservations_count'] ?? 0) ?></td>
                            <td style="padding:.5rem .75rem;white-space:nowrap"><?= $h($fmt($c['last_reservation'] ?? null)) ?></td>
                            <td style="padding:.5rem .75rem;white-space:nowrap"><?= $h($fmt($c['first_seen'] ?? null)) ?></td>
                            <td style="padding:.5rem .75rem"><?= $h($c['lang']) ?: '<span class="muted">—</span>' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
