<?php
/**
 * /admin/bloggers — manager view.
 *
 * @var array $bloggers  list of bloggers (Poster + local merged)
 * @var array $report    ['rows' => [...], 'totals' => [...]]
 * @var string $dateFrom YYYY-MM-DD
 * @var string $dateTo   YYYY-MM-DD
 */

$esc    = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$fmtVnd = static fn ($minor): string => number_format((int) round(((float) $minor) / 100), 0, '.', ' ');
$fmtPct = static fn ($p): string => rtrim(rtrim(number_format((float) $p, 2, '.', ''), '0'), '.');

$rows   = $report['rows']   ?? [];
$totals = $report['totals'] ?? ['bloggers' => 0, 'checks' => 0, 'revenue' => 0, 'cashback' => 0];
?>
<style>
.bl-note{color:var(--muted);font-size:.82rem;line-height:1.5;margin-bottom:1rem}
.bl-note b{color:var(--text)}
.bl-period{display:flex;gap:.75rem;align-items:flex-end;flex-wrap:wrap;margin-bottom:1rem}
.bl-period .f{display:flex;flex-direction:column}
.bl-period label{margin-bottom:.2rem}
.bl-pills{display:flex;gap:.6rem;flex-wrap:wrap;margin-top:1rem}
.bl-pill{border:1px solid var(--border);border-radius:10px;padding:.5rem .85rem;font-size:.82rem;font-weight:700;background:var(--card2)}
.bl-pill .v{font-size:1.05rem;display:block;margin-top:.15rem}
.bl-pill.ok{border-color:rgba(16,185,129,.4);color:#6ee7b7}
.bl-pill.accent{border-color:rgba(184,135,70,.5);color:var(--accent)}
.num{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
.bl-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(330px,1fr));gap:.75rem}
.bl-card{border:1px solid var(--border);border-radius:8px;padding:.85rem;background:var(--card2)}
.bl-card.off{opacity:.5}
.bl-card .fields{display:grid;grid-template-columns:1fr 1fr;gap:.5rem .65rem;margin-bottom:.6rem}
.bl-card .fields .wide{grid-column:1 / -1}
.bl-card .fields label{margin-bottom:.15rem;font-size:.72rem}
.bl-card .fields input{width:100%}
.bl-card .actions{display:flex;gap:.5rem;align-items:center}
.bl-card .actions form{margin:0}
.bl-card .cid{margin-left:auto;color:var(--muted);font-size:.72rem}
.bl-tag{display:inline-block;font-size:.68rem;padding:.05rem .4rem;border-radius:4px;border:1px solid var(--border);color:var(--muted);margin-left:.4rem}
.bl-add .fields{grid-template-columns:repeat(5,1fr)}
@media(max-width:680px){.bl-add .fields{grid-template-columns:1fr 1fr}}
</style>

<div class="card">
  <h2>Блогеры — отчёт по промокодам</h2>
  <p class="bl-note">
    Промокод = имя клиента в Poster (группа <b>Blogers</b>). Официант привязывает карту по промокоду на кассе →
    скидка применяется автоматически, продажа засчитывается блогеру. Кешбек = <b>выручка после скидки × %</b>.
  </p>

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

  <?php if (empty($rows)): ?>
    <p class="bl-note">Нет продаж по промокодам за выбранный период.</p>
  <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>Промокод</th>
          <th>Имя</th>
          <th class="num">Скидка</th>
          <th class="num">Кешбек&nbsp;%</th>
          <th class="num">Чеки</th>
          <th class="num">Выручка&nbsp;₫</th>
          <th class="num">Кешбек&nbsp;₫</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><code><?= $esc($r['promocode']) ?></code></td>
            <td><?= $esc($r['name']) ?></td>
            <td class="num"><?= $fmtPct($r['discount_pct']) ?>%</td>
            <td class="num"><?= $fmtPct($r['cashback_pct']) ?>%</td>
            <td class="num"><?= (int) $r['checks'] ?></td>
            <td class="num"><?= $fmtVnd($r['revenue']) ?></td>
            <td class="num"><?= $fmtVnd($r['cashback']) ?></td>
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

<div class="card">
  <h2>Управление блогерами <span class="bl-tag"><?= count($bloggers) ?></span></h2>
  <?php if (empty($bloggers)): ?>
    <p class="bl-note">Пока нет блогеров в группе Blogers. Создайте первого выше.</p>
  <?php else: ?>
    <div class="bl-grid">
      <?php foreach ($bloggers as $b): ?>
        <div class="bl-card<?= $b['is_active'] ? '' : ' off' ?>">
          <form method="post" action="/admin/bloggers">
            <input type="hidden" name="update_blogger" value="1">
            <input type="hidden" name="client_id" value="<?= (int) $b['client_id'] ?>">
            <input type="hidden" name="dateFrom" value="<?= $esc($dateFrom) ?>">
            <input type="hidden" name="dateTo" value="<?= $esc($dateTo) ?>">
            <div class="fields">
              <div>
                <label>Промокод</label>
                <input type="text" name="promocode" value="<?= $esc($b['promocode']) ?>" required>
              </div>
              <div>
                <label>Имя</label>
                <input type="text" name="name" value="<?= $esc($b['name']) ?>">
              </div>
              <div class="wide">
                <label>Email (gmail)</label>
                <input type="email" name="email" value="<?= $esc($b['email']) ?>">
              </div>
              <div>
                <label>Скидка %</label>
                <input type="number" name="discount_pct" min="0" max="100" step="0.5" value="<?= $esc($fmtPct($b['discount_pct'])) ?>">
              </div>
              <div>
                <label>Кешбек %</label>
                <input type="number" name="cashback_pct" min="0" max="100" step="0.5" value="<?= $esc($fmtPct($b['cashback_pct'])) ?>">
              </div>
            </div>
            <div class="actions">
              <button class="btn btn-primary btn-sm" type="submit">Сохранить</button>
              <span class="cid">id <?= (int) $b['client_id'] ?><?= $b['tracked'] ? '' : ' · только в Poster' ?></span>
            </div>
          </form>
          <div class="actions" style="margin-top:.5rem">
            <form method="post" action="/admin/bloggers">
              <input type="hidden" name="client_id" value="<?= (int) $b['client_id'] ?>">
              <input type="hidden" name="dateFrom" value="<?= $esc($dateFrom) ?>">
              <input type="hidden" name="dateTo" value="<?= $esc($dateTo) ?>">
              <?php if ($b['is_active']): ?>
                <input type="hidden" name="toggle_active" value="deactivate">
                <button class="btn btn-secondary btn-sm" type="submit">Деактивировать</button>
              <?php else: ?>
                <input type="hidden" name="toggle_active" value="activate">
                <button class="btn btn-secondary btn-sm" type="submit">Активировать</button>
              <?php endif; ?>
            </form>
            <?php if (!$b['is_active']): ?><span class="cid">неактивен</span><?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
