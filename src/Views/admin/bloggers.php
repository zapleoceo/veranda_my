<?php
/**
 * /admin/bloggers — manager view.
 *
 * @var array  $report   ['rows' => [...], 'totals' => [...]] — every blogger, active first
 * @var string $dateFrom YYYY-MM-DD
 * @var string $dateTo   YYYY-MM-DD
 */

$esc    = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$fmtVnd = static fn ($minor): string => number_format((int) round(((float) $minor) / 100), 0, '.', ' ');
$fmtPct = static fn ($p): string => rtrim(rtrim(number_format((float) $p, 2, '.', ''), '0'), '.');

$rows   = $report['rows']   ?? [];
$totals = $report['totals'] ?? ['bloggers' => 0, 'checks' => 0, 'revenue' => 0, 'cashback' => 0];

// Toggleable report columns (promocode + actions are always shown).
$cols = [
    'name'      => 'Имя',
    'discount'  => 'Скидка',
    'cashback'  => 'Кешбек %',
    'checks'    => 'Чеки',
    'revenue'   => 'Выручка ₫',
    'cashbackv' => 'Кешбек ₫',
];
?>
<style>
.bl-note{color:var(--muted);font-size:.82rem;line-height:1.5;margin-bottom:1rem}
.bl-note b{color:var(--text)}
.bl-toolbar{display:flex;gap:1rem;align-items:flex-end;justify-content:space-between;flex-wrap:wrap;margin-bottom:1rem}
.bl-period{display:flex;gap:.75rem;align-items:flex-end;flex-wrap:wrap;margin:0}
.bl-period .f{display:flex;flex-direction:column}
.bl-period label{margin-bottom:.2rem}
.bl-cols{position:relative}
.bl-cols-pop{position:absolute;right:0;top:calc(100% + 4px);z-index:20;background:var(--surface);
    border:1px solid var(--border);border-radius:8px;padding:.5rem .6rem;min-width:170px;box-shadow:0 8px 24px rgba(0,0,0,.4)}
.bl-cols-pop label{display:flex;align-items:center;gap:.45rem;font-size:.8rem;font-weight:500;
    color:var(--text);margin:0;padding:.2rem 0;text-transform:none;letter-spacing:0;cursor:pointer}
