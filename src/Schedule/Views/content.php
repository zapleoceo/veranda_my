<?php
/**
 * /schedule — view (state-driven).
 *
 * Provided by ScheduleController::renderPage():
 *   $state      — current snapshot (blocks + shifts + templates + rules)
 *   $employees  — roster from staff_tags + rate overlay
 *   $halls      — Poster halls (id, name, icon)
 *   $zones      — schedule_zones rows
 *   $snapshots  — named version pills
 *   $periodFrom / $periodTo — 'YYYY-MM-DD'
 *   $days       — output of PeriodBuilder::build()
 *   $heatmap    — output of HeatmapBuilder::build() (perDayBlock + agg)
 *
 * The view stays as thin as possible: it renders the SKELETON of the
 * grid + payroll + heatmap, then JS (schedule.js) fills in everything
 * dynamic on DOMContentLoaded via recomputeSummaries() →
 *   • per-day ⚠ / ✓ + ₫/день + totals row
 *   • payroll table rows
 *   • heatmap live recompute
 *
 * Computation duplicated between PHP and JS used to be the biggest
 * source of drift (PHP had stale 3-rule warnings while JS used 5
 * rule types). One source of truth now: JS.
 */

use App\Schedule\Domain\BlockColor;
use App\Schedule\Domain\TimeRange;
use App\Schedule\Services\HeatmapBuilder;

$periodFrom ??= date('Y-m-d', strtotime('monday this week'));
$periodTo   ??= date('Y-m-d', strtotime($periodFrom . ' +13 days'));
$state      ??= ['blocks' => [], 'shifts' => new \stdClass(), 'templates' => []];
$employees  ??= [];
$halls      ??= [];
$zones      ??= [];
$snapshots  ??= [];
$days       ??= [];
$heatmap    ??= ['hourStart' => 8, 'hourEnd' => 24, 'dayCount' => 0,
                 'perDayBlock' => [], 'aggHourTotal' => array_fill(0, 24, 0),
                 'aggHourAvg' => array_fill(0, 24, 0)];

$schDowRu = ['вс','пн','вт','ср','чт','пт','сб'];
$schMonRu = ['','янв','фев','мар','апр','мая','июн','июл','авг','сен','окт','ноя','дек'];

// Standalone-invocation fallback (when the view is included without
// the controller pre-building $days). Controller-driven path skips.
if ($days === []) {
    $cur = (int) strtotime($periodFrom);
    $end = (int) strtotime($periodTo);
    if ($cur !== false && $end !== false && $cur <= $end) {
        $i = 0;
        while ($cur <= $end && $i < 366) {
            $days[] = [
                'idx'     => $i,
                'iso'     => date('Y-m-d', $cur),
                'date'    => (string) date('j', $cur),
                'mon'     => $schMonRu[(int) date('n', $cur)],
                'dow'     => $schDowRu[(int) date('w', $cur)],
                'weekend' => in_array((int) date('w', $cur), [0, 5, 6], true),
            ];
            $cur = (int) strtotime('+1 day', $cur);
            $i++;
        }
    }
}

// ─── Normalize shifts (server-side stdClass becomes [] in PHP via decode) ───
$shifts = $state['shifts'] ?? [];
if (!is_array($shifts)) $shifts = [];

// ─── Lookups
$empById = [];
foreach ($employees as $e) $empById[(int)$e['id']] = $e;

// Blocks of type=hall reference a Poster hall_id. The block's stored
// `name` is captured at creation time and may go stale if the hall is
// renamed in Poster — overlay the live name/icon here so the UI always
// matches what the Poster admin sees.
$hallById = [];
foreach ($halls as $h) {
    if (!is_array($h)) continue;
    $hid = (int)($h['id'] ?? 0);
    if ($hid > 0) $hallById[$hid] = $h;
}

$blocks       = $state['blocks'] ?? [];
$schHourStart = $heatmap['hourStart'];
$schHourEnd   = $heatmap['hourEnd'];

// ─── Grid columns ────────────────────────────────────────────────
$gridColsArr = ['72px', '36px'];          // date + dow
foreach ($blocks as $block) {
    foreach ($block['slots'] as $_) $gridColsArr[] = '92px';
    $gridColsArr[] = '14px';              // divider after each block
}
$gridColsArr[] = '38px';                  // + add-block btn column
$gridColsArr[] = '56px';                  // warn
$gridColsArr[] = '70px';                  // budget
$gridCols = implode(' ', $gridColsArr);

// ─── Cheap period totals for the topbar metric. Single walk; only
// values JS doesn't compute back into the topbar (the per-day ⚠/✓
// cells, totals row, and payroll table are all JS-driven on boot).
$totalHours  = 0.0;
$totalSalary = 0.0;
foreach ($days as $d) {
    foreach ($blocks as $block) {
        foreach ($block['slots'] as $sIdx => $_) {
            $sh = $shifts[$d['iso']][$block['id'] . ':' . $sIdx] ?? null;
            if (!$sh) continue;
            $hrs = TimeRange::toHours((string)($sh['end'] ?? '')) - TimeRange::toHours((string)($sh['start'] ?? ''));
            if ($hrs > 0) {
                $totalHours  += $hrs;
                $rate = (int) ($empById[(int)($sh['emp_id'] ?? 0)]['rate_per_hour'] ?? 0);
                $totalSalary += $hrs * $rate;
            }
        }
    }
}
?>

