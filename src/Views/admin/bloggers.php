<?php
/**
 * /admin/bloggers — manager view.
 *
 * @var array  $report   ['rows' => [...], 'totals' => [...]] — every blogger, active first
 * @var array  $accounts <int,string> finance accounts for the payout dropdown
 * @var array  $config   ['group_id' => int, 'payout_category_id' => int]
 * @var string $dateFrom YYYY-MM-DD
 * @var string $dateTo   YYYY-MM-DD
 */

$esc    = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$fmtVnd = static fn ($minor): string => number_format((int) round(((float) $minor) / 100), 0, '.', ' ');
$vnd    = static fn ($minor): int => (int) round(((float) $minor) / 100);
$fmtPct = static fn ($p): string => rtrim(rtrim(number_format((float) $p, 2, '.', ''), '0'), '.');

$rows     = $report['rows']   ?? [];
$totals   = $report['totals'] ?? ['bloggers' => 0, 'checks' => 0, 'revenue' => 0, 'cashback' => 0, 'paid' => 0, 'topay' => 0];
$accounts = $accounts ?? [];
$config   = $config   ?? ['group_id' => 10, 'payout_category_id' => 24];

// Toggleable report columns (promocode, "к выплате" + actions are always shown).
$cols = [
    'name'        => 'Имя',
    'discount'    => 'Скидка',
    'cashbackpct' => 'Кешбек %',
    'limit'       => 'Лимит %',
    'checks'      => 'Чеки',
    'revenue'     => 'Выручка ₫',
    'accrued'     => 'Начислено ₫',
    'paid'        => 'Выплачено ₫',
];
?>
<style>
.bl-note{color:var(--muted);font-size:.82rem;line-height:1.5;margin-bottom:1rem}
.bl-note b{color:var(--text)}
.bl-toolbar{display:flex;gap:1rem;align-items:flex-end;justify-content:space-between;flex-wrap:wrap;margin-bottom:1rem}
.bl-period{display:flex;gap:.75rem;align-items:flex-end;flex-wrap:wrap;margin:0}
.bl-period .f{display:flex;flex-direction:column}
.bl-period label{margin-bottom:.2rem}
.bl-actions{display:flex;gap:.6rem;align-items:flex-end}
.bl-cols{position:relative}
.bl-cols-pop{position:absolute;right:0;top:calc(100% + 4px);z-index:20;background:var(--surface);
    border:1px solid var(--border);border-radius:8px;padding:.5rem .6rem;min-width:170px;box-shadow:0 8px 24px rgba(0,0,0,.4)}
.bl-cols-pop label{display:flex;align-items:center;gap:.45rem;font-size:.8rem;font-weight:500;
    color:var(--text);margin:0;padding:.2rem 0;text-transform:none;letter-spacing:0;cursor:pointer}