.bl-cols-pop input{margin:0}
.bl-pills{display:flex;gap:.6rem;flex-wrap:wrap;margin-top:1rem}
.bl-pill{border:1px solid var(--border);border-radius:10px;padding:.5rem .85rem;font-size:.82rem;font-weight:700;background:var(--card2)}
.bl-pill .v{font-size:1.05rem;display:block;margin-top:.15rem}
.bl-pill.ok{border-color:rgba(16,185,129,.4);color:#6ee7b7}
.bl-pill.accent{border-color:rgba(184,135,70,.5);color:var(--accent)}
.num{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
.col-actions{width:1%;white-space:nowrap;text-align:right}
tr.off td{opacity:.45}
.bl-edit-btn{padding:.2rem .55rem}
.bl-edit-cell{background:var(--card2)}
.bl-edit-cell td,.bl-edit-cell{padding:0}
.bl-edit-wrap{padding:.85rem 1rem;border-left:2px solid var(--accent)}
.bl-edit-form .fields{display:grid;grid-template-columns:repeat(5,1fr);gap:.5rem .65rem;margin-bottom:.6rem}
.bl-edit-form .fields .wide{grid-column:1 / -1}
.bl-edit-form .fields label{margin-bottom:.15rem;font-size:.72rem}
.bl-edit-form .fields input{width:100%}
.bl-edit-actions{display:flex;gap:.6rem;align-items:center;flex-wrap:wrap}
.bl-edit-actions form{margin:0}
.bl-cid{margin-left:auto;color:var(--muted);font-size:.72rem}
.bl-tag{display:inline-block;font-size:.68rem;padding:.05rem .4rem;border-radius:4px;border:1px solid var(--border);color:var(--muted);margin-left:.4rem}
/* column visibility */
#blTable.hide-name .col-name,
#blTable.hide-discount .col-discount,
#blTable.hide-cashback .col-cashback,
#blTable.hide-checks .col-checks,
#blTable.hide-revenue .col-revenue,
#blTable.hide-cashbackv .col-cashbackv{display:none}
.bl-add .fields{display:grid;grid-template-columns:repeat(5,1fr);gap:.5rem .65rem;margin-bottom:.6rem}
.bl-add .fields label{margin-bottom:.15rem;font-size:.72rem}
.bl-add .fields input{width:100%}
@media(max-width:680px){.bl-edit-form .fields,.bl-add .fields{grid-template-columns:1fr 1fr}}
</style>

<div class="card">
  <h2>Блогеры — отчёт по промокодам</h2>
  <p class="bl-note">
    Промокод = имя клиента в Poster (группа <b>Blogers</b>). Официант привязывает карту по промокоду на кассе →
    скидка применяется автоматически, продажа засчитывается блогеру. Кешбек = <b>выручка после скидки × %</b>.
    Нажми <b>✎</b>, чтобы изменить промокод, имя, скидку или кешбек.
  </p>

  <div class="bl-toolbar">
    <form method="get" action="/admin/bloggers" class="bl-period">
      <div class="f">
        <label>Начало</label>
        <input type="date" name="dateFrom" value="<?= $esc($dateFrom) ?>">
      </div>
      <div class="f">
        <label>Конец</label>
        <input type="date" name="dateTo" value="<?= $esc($dateTo) ?>">
      </div>
      <button class="btn btn-primary" type="submit">Показать</button>
    </form>

    <div class="bl-cols">
      <button type="button" class="btn btn-secondary btn-sm" id="colsBtn">Колонки ▾</button>
      <div class="bl-cols-pop" id="colsPop" style="display:none">
        <?php foreach ($cols as $key => $label): ?>
          <label><input type="checkbox" data-col="<?= $esc($key) ?>" checked> <?= $esc($label) ?></label>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <?php if (empty($rows)): ?>
    <p class="bl-note">Пока нет блогеров в группе Blogers. Создайте первого ниже.</p>
  <?php else: ?>
    <table id="blTable">
      <thead>
        <tr>
          <th class="col-promo">Промокод</th>
          <th class="col-name">Имя</th>
          <th class="col-discount num">Скидка</th>
          <th class="col-cashback num">Кешбек&nbsp;%</th>
          <th class="col-checks num">Чеки</th>
          <th class="col-revenue num">Выручка&nbsp;₫</th>
          <th class="col-cashbackv num">Кешбек&nbsp;₫</th>
          <th class="col-actions"></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): $id = (int) $r['client_id']; ?>
          <tr class="<?= $r['is_active'] ? '' : 'off' ?>">
            <td class="col-promo">
              <code><?= $esc($r['promocode']) ?></code>
              <?php if (!$r['is_active']): ?><span class="bl-tag">неактивен</span><?php endif; ?>
            </td>
            <td class="col-name"><?= $esc($r['name']) ?></td>
            <td class="col-discount num"><?= $fmtPct($r['discount_pct']) ?>%</td>
            <td class="col-cashback num"><?= $fmtPct($r['cashback_pct']) ?>%</td>
            <td class="col-checks num"><?= (int) $r['checks'] ?></td>
            <td class="col-revenue num"><?= $fmtVnd($r['revenue']) ?></td>
            <td class="col-cashbackv num"><?= $fmtVnd($r['cashback']) ?></td>
            <td class="col-actions">
              <button type="button" class="btn btn-secondary btn-sm bl-edit-btn" data-id="<?= $id ?>" title="Редактировать">✎</button>
            </td>
          </tr>
          <tr class="bl-edit-cell" id="edit-<?= $id ?>" hidden>
            <td colspan="8">
              <div class="bl-edit-wrap">
                <form method="post" action="/admin/bloggers" class="bl-edit-form">
                  <input type="hidden" name="update_blogger" value="1">
                  <input type="hidden" name="client_id" value="<?= $id ?>">
                  <input type="hidden" name="dateFrom" value="<?= $esc($dateFrom) ?>">
                  <input type="hidden" name="dateTo" value="<?= $esc($dateTo) ?>">
                  <div class="fields">
                    <div>
                      <label>Промокод</label>
                      <input type="text" name="promocode" value="<?= $esc($r['promocode']) ?>" required>
                    </div>
                    <div>
                      <label>Имя</label>
                      <input type="text" name="name" value="<?= $esc($r['name']) ?>">
                    </div>
                    <div class="wide">
                      <label>Email (gmail)</label>
                      <input type="email" name="email" value="<?= $esc($r['email']) ?>">
                    </div>
                    <div>
                      <label>Скидка %</label>
                      <input type="number" name="discount_pct" min="0" max="100" step="0.5" value="<?= $esc($fmtPct($r['discount_pct'])) ?>">
                    </div>
                    <div>
                      <label>Кешбек %</label>
                      <input type="number" name="cashback_pct" min="0" max="100" step="0.5" value="<?= $esc($fmtPct($r['cashback_pct'])) ?>">
                    </div>
                  </div>
                  <div class="bl-edit-actions">
                    <button class="btn btn-primary btn-sm" type="submit">Сохранить</button>
                    <span class="bl-cid">id <?= $id ?><?= $r['tracked'] ? '' : ' · только в Poster' ?></span>
                  </div>
                </form>
                <div class="bl-edit-actions" style="margin-top:.55rem">
                  <form method="post" action="/admin/bloggers">
                    <input type="hidden" name="client_id" value="<?= $id ?>">
                    <input type="hidden" name="dateFrom" value="<?= $esc($dateFrom) ?>">
                    <input type="hidden" name="dateTo" value="<?= $esc($dateTo) ?>">
                    <?php if ($r['is_active']): ?>
                      <input type="hidden" name="toggle_active" value="deactivate">
                      <button class="btn btn-secondary btn-sm" type="submit">Деактивировать</button>
                    <?php else: ?>
                      <input type="hidden" name="toggle_active" value="activate">
                      <button class="btn btn-secondary btn-sm" type="submit">Активировать</button>
                    <?php endif; ?>
                  </form>
                </div>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <div class="bl-pills">
    <div class="bl-pill">Блогеров<span class="v"><?= (int) $totals['bloggers'] ?></span></div>
    <div class="bl-pill">Чеков<span class="v"><?= (int) $totals['checks'] ?></span></div>
    <div class="bl-pill ok">Выручка ₫<span class="v"><?= $fmtVnd($totals['revenue']) ?></span></div>
    <div class="bl-pill accent">Кешбек к выплате ₫<span class="v"><?= $fmtVnd($totals['cashback']) ?></span></div>
  </div>
</div>

<div class="card bl-add">
  <h2>Добавить блогера</h2>
  <form method="post" action="/admin/bloggers">
    <input type="hidden" name="create_blogger" value="1">
    <input type="hidden" name="dateFrom" value="<?= $esc($dateFrom) ?>">
    <input type="hidden" name="dateTo" value="<?= $esc($dateTo) ?>">
    <div class="fields">
      <div>
        <label>Промокод</label>
        <input type="text" name="promocode" placeholder="ANNA2026" required>
      </div>
      <div>
        <label>Имя блогера</label>
        <input type="text" name="name" placeholder="Анна Иванова">
      </div>
      <div>
        <label>Email (gmail)</label>
        <input type="email" name="email" placeholder="anna@gmail.com">
      </div>
      <div>
        <label>Скидка %</label>
        <input type="number" name="discount_pct" min="0" max="100" step="0.5" value="0">
      </div>
      <div>
        <label>Кешбек %</label>
        <input type="number" name="cashback_pct" min="0" max="100" step="0.5" value="0">
      </div>
    </div>
    <button class="btn btn-primary" type="submit">Создать</button>
  </form>
</div>

<script>
(function () {
    // Inline edit toggle
    document.querySelectorAll('.bl-edit-btn').forEach(function (b) {
        b.addEventListener('click', function () {
            var row = document.getElementById('edit-' + b.dataset.id);
            if (row) { row.hidden = !row.hidden; }
        });
    });

    // Column chooser (persisted in localStorage)
    var table = document.getElementById('blTable');
    var KEY = 'bloggers_cols_hidden';
    var hidden = {};
    try { hidden = JSON.parse(localStorage.getItem(KEY) || '{}') || {}; } catch (e) {}

    var boxes = document.querySelectorAll('#colsPop input[data-col]');
    function apply() {
        if (!table) return;
        boxes.forEach(function (cb) {
            var col = cb.dataset.col;
            cb.checked = !hidden[col];
            table.classList.toggle('hide-' + col, !!hidden[col]);
        });
    }
    boxes.forEach(function (cb) {
        cb.addEventListener('change', function () {
            hidden[cb.dataset.col] = !cb.checked;
            try { localStorage.setItem(KEY, JSON.stringify(hidden)); } catch (e) {}
            apply();
        });
    });
    apply();

    var btn = document.getElementById('colsBtn');
    var pop = document.getElementById('colsPop');
    if (btn && pop) {
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            pop.style.display = (pop.style.display === 'none') ? 'block' : 'none';
        });
        document.addEventListener('click', function (e) {
            if (pop.style.display !== 'none' && !pop.contains(e.target) && e.target !== btn) {
                pop.style.display = 'none';
            }
        });
    }
})();
</script>