<div class="container sch-wrap">

  <?php
  // Distinct employees in the period (cheap walk for the topbar metric).
  $empSet = [];
  foreach ($days as $d) {
      foreach ($blocks as $block) {
          foreach ($block['slots'] as $sIdx => $_) {
              $sh = $shifts[$d['iso']][$block['id'] . ':' . $sIdx] ?? null;
              if ($sh && !empty($sh['emp_id'])) $empSet[(int)$sh['emp_id']] = true;
          }
      }
  }
  $empCount = count($empSet);
  ?>

  <!-- Compact unified top bar: period selector + inline metrics + buttons.
       Wraps gracefully on narrow screens; on desktop it's a single row. -->
  <div class="sch-topbar" data-help-abs="Период просмотра, сводные метрики и действия. Стрелки сдвигают период сохраняя длину; даты задают диапазон руками; пресеты — быстрые шаблоны.">
    <button class="sch-period-arrow" id="schPeriodPrev" title="Сдвинуть период назад">◀</button>
    <div class="sch-period-range">
      <input type="date" id="schPeriodFrom" class="sch-date-input" value="<?= htmlspecialchars($periodFrom) ?>">
      <span style="color: var(--muted)">→</span>
      <input type="date" id="schPeriodTo" class="sch-date-input" value="<?= htmlspecialchars($periodTo) ?>">
    </div>
    <button class="sch-period-arrow" id="schPeriodNext" title="Сдвинуть период вперёд">▶</button>
    <span class="sch-period-stats"><?= count($days) ?>д</span>

    <div class="sch-period-presets">
      <button data-period-preset="7">Неделя</button>
      <button data-period-preset="14" class="active">2 недели</button>
      <button data-period-preset="30">Месяц</button>
      <button data-period-preset="custom">Произв.</button>
    </div>

    <span class="sch-topbar-sep"></span>

    <span class="sch-metric-inline" data-help-abs="Сумма часов всех смен в периоде."
          title="Часы за период"><span class="lbl">Часы</span> <b><?= number_format((int)round($totalHours), 0, '.', ' ') ?></b></span>
    <span class="sch-metric-inline gold" data-help-abs="Прогноз ФОТ: ставка × часы."
          title="Прогноз ФОТ"><span class="lbl">ЗП</span> <b><?= $totalSalary > 0 ? number_format($totalSalary/1_000_000, 2, '.', '') . 'M' : '—' ?></b></span>
    <span class="sch-metric-inline" data-help-abs="Сколько разных сотрудников задействовано в периоде."
          title="Уникальные сотрудники"><span class="lbl">Сотр</span> <b><?= $empCount ?></b></span>
    <span class="sch-metric-inline" data-metric="warn-days"
          data-help-abs="Дней с хотя бы одной проблемой (нет старшего, двойное бронирование, не в графике). Hover на ⚠ в строке дня — конкретная причина."
          title="Дней с предупреждениями"><b>0 ⚠</b></span>

    <span style="flex:1"></span>

    <button class="sch-btn" id="schStaffBtn"
            data-help-abs="Управление персоналом: кто в графике, кто может быть старшим, ставка ₫/час.">👥 Персонал</button>
    <button class="sch-btn" id="schCopyWeek"
            data-help-abs="Скопировать смены этой недели на следующую — еженедельное планирование одной кнопкой.">↳ Скопировать неделю</button>
    <button class="sch-btn primary" id="schSaveBtn"
            data-help-abs="Сохранить текущий черновик (без создания новой версии).">Сохранить</button>
    <button type="button" id="schHelpBtn" class="sch-help-btn"
            aria-pressed="false" title="Подсказки по интерфейсу"
            data-help-abs="Включить/выключить режим подсказок.">?</button>
  </div>

  <!-- Rule constructor — collapsible panel. JS owns the list; PHP renders
       only the shell so the page is layout-stable before boot. -->
  <details class="sch-rules" id="schRulesPanel" data-help-abs="Конструктор правил проверки графика. Тогглы включают/выключают, × удаляет, «+ Правило» добавляет новое. Изменения сразу пересчитывают ⚠ в столбце предупреждений.">
    <summary>
      <span class="sch-rules-title">⚙ Правила графика</span>
      <span class="sch-rules-count" id="schRulesCount"></span>
    </summary>
    <div class="sch-rules-body">
      <div class="sch-rules-list" id="schRulesList"></div>
      <div class="sch-rules-add" id="schRulesAdd">
        <button type="button" class="sch-btn ghost" id="schRulesAddBtn">+ Добавить правило</button>
      </div>
      <form class="sch-rules-form" id="schRulesForm" hidden>
        <div class="sch-rules-form-row">
          <label>Тип
            <select id="schRuleType">
              <option value="startTime">Старт смены в HH:MM</option>
              <option value="endTime">Конец смены в HH:MM</option>
              <option value="needSenior">Нет старшего</option>
              <option value="doubleBooking">Двойное бронирование</option>
              <option value="offRoster">Назначен «не в графике»</option>
            </select>
          </label>
          <label>Применить к
            <select id="schRuleScope">
              <option value="all">Все блоки</option>
              <option value="senior">Старшие смены</option>
              <option value="main">Главный зал</option>
              <option value="banya">Баня</option>
              <option value="custom">Кастомные (Беседка/Терраса/…)</option>
            </select>
          </label>
          <label class="sch-rules-time-field">Время
            <input type="text" id="schRuleValue" placeholder="10:00" maxlength="5">
          </label>
          <label class="sch-rules-weekend-field">Время Пт/Сб/Вс
            <input type="text" id="schRuleWeekendValue" placeholder="23:00" maxlength="5">
          </label>
        </div>
        <div class="sch-rules-form-row">
          <label style="flex:1">Название
            <input type="text" id="schRuleName" placeholder="Сгенерируется автоматически">
          </label>
          <button type="button" class="sch-btn ghost" id="schRuleCancel">Отмена</button>
          <button type="submit" class="sch-btn primary" id="schRuleAddSave">Добавить</button>
        </div>
      </form>
    </div>
  </details>

  <!-- Toolbar -->
  <div class="sch-toolbar">
    <span style="flex:1"></span>
    <button class="sch-btn ghost" id="schClearPeriod"
            data-help-abs="Удалить все смены за выбранный период. С подтверждением.">Очистить период</button>
  </div>

  <!-- THE GRID — state-driven structure -->
  <div class="sch-grid-scroll" data-help-abs="Сетка дней (вниз) × слотов в блоках.