.bl-cols-pop input{margin:0}
.bl-cfg summary{font-weight:600;font-size:.9rem;color:var(--text)}
.bl-cfg-fields{display:flex;gap:1rem;flex-wrap:wrap;margin:.9rem 0 .4rem}
.bl-cfg-fields .f{display:flex;flex-direction:column}
.bl-cfg-fields input{width:180px}
.bl-pills{display:flex;gap:.6rem;flex-wrap:wrap;margin-top:1rem}
.bl-pill{border:1px solid var(--border);border-radius:10px;padding:.5rem .85rem;font-size:.82rem;font-weight:700;background:var(--card2)}
.bl-pill .v{font-size:1.05rem;display:block;margin-top:.15rem}
.bl-pill.ok{border-color:rgba(16,185,129,.4);color:#6ee7b7}
.bl-pill.accent{border-color:rgba(184,135,70,.5);color:var(--accent)}
.num{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
.col-actions,.col-topay{white-space:nowrap}
.col-actions{width:1%;text-align:right}
.bl-topay-cell{display:flex;gap:.5rem;align-items:center;justify-content:flex-end}
.bl-pay-btn{padding:.2rem .55rem}
tr.off td{opacity:.45}
.bl-edit-btn{padding:.2rem .55rem}
.bl-edit-cell td,.bl-edit-cell{padding:0}
.bl-edit-wrap{padding:.85rem 1rem;border-left:2px solid var(--accent);background:var(--card2)}
.bl-edit-form .fields{display:grid;grid-template-columns:repeat(5,1fr);gap:.5rem .65rem;margin-bottom:.6rem}
.bl-edit-form .fields .wide{grid-column:1 / -1}
.bl-edit-form .fields label{margin-bottom:.15rem;font-size:.72rem}
.bl-edit-form .fields input{width:100%}
.bl-edit-actions{display:flex;gap:.6rem;align-items:center;flex-wrap:wrap}
.bl-edit-actions form{margin:0}
.bl-cid{margin-left:auto;color:var(--muted);font-size:.72rem}
.bl-tag{display:inline-block;font-size:.68rem;padding:.05rem .4rem;border-radius:4px;border:1px solid var(--border);color:var(--muted);margin-left:.4rem}
#blTable.hide-name .col-name,
#blTable.hide-discount .col-discount,
#blTable.hide-cashbackpct .col-cashbackpct,
#blTable.hide-limit .col-limit,
#blTable.hide-checks .col-checks,
#blTable.hide-revenue .col-revenue,
#blTable.hide-accrued .col-accrued,
#blTable.hide-paid .col-paid{display:none}
.bl-socials-row{grid-column:1 / -1;display:grid;grid-template-columns:repeat(4,1fr);gap:.5rem .65rem}
@media(max-width:680px){.bl-socials-row{grid-template-columns:1fr 1fr}}
.bl-add .fields{display:grid;grid-template-columns:1fr 1fr;gap:.5rem .65rem;margin-bottom:.8rem}
.bl-add .fields .wide{grid-column:1 / -1}
.bl-add .fields label{margin-bottom:.15rem;font-size:.72rem}
.bl-add .fields input,.bl-add .fields select{width:100%}
.bl-modal{position:fixed;inset:0;z-index:1000;display:flex;align-items:flex-start;justify-content:center;padding:6vh 1rem;overflow:auto}
.bl-modal[hidden]{display:none}
.bl-modal-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.6)}
.bl-modal-card{position:relative;z-index:1;background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:1.25rem 1.5rem;width:100%;max-width:560px;box-shadow:0 20px 60px rgba(0,0,0,.5)}
.bl-modal-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem}
.bl-modal-head h2{margin:0}
.bl-modal-x{background:none;border:none;color:var(--muted);font-size:1.6rem;line-height:1;cursor:pointer;padding:0 .25rem}
.bl-modal-x:hover{color:var(--text)}
@media(max-width:680px){.bl-edit-form .fields,.bl-add .fields{grid-template-columns:1fr 1fr}}
</style>

<details class="card bl-cfg">
  <summary>⚙ Настройки модуля</summary>
  <form method="post" action="/admin/bloggers">
    <input type="hidden" name="save_config" value="1">
    <input type="hidden" name="csrf_token" value="<?= $esc($csrf ?? '') ?>">
    <input type="hidden" name="dateFrom" value="<?= $esc($dateFrom) ?>">
    <input type="hidden" name="dateTo" value="<?= $esc($dateTo) ?>">
    <div class="bl-cfg-fields">
      <div class="f">
        <label>ID группы блогеров (Poster)</label>
        <input type="number" name="group_id" min="1" value="<?= (int) $config['group_id'] ?>" required>
      </div>
      <div class="f">
        <label>ID категории выплат (finance)</label>
        <input type="number" name="payout_category_id" min="1" value="<?= (int) $config['payout_category_id'] ?>" required>
      </div>
    </div>
    <p class="bl-note">
      В группе создаются блогеры (по умолчанию 10 «Blogers»). В категории выплат проводятся транзакции кешбека
      и по ней считается «Выплачено» (по умолчанию 24 «Bloggers»).
    </p>
    <button class="btn btn-primary" type="submit">Сохранить настройки</button>
  </form>
</details>