• Клик в ячейку → форма назначения сотрудника и времени.
• Drag смены в другую ячейку → перемещение (если там кто-то был — поменяются местами).
• Ctrl+drag (⌘+drag на Mac) → копия в новую ячейку, оригинал остаётся.
• Drag шаблона времени в ячейку → меняет время или открывает форму с подставленным временем.">
    <div class="sch-grid" style="grid-template-columns: <?= $gridCols ?>;">

      <!-- ────── Block headers row ────── -->
      <div class="sch-block-head" style="grid-column: span 2; background: rgba(255,255,255,.025); border-bottom: 1.5px solid var(--border);">
        <span class="sch-block-name" style="color: var(--muted); font-size: 11px;">Дата</span>
      </div>

      <?php foreach ($blocks as $blkIdx => $block):
          $color    = BlockColor::of($block);
          $headCls  = BlockColor::headerClass($color);
          $divCls   = BlockColor::dividerClass($color);
          $slotsCnt = count($block['slots']);

          // Live overlay: if this block is bound to a Poster hall, prefer
          // the live hall name/icon over whatever was stored on the block.
          // Falls back to stored values when the hall is missing (e.g.
          // deleted in Poster or cache miss).
          $isHall   = ($block['type'] ?? '') === 'hall' && !empty($block['hall_id']);
          $hallRow  = $isHall ? ($hallById[(int)$block['hall_id']] ?? null) : null;
          $blkName  = $hallRow['name'] ?? ($block['name'] ?? '');
          $blkIcon  = $hallRow['icon'] ?? ($block['icon'] ?? '');
      ?>
        <div class="sch-block-head <?= $headCls ?>" style="grid-column: span <?= $slotsCnt ?>;"
             data-block-id="<?= htmlspecialchars($block['id']) ?>"
             data-help-abs="<?= htmlspecialchars($blkName) ?>. Внутри <?= $slotsCnt ?> слот(а/ов) — это типичное число людей в этой зоне. В верхней строке шапки — маленькие кнопки + слот и удалить блок.">
          <span class="sch-col-actions">
            <button title="Добавить слот" class="sch-block-add-slot"
                    data-block-idx="<?= $blkIdx ?>"
                    data-help-abs="Добавить новый слот (колонку) в конец блока «<?= htmlspecialchars($blkName) ?>».">+</button>
            <button title="Удалить блок" class="sch-block-del"
                    data-block-idx="<?= $blkIdx ?>"
                    data-help-abs="Удалить блок целиком. Все смены в нём пропадут.">⋮</button>
          </span>
          <span class="sch-block-head-main">
            <span class="sch-block-icon"><?= htmlspecialchars($blkIcon) ?></span>
            <span class="sch-block-name"><?= htmlspecialchars($blkName) ?></span>
          </span>
        </div>
        <div class="sch-divider <?= $divCls ?> head-row"></div>
      <?php endforeach; ?>

      <div class="sch-add-block-cell" data-help-abs="Добавить целый блок: либо новый Hall из Poster, либо кастомная зона (Беседка / Терраса / VIP).">
        <button class="sch-add-block-btn" title="Добавить новый блок" id="schAddBlockBtn">+</button>
      </div>

      <div class="sch-block-head" style="grid-column: span 2; background: rgba(255,255,255,.025); border-bottom: 1.5px solid var(--border);"
           data-help-abs="Сводка дня: красный ⚠ если нет старшего, и прогноз ЗП этого дня.">
        <span class="sch-block-name" style="color: var(--muted); font-size: 10px; text-align: center; width: 100%;">Сводка дня</span>
      </div>

      <!-- ────── Slot sub-headers row ────── -->
      <div class="sch-slot-head"></div>
      <div class="sch-slot-head"></div>

      <?php foreach ($blocks as $blkIdx => $block):
          $divCls = BlockColor::dividerClass(BlockColor::of($block));
          foreach ($block['slots'] as $sIdx => $slot):
      ?>
        <div class="sch-slot-head" data-help-abs="Слот <?= $sIdx + 1 ?>. Это просто колонка-«позиция» — показывает, сколько человек одновременно работает в этом блоке. Время каждой смены задаётся в ячейке. × справа — удалить эту колонку (с конфирмом, если внутри есть смены).">
          <span class="sch-slot-num"><?= $sIdx + 1 ?></span>
          <button class="sch-slot-del" title="Удалить этот слот"
                  data-block-idx="<?= $blkIdx ?>" data-slot-idx="<?= $sIdx ?>">×</button>
        </div>
      <?php endforeach; ?>
        <div class="sch-divider <?= $divCls ?>"></div>
      <?php endforeach; ?>

      <div class="sch-add-block-cell" style="border-bottom: 1px solid var(--border);"></div>

      <div class="sch-slot-head" data-help-abs="Предупреждения дня. Три проверки:
1) Нет старшего — день с любыми сменами, но без смены в блоке «Старшие смены».
2) Двойное бронирование — один и тот же сотрудник в перекрывающихся сменах в этот день.
3) Не в графике — назначен сотрудник, у которого в модалке «Персонал» снят флажок «В графике».
Hover на ⚠ в конкретном дне покажет причины.">⚠</div>
      <div class="sch-slot-head" data-help-abs="Прогноз ФОТ за этот день.">₫/день</div>


      <!-- ────── Data rows ────── -->
      <?php
      $emptyDays = empty($days);
      if ($emptyDays):
      ?>
        <div style="grid-column: 1 / -1; padding: 40px; text-align: center; color: var(--muted);">
          Выбран некорректный период. Скорректируйте даты в селекторе сверху.
        </div>
      <?php endif; ?>

      <?php foreach ($days as $d):
          $weekendCls = $d['weekend'] ? ' weekend' : '';
      ?>
        <div class="sch-row<?= $weekendCls ?> sch-date-cell">
          <span class="sch-day-num"><?= htmlspecialchars($d['date']) ?></span>
          <span class="sch-day-mon"><?= htmlspecialchars($d['mon']) ?></span>
        </div>
        <div class="sch-row<?= $weekendCls ?> sch-dow-cell"><?= htmlspecialchars($d['dow']) ?></div>

        <?php foreach ($blocks as $block):
            $color  = BlockColor::of($block);
            $divCls = BlockColor::dividerClass($color);
            foreach ($block['slots'] as $sIdx => $slot):
                $sh = $shifts[$d['iso']][$block['id'] . ':' . $sIdx] ?? null;
        ?>
          <div class="sch-cell<?= $weekendCls ?>"
               data-block="<?= htmlspecialchars($block['id']) ?>"
               data-slot="<?= $sIdx ?>"
               data-day-iso="<?= htmlspecialchars($d['iso']) ?>"
               data-day-idx="<?= $d['idx'] ?>">
            <?php if ($sh):
                $emp  = $empById[(int)($sh['emp_id'] ?? 0)] ?? null;
                $name = $emp['name'] ?? ($sh['emp_name'] ?? '?');
                $star = ($color === BlockColor::SENIOR && ($emp['can_be_senior'] ?? false)) ? ' ★' : '';
                $time = TimeRange::shortRange((string)($sh['start'] ?? ''), (string)($sh['end'] ?? ''));
                $fullTitle = $name . $star . ' · ' . $time;
            ?>
              <div class="sch-shift <?= $color ?>" draggable="true" title="<?= htmlspecialchars($fullTitle) ?>">
                <span class="sch-name"><?= htmlspecialchars($name . $star) ?></span>
                <span class="sch-time"><?= htmlspecialchars($time) ?></span>
              </div>
            <?php else: ?>
              <span class="sch-empty"><?= ($color === BlockColor::BANYA || $color === BlockColor::CUSTOM) ? '—' : '+' ?></span>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
          <div class="sch-divider <?= $divCls ?><?= $weekendCls ?>"></div>
        <?php endforeach; ?>

        <div class="sch-add-block-cell<?= $weekendCls ?>"></div>

        <!-- ⚠/✓ + ₫/day cells are filled by JS recomputeSummaries() on
             boot (richer rule engine + per-day reasons in tooltip). PHP
             renders neutral placeholders so layout doesn't shift. -->
        <div class="sch-warn-cell ok<?= $weekendCls ?>" data-day-iso="<?= htmlspecialchars($d['iso']) ?>" title="">·</div>
        <div class="sch-budget-cell<?= $weekendCls ?>" data-day-iso="<?= htmlspecialchars($d['iso']) ?>">—</div>
      <?php endforeach; ?>


      <!-- ────── Totals row — populated by JS recomputeSummaries() on boot ────── -->
      <?php if (!empty($days)): ?>
        <div class="sch-totals-cell" style="grid-column: span 2;"
             data-help-abs="Итого за период: количество назначенных смен в каждом слоте + общий ФОТ.">Итого</div>
        <?php foreach ($blocks as $block):
            foreach ($block['slots'] as $sIdx => $_):
        ?>
          <div class="sch-totals-cell" data-totals-slot="<?= htmlspecialchars($block['id']) ?>:<?= $sIdx ?>">0</div>
        <?php endforeach; ?>
          <div class="sch-divider"></div>
        <?php endforeach; ?>
        <div class="sch-add-block-cell"></div>
        <div class="sch-warn-cell bad" data-totals="warn" style="background: rgba(184,135,70,.08); font-weight: 700;">0 ⚠</div>
        <div class="sch-totals-cell" data-totals="salary" style="font-size: 12px;">
          <?= $totalSalary > 0 ? number_format($totalSalary / 1_000_000, 2, '.', '') . 'M' : '—' ?>
        </div>
      <?php endif; ?>

    </div>
  </div>


  <!-- ════════ Payroll forecast — per-employee breakdown ════════ -->
  <!-- Payroll table — empty skeleton; JS recomputePayroll() (in
       schedule.js) fills rows + totals from App.state on boot and on
       every shift edit. -->
  <section class="sch-payroll-section"
           data-help-abs="Прогноз ЗП по каждому сотруднику за выбранный период. Часы × ставка из staff_tags. Пересчитывается живьём при правках смен.">
    <div class="sch-payroll-head">
      <h3>💰 Прогноз ЗП</h3>
      <span class="sch-payroll-period"><?= count($days) ?>д · <?= htmlspecialchars($periodFrom) ?> — <?= htmlspecialchars($periodTo) ?></span>
    </div>
    <table class="sch-payroll-table" id="schPayrollTable">
      <thead>
        <tr>
          <th class="who">Сотрудник</th>
          <th class="tag">Роль</th>
          <th class="num">Часы</th>
          <th class="num">Ставка ₫/ч</th>
          <th class="num">ЗП ₫</th>
        </tr>
      </thead>
      <tbody>
        <tr><td colspan="5" class="empty">Загрузка…</td></tr>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="2">Итого</td>
          <td class="num"><span data-total="hours">0</span></td>
          <td class="num">—</td>
          <td class="num zp"><span data-total="zp">—</span></td>
        </tr>
      </tfoot>
    </table>
  </section>


  <!-- ════════ Coverage by hour — heatmap + aggregate histogram ════════ -->
  <section class="sch-coverage-section"
           data-help-abs="Загрузка по часам: сколько людей одновременно работает в каждом временном интервале. Сверху — heatmap, снизу — горизонтальная гистограмма средней нагрузки по часам за весь период. Шаг и фильтр меняются live.">
    <div class="sch-coverage-head">
      <h3>📊 Загрузка по часам</h3>
      <select id="schBucketSize" title="Шаг группировки часов" data-help-abs="1 час даёт детальную картину, 2-3 часа агрегируют.">
        <option value="1">1 час</option>
        <option value="2" selected>2 часа</option>
        <option value="3">3 часа</option>
        <option value="4">4 часа</option>
      </select>
      <select id="schCoverageFilter" title="Фильтр по блокам" data-help-abs="Фильтр: всех / только старших / только определённый блок.">
        <option value="all">Всех</option>
        <option value="senior">Только старших</option>
        <option value="main">Главный зал</option>
        <option value="banya">Только баню</option>
        <option value="custom">Только беседку</option>
      </select>
      <span class="sch-coverage-legend">
        <span>мало</span>
        <span class="swatch" style="background: rgba(184,135,70,0.05)"></span>
        <span class="swatch" style="background: rgba(184,135,70,0.30)"></span>
        <span class="swatch" style="background: rgba(184,135,70,0.60)"></span>
        <span class="swatch" style="background: rgba(184,135,70,0.95)"></span>
        <span>много</span>
      </span>
    </div>

    <?php
    // ─── Heatmap inputs ─ uses pre-computed $heatmap['perDayBlock']
    // from HeatmapBuilder (controller) — no PHP-side rewalk of state.
    $schBucket = 2;   // default; JS overrides per dropdown
    $colCount  = (int) ceil(($schHourEnd - $schHourStart) / $schBucket);
    $dayCount  = max(1, count($days));
    $perDayBlock = $heatmap['perDayBlock'] ?? [];

    // Which block colours actually have blocks? Drives how many numbers
    // we squeeze into each cell. Order is canonical (senior → custom).
    $schColorsPresent = [];
    foreach (BlockColor::ALL as $c) {
        foreach ($blocks as $b) {
            if (BlockColor::of($b) === $c) { $schColorsPresent[] = $c; break; }
        }
    }

    // Per-day, per-colour buckets. Sum of per-cell colour values drives
    // intensity (so colour reflects total simultaneous people).
    $schDayBuckets = [];   // [day_idx][colour] → list of {from,to,value}
    $maxCount      = 0.0;
    foreach ($days as $d) {
        $perColor = [];
        $perBlock = $perDayBlock[$d['idx']] ?? null;
        foreach ($schColorsPresent as $c) {
            $hours = $perBlock[$c] ?? array_fill(0, 24, 0);
            $perColor[$c] = HeatmapBuilder::bucketize($hours, $schHourStart, $schHourEnd, $schBucket);
        }
        $schDayBuckets[$d['idx']] = $perColor;
        for ($i = 0; $i < $colCount; $i++) {
            $total = 0.0;
            foreach ($schColorsPresent as $c) $total += $perColor[$c][$i]['value'];
            $maxCount = max($maxCount, $total);
        }
    }
    // Avg row: sum per-hour over days / dayCount, per colour, then bucket.
    $schAvgBuckets = [];
    foreach ($schColorsPresent as $c) {
        $hourly = array_fill(0, 24, 0);
        foreach ($days as $d) {
            $hours = $perDayBlock[$d['idx']][$c] ?? [];
            foreach ($hours as $h => $v) $hourly[$h] += $v;
        }
        $hourly = array_map(static fn ($v) => $v / $dayCount, $hourly);
        $schAvgBuckets[$c] = HeatmapBuilder::bucketize($hourly, $schHourStart, $schHourEnd, $schBucket);
    }
    for ($i = 0; $i < $colCount; $i++) {
        $total = 0.0;
        foreach ($schColorsPresent as $c) $total += $schAvgBuckets[$c][$i]['value'];
        $maxCount = max($maxCount, $total);
    }
    $maxCount = max(1.0, $maxCount);

    // Per-colour legend labels — actual block names (Poster hall overlay
    // applied where available). Two blocks sharing the same colour →
    // joined with "/" so the legend stays single-line.
    $schColorBlockNames = [];
    foreach ($schColorsPresent as $c) {
        $names = [];
        foreach ($blocks as $b) {
            if (BlockColor::of($b) !== $c) continue;
            $isHall = ($b['type'] ?? '') === 'hall' && !empty($b['hall_id']);
            $hall   = $isHall ? ($hallById[(int)$b['hall_id']] ?? null) : null;
            $names[] = (string) ($hall['name'] ?? ($b['name'] ?? $c));
        }
        $schColorBlockNames[$c] = implode('/', $names);
    }
    $schBreakdownHint = implode(' | ', array_values($schColorBlockNames));
    $schColorNamesCsv = implode(',', array_map(
        static fn ($c) => $c . ':' . str_replace(',', ' ', $schColorBlockNames[$c]),
        $schColorsPresent
    ));
    ?>
    <!-- Heatmap matrix — one continuous grid: corner + col-heads +
         per-day rows + a merged "Сред." footer row.
         Cell format: per-colour breakdown joined by "|" (senior|main|
         banya|custom), only colours that actually have blocks. Colour
         intensity = sum of all the displayed numbers. -->
    <div class="sch-cov-grid" id="schCovGrid"
         style="--cov-cols: <?= $colCount ?>;"
         data-color-order="<?= htmlspecialchars(implode(',', $schColorsPresent)) ?>"
         data-color-names="<?= htmlspecialchars($schColorNamesCsv) ?>"
         title="В ячейке: <?= htmlspecialchars($schBreakdownHint) ?>">
      <div class="sch-cov-corner" title="Разбивка по блокам: <?= htmlspecialchars($schBreakdownHint) ?>">День \ Час<br><small><?= htmlspecialchars($schBreakdownHint) ?></small></div>
      <?php
      // Pull col-headers from any colour's buckets — all colours share
      // the same columns. Defensive against empty $schColorsPresent.
      $headBuckets = $schColorsPresent !== [] ? ($schAvgBuckets[$schColorsPresent[0]] ?? []) : [];
      foreach ($headBuckets as $b): ?>
        <div class="sch-cov-col-head"><?= sprintf('%02d–%02d', $b['from'], $b['to']) ?></div>
      <?php endforeach; ?>

      <?php foreach ($days as $d):
          $weekendCls = $d['weekend'] ? ' weekend' : '';
          $perColor   = $schDayBuckets[$d['idx']];
      ?>
        <div class="sch-cov-row-head<?= $weekendCls ?>"><?= htmlspecialchars($d['dow']) ?> <?= htmlspecialchars($d['date']) ?></div>
        <?php for ($i = 0; $i < $colCount; $i++):
            $values = [];
            $total  = 0.0;
            foreach ($schColorsPresent as $c) {
                $v = $perColor[$c][$i]['value'];
                $values[] = $v;
                $total   += $v;
            }
            $a = HeatmapBuilder::cellAttrs($total, $maxCount);
            $label = HeatmapBuilder::formatCellLabel($values, false);
        ?>
          <div class="sch-cov-cell"
               data-count="<?= (int) $total ?>"
               style="background: rgba(184,135,70,<?= $a['alpha'] ?>); color: <?= $a['text'] ?>;"
               title="<?= htmlspecialchars($schBreakdownHint . ': ' . $label) ?>"><?= $label ?></div>
        <?php endfor; ?>
      <?php endforeach; ?>

      <!-- Footer "average" row — same column model, lighter text style. -->
      <div class="sch-cov-row-head avg" title="Среднее по всем дням периода (<?= count($days) ?> д)">Сред.</div>
      <?php for ($i = 0; $i < $colCount; $i++):
          $values = [];
          $total  = 0.0;
          foreach ($schColorsPresent as $c) {
              $v = $schAvgBuckets[$c][$i]['value'];
              $values[] = $v;
              $total   += $v;
          }
          $a = HeatmapBuilder::cellAttrs($total, $maxCount);
          $label = HeatmapBuilder::formatCellLabel($values, true);
      ?>
        <div class="sch-cov-cell avg"
             data-count="<?= round($total, 1) ?>"
             style="background: rgba(184,135,70,<?= $a['alpha'] ?>); color: <?= $a['text'] ?>;"
             title="<?= htmlspecialchars('Сред. ' . $schBreakdownHint . ': ' . $label) ?>"><?= $label ?></div>
      <?php endfor; ?>
    </div>
  </section>

  <!-- Stats payload for JS heatmap rebucketization. Built from the
       same $heatmap that drove the server-rendered matrix above — single
       source of truth (HeatmapBuilder, see src/Schedule/Services). -->
  <script id="schStatsData" type="application/json"><?= json_encode(
      (new HeatmapBuilder($schHourStart, $schHourEnd))->buildStatsPayload($heatmap, $days),
      JSON_UNESCAPED_UNICODE
  ) ?></script>

  <?php
  // ─── Boot payload for JS state machine ───
  $schBootPayload = [
      'state'     => $state,
      'period'    => ['from' => $periodFrom, 'to' => $periodTo],
      'employees' => $employees,
      'halls'     => $halls,
      'zones'     => $zones,
      'snapshots' => $snapshots,
  ];
  ?>
  <script id="schBootData" type="application/json"><?= json_encode($schBootPayload, JSON_UNESCAPED_UNICODE) ?></script>


  <!-- Versions (named only). The current "draft" is implicit — every edit
       auto-saves into it; named versions are explicit save points the user
       can return to / rename / delete. -->
  <div class="sch-snapshots" data-help-abs="Именованные версии графика. Кнопкой «Сохранить версию» создаётся именованная точка восстановления. Клик по версии — загрузить. Hover на pill: 🔗 — публичная ссылка (read-only, без ЗП), ✏ — переименовать, × — удалить. Автосохранения сюда не попадают — они просто обновляют текущий черновик.">
    <span class="sch-snap-label">Версии:</span>
    <?php foreach ($snapshots as $snap):
        $when      = preg_replace('/^(\d{4})-(\d{2})-(\d{2}) (\d{2}:\d{2}).*$/', '$3.$2 $4', (string)$snap['created_at']);
        $shareCode = (string) ($snap['share_code'] ?? '');
        $shareUrl  = $shareCode !== '' ? '/schedule/v/' . $shareCode : '';
    ?>
      <span class="sch-snap-pill" data-snap-id="<?= (int)$snap['id'] ?>" data-snap-label="<?= htmlspecialchars($snap['label']) ?>" data-share-url="<?= htmlspecialchars($shareUrl) ?>">
        <span class="sch-snap-name"><?= htmlspecialchars($snap['label']) ?></span>
        <span class="when"><?= htmlspecialchars($when) ?></span>
        <?php if ($shareUrl !== ''): ?>
          <button class="sch-snap-btn sch-snap-share" title="Скопировать публичную ссылку (read-only)" data-share-url="<?= htmlspecialchars($shareUrl) ?>">🔗</button>
        <?php endif; ?>
        <button class="sch-snap-btn sch-snap-rename" title="Переименовать" data-snap-id="<?= (int)$snap['id'] ?>">✏</button>
        <button class="sch-snap-btn sch-snap-del"    title="Удалить"        data-snap-id="<?= (int)$snap['id'] ?>">×</button>
      </span>
    <?php endforeach; ?>
    <?php if (empty($snapshots)): ?>
      <span class="sch-snap-empty" style="color: var(--muted); font-size: 12px;">Именованных версий пока нет. «Сохранить версию» создаст первую.</span>
    <?php endif; ?>
    <span style="flex:1"></span>
    <button class="sch-btn" id="schSaveSnapBtn">Сохранить версию</button>
  </div>

</div>


<!-- ════════ Inline popover for editing a single cell ════════ -->
<div id="schPopover" class="sch-popover" role="dialog" aria-label="Редактирование смены">
  <div class="sch-popover-arrow"></div>
  <div class="sch-popover-meta" id="schPopoverMeta">—</div>
  <div>
    <label>Сотрудник</label>
    <select id="schPopoverEmp">
      <option value="">— не назначен —</option>
    </select>
  </div>
  <div>
    <label>Время</label>
    <div class="field-row">
      <input type="time" id="schPopoverFrom" value="09:00">
      <span style="color:var(--muted)">–</span>
      <input type="time" id="schPopoverTo" value="17:00">
    </div>
  </div>
  <div>
    <label>Зал (опционально)</label>
    <select id="schPopoverHall">
      <option value="">— любой —</option>
    </select>
  </div>
  <div class="actions">
    <button class="del" id="schPopoverDel">×</button>
    <button id="schPopoverCancel">Отмена</button>
    <button class="save" id="schPopoverSave">Сохранить</button>
  </div>
</div>


<!-- ════════ Modal: Add new block ════════ -->
<div id="schModalAddBlock" class="sch-modal-backdrop" role="dialog" aria-modal="true">
  <div class="sch-modal">
    <h3>➕ Добавить блок</h3>

    <div class="sch-modal-group">
      <label>Тип блока</label>
      <div class="sch-modal-radio" id="schBlockTypeRadio">
        <div class="card active" data-type="hall">
          <span class="title">🏛 Зал из Poster</span>
          <span class="sub">подтягивает Hall_ID</span>
        </div>
        <div class="card" data-type="custom">
          <span class="title">🌿 Кастомная зона</span>
          <span class="sub">беседка, терраса и т.п.</span>
        </div>
      </div>
    </div>

    <div class="sch-modal-group" id="schBlockHallGroup">
      <label>Зал из Poster</label>
      <select id="schBlockHallSelect"></select>
      <p class="sch-modal-hint">Список тянется из Poster. Уже использованные залы недоступны.</p>
    </div>

    <div class="sch-modal-group" id="schBlockCustomGroup" style="display:none">
      <label>Название зоны</label>
      <input type="text" id="schBlockCustomName" placeholder="Например: «Беседка», «VIP-зал», «Веранда»">
      <label style="margin-top:10px">Иконка (эмодзи)</label>
      <input type="text" id="schBlockCustomIcon" placeholder="🌿" maxlength="4">
      <p class="sch-modal-hint">Кастомные зоны сохраняются в schedule_zones — потом доступны во всех графиках.</p>
    </div>

    <div class="sch-modal-group">
      <label>Кол-во начальных слотов</label>
      <input type="number" id="schBlockSlots" value="2" min="1" max="10" style="width: 110px">
    </div>

    <div class="sch-modal-actions">
      <button class="sch-btn ghost" id="schModalAddBlockClose">Отмена</button>
      <button class="sch-btn primary" id="schModalAddBlockSave">Добавить</button>
    </div>
  </div>