<div class="card">
  <h2>Блогеры — отчёт по промокодам</h2>
  <p class="bl-note">
    Промокод = имя клиента в Poster. Официант привязывает карту по промокоду на кассе → скидка применяется,
    продажа засчитывается блогеру. Кешбек = <b>выручка после скидки × %</b>. <b>✎</b> — изменить блогера;
    <b>Выплатить</b> — провести кешбек как расход Poster (категория выплат).
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

    <div class="bl-actions">
      <button type="button" class="btn btn-primary" id="addBtn">+ Добавить блогера</button>
      <div class="bl-cols">
        <button type="button" class="btn btn-secondary btn-sm" id="colsBtn">Колонки ▾</button>
        <div class="bl-cols-pop" id="colsPop" style="display:none">
          <?php foreach ($cols as $key => $label): ?>
            <label><input type="checkbox" data-col="<?= $esc($key) ?>" checked> <?= $esc($label) ?></label>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>

  <?php if (empty($rows)): ?>
    <p class="bl-note">Пока нет блогеров в группе. Создайте первого кнопкой «+ Добавить блогера».</p>
  <?php else: ?>
    <table id="blTable">
      <thead>
        <tr>
          <th class="col-promo">Промокод</th>
          <th class="col-name">Имя</th>
          <th class="col-discount num">Скидка</th>
          <th class="col-cashbackpct num">Кешбек&nbsp;%</th>
          <th class="col-limit num">Лимит&nbsp;%</th>
          <th class="col-checks num">Чеки</th>
          <th class="col-revenue num">Выручка&nbsp;₫</th>
          <th class="col-accrued num">Начислено&nbsp;₫</th>
          <th class="col-paid num">Выплачено&nbsp;₫</th>
          <th class="col-topay num">К&nbsp;выплате&nbsp;₫</th>
          <th class="col-actions"></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): $id = (int) $r['client_id']; $topayVnd = $vnd($r['topay']); ?>
          <tr class="<?= $r['is_active'] ? '' : 'off' ?>">
            <td class="col-promo">
              <code><?= $esc($r['promocode']) ?></code>
              <?php if (!$r['is_active']): ?><span class="bl-tag">неактивен</span><?php endif; ?>
            </td>
            <td class="col-name"><?= $esc($r['name']) ?></td>
            <td class="col-discount num"><?= $fmtPct($r['discount_pct']) ?>%</td>
            <td class="col-cashbackpct num"><?= $fmtPct($r['cashback_pct']) ?>%</td>
            <td class="col-limit num"><?= $fmtPct($r['limit_pct'] ?? 15) ?>%</td>
            <td class="col-checks num"><?= (int) $r['checks'] ?></td>
            <td class="col-revenue num"><?= $fmtVnd($r['revenue']) ?></td>
            <td class="col-accrued num"><?= $fmtVnd($r['cashback']) ?></td>
            <td class="col-paid num"><?= $fmtVnd($r['paid']) ?></td>
            <td class="col-topay num">
              <div class="bl-topay-cell">
                <span><?= $fmtVnd($r['topay']) ?></span>
                <?php if ($topayVnd > 0): ?>
                  <button type="button" class="btn btn-primary btn-sm bl-pay-btn"
                          data-id="<?= $id ?>" data-promo="<?= $esc($r['promocode']) ?>" data-amount="<?= $topayVnd ?>"
                          title="Выплатить">Выплатить</button>
                <?php endif; ?>
              </div>
            </td>
            <td class="col-actions">
              <button type="button" class="btn btn-secondary btn-sm bl-edit-btn" data-id="<?= $id ?>" title="Редактировать">✎</button>
            </td>
          </tr>
          <tr class="bl-edit-cell" id="edit-<?= $id ?>" hidden>
            <td colspan="11">
              <div class="bl-edit-wrap">
                <form method="post" action="/admin/bloggers" class="bl-edit-form">
                  <input type="hidden" name="update_blogger" value="1">
                  <input type="hidden" name="csrf_token" value="<?= $esc($csrf ?? '') ?>">
                  <input type="hidden" name="client_id" value="<?= $id ?>">
                  <input type="hidden" name="dateFrom" value="<?= $esc($dateFrom) ?>">
                  <input type="hidden" name="dateTo" value="<?= $esc($dateTo) ?>">
                  <?php $soc = is_array($r['socials'] ?? null) ? $r['socials'] : []; ?>
                  <div class="fields">
                    <div>
                      <label>Промокод</label>
                      <input type="text" name="promocode" value="<?= $esc($r['promocode']) ?>" required>
                    </div>
                    <div>
                      <label>Имя</label>
                      <input type="text" name="name" value="<?= $esc($r['name']) ?>">
                    </div>
                    <div>
                      <label>Скидка %</label>
                      <input type="number" name="discount_pct" min="0" max="100" step="0.5" value="<?= $esc($fmtPct($r['discount_pct'])) ?>">
                    </div>
                    <div>
                      <label>Кешбек %</label>
                      <input type="number" name="cashback_pct" min="0" max="100" step="0.5" value="<?= $esc($fmtPct($r['cashback_pct'])) ?>">
                    </div>
                    <div>
                      <label>Лимит %</label>
                      <input type="number" name="limit_pct" min="0" max="100" step="0.5" value="<?= $esc($fmtPct($r['limit_pct'] ?? 15)) ?>">
                    </div>
                    <div class="wide">
                      <label>Email (gmail)</label>
                      <input type="email" name="email" value="<?= $esc($r['email']) ?>">
                    </div>
                    <div class="bl-socials-row">
                      <div><label>Instagram</label><input type="text" name="ig" value="<?= $esc($soc['ig'] ?? '') ?>" placeholder="@handle"></div>
                      <div><label>Telegram</label><input type="text" name="tg" value="<?= $esc($soc['tg'] ?? '') ?>" placeholder="@username"></div>
                      <div><label>TikTok</label><input type="text" name="tt" value="<?= $esc($soc['tt'] ?? '') ?>" placeholder="@handle"></div>
                      <div><label>YouTube</label><input type="text" name="yt" value="<?= $esc($soc['yt'] ?? '') ?>" placeholder="@канал / ссылка"></div>
                    </div>
                  </div>
                  <div class="bl-edit-actions">
                    <button class="btn btn-primary btn-sm" type="submit">Сохранить</button>
                    <span class="bl-cid">id <?= $id ?><?= $r['tracked'] ? '' : ' · только в Poster' ?></span>
                  </div>
                </form>
                <div class="bl-edit-actions" style="margin-top:.55rem">
                  <form method="post" action="/admin/bloggers">
                    <input type="hidden" name="csrf_token" value="<?= $esc($csrf ?? '') ?>">
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
    <div class="bl-pill">Начислено ₫<span class="v"><?= $fmtVnd($totals['cashback']) ?></span></div>
    <div class="bl-pill ok">Выплачено ₫<span class="v"><?= $fmtVnd($totals['paid']) ?></span></div>
    <div class="bl-pill accent">К выплате ₫<span class="v"><?= $fmtVnd($totals['topay']) ?></span></div>
  </div>