</div>

<!-- ════════ Staff management modal ════════ -->
<div id="schModalStaff" class="sch-modal-backdrop" role="dialog" aria-modal="true">
  <div class="sch-modal" style="width: 760px; max-width: calc(100vw - 32px);">
    <h3>👥 Управление персоналом графика</h3>
    <p class="sch-modal-hint" style="margin-bottom: 14px;">
      Источник списка — Poster <code>access.getEmployees</code> (кэш 30 мин).
      Локальные теги (в графике / может быть старшим / ставка ₫/ч) сохраняются
      в <code>schedule_staff_tags</code>.
      <a href="#" id="schReloadPoster" style="color: var(--accent);">⟳ Перезагрузить из Poster</a>
    </p>

    <div style="max-height: 60vh; overflow: auto; border: 1px solid var(--border); border-radius: 10px;">
      <table class="sch-staff-table" id="schStaffTable" style="width: 100%; border-collapse: collapse; font-size: 12.5px;">
        <thead>
          <tr style="background: rgba(255,255,255,.02);">
            <th style="padding: 10px; text-align: left; border-bottom: 1px solid var(--border);">Сотрудник</th>
            <th style="padding: 10px; text-align: left; border-bottom: 1px solid var(--border);">Poster роль</th>
            <th style="padding: 10px; text-align: left; border-bottom: 1px solid var(--border);">Тег</th>
            <th style="padding: 10px; text-align: center; border-bottom: 1px solid var(--border);">В графике</th>
            <th style="padding: 10px; text-align: center; border-bottom: 1px solid var(--border);">Старший</th>
            <th style="padding: 10px; text-align: right;  border-bottom: 1px solid var(--border);">₫/час</th>
          </tr>
        </thead>
        <tbody></tbody>
      </table>
    </div>

    <div class="sch-modal-actions">
      <button class="sch-btn ghost" id="schModalStaffClose">Закрыть</button>
      <button class="sch-btn primary" id="schModalStaffSave">Сохранить теги</button>
    </div>
  </div>
</div>

<script src="/schedule/assets/js/schedule.js?v=20260520_perf" defer></script>