</div>

<!-- Add-blogger modal -->
<div class="bl-modal" id="addModal" hidden>
  <div class="bl-modal-backdrop" data-close></div>
  <div class="bl-modal-card">
    <div class="bl-modal-head">
      <h2>Добавить блогера</h2>
      <button type="button" class="bl-modal-x" data-close aria-label="Закрыть">&times;</button>
    </div>
    <form method="post" action="/admin/bloggers" class="bl-add">
      <input type="hidden" name="create_blogger" value="1">
      <input type="hidden" name="csrf_token" value="<?= $esc($csrf ?? '') ?>">
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
        <div class="wide">
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
        <div class="wide">
          <label>Лимит % (макс. скидка + кешбек)</label>
          <input type="number" name="limit_pct" min="0" max="100" step="0.5" value="15">
        </div>
        <div><label>Instagram</label><input type="text" name="ig" placeholder="@handle"></div>
        <div><label>Telegram</label><input type="text" name="tg" placeholder="@username"></div>
        <div><label>TikTok</label><input type="text" name="tt" placeholder="@handle"></div>
        <div><label>YouTube</label><input type="text" name="yt" placeholder="@канал / ссылка"></div>
      </div>
      <button class="btn btn-primary" type="submit">Создать</button>
    </form>
  </div>
</div>

<!-- Payout modal -->
<div class="bl-modal" id="payModal" hidden>
  <div class="bl-modal-backdrop" data-close></div>
  <div class="bl-modal-card">
    <div class="bl-modal-head">
      <h2>Выплата кешбека</h2>
      <button type="button" class="bl-modal-x" data-close aria-label="Закрыть">&times;</button>
    </div>
    <form method="post" action="/admin/bloggers" class="bl-add">
      <input type="hidden" name="pay_blogger" value="1">
      <input type="hidden" name="csrf_token" value="<?= $esc($csrf ?? '') ?>">
      <input type="hidden" name="client_id" id="payClientId" value="">
      <input type="hidden" name="dateFrom" value="<?= $esc($dateFrom) ?>">
      <input type="hidden" name="dateTo" value="<?= $esc($dateTo) ?>">
      <p class="bl-note" style="margin-bottom:.8rem">Блогер: <b id="payPromo">—</b></p>
      <div class="fields">
        <div class="wide">
          <label>Сумма выплаты (₫)</label>
          <input type="number" name="amount_vnd" id="payAmount" min="1" step="1" required>
        </div>
        <div class="wide">
          <label>Счёт</label>
          <select name="account_id" required>
            <?php if (empty($accounts)): ?>
              <option value="">— нет счетов —</option>
            <?php else: foreach ($accounts as $aid => $aname): ?>
              <option value="<?= (int) $aid ?>"><?= $esc($aname) ?></option>
            <?php endforeach; endif; ?>
          </select>
        </div>
        <div class="wide">
          <label>Комментарий (в транзакцию Poster)</label>
          <input type="text" name="comment" id="payComment" maxlength="255">
        </div>
      </div>
      <button class="btn btn-primary" type="submit">Выплатить</button>
    </form>
  </div>
</div>

<script>
(function () {
    var payBy = <?= json_encode((string) ($userEmail ?? ''), JSON_UNESCAPED_UNICODE) ?>;

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
    function applyCols() {
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
            applyCols();
        });
    });
    applyCols();

    var colsBtn = document.getElementById('colsBtn');
    var colsPop = document.getElementById('colsPop');
    if (colsBtn && colsPop) {
        colsBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            colsPop.style.display = (colsPop.style.display === 'none') ? 'block' : 'none';
        });
        document.addEventListener('click', function (e) {
            if (colsPop.style.display !== 'none' && !colsPop.contains(e.target) && e.target !== colsBtn) {
                colsPop.style.display = 'none';
            }
        });
    }

    // Modals (add + pay)
    function bindModal(modal, openBtnOrFn) {
        if (!modal) return;
        modal.querySelectorAll('[data-close]').forEach(function (el) {
            el.addEventListener('click', function () { modal.hidden = true; });
        });
    }
    var addModal = document.getElementById('addModal');
    var addBtn = document.getElementById('addBtn');
    if (addBtn && addModal) {
        addBtn.addEventListener('click', function () {
            addModal.hidden = false;
            var f = addModal.querySelector('input[name="promocode"]');
            if (f) f.focus();
        });
    }
    bindModal(addModal);

    var payModal = document.getElementById('payModal');
    bindModal(payModal);
    document.querySelectorAll('.bl-pay-btn').forEach(function (b) {
        b.addEventListener('click', function () {
            if (!payModal) return;
            payModal.querySelector('#payClientId').value = b.dataset.id;
            payModal.querySelector('#payPromo').textContent = b.dataset.promo || ('id ' + b.dataset.id);
            var amt = payModal.querySelector('#payAmount');
            amt.value = b.dataset.amount || '';
            payModal.querySelector('#payComment').value =
                'BLOGGER ' + (b.dataset.promo ? b.dataset.promo + ' ' : '') + 'ID=' + b.dataset.id + (payBy ? ' by ' + payBy : '');
            payModal.hidden = false;
            amt.focus();
        });
    });

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        if (addModal) addModal.hidden = true;
        if (payModal) payModal.hidden = true;
    });
})();
</script>
