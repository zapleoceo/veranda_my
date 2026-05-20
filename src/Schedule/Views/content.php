<?php
/**
 * /schedule — view (state-driven).
 *
 * Provided by ScheduleController::_handlePage():
 *   $state      — current snapshot (or default scaffold) — blocks + shifts + templates
 *   $employees  — roster from staff_tags overlay
 *   $halls      — Poster halls (id, name, icon)
 *   $zones      — schedule_zones rows
 *   $snapshots  — history pills
 *   $periodFrom — 'YYYY-MM-DD'
 *   $periodTo   — 'YYYY-MM-DD'
 *
 * Help-mode tooltips: [data-help-abs="…"] on every meaningful element.
 * The `?` button (#schHelpBtn) toggles body.sch-help-mode.
 */

$periodFrom ??= date('Y-m-d', strtotime('monday this week'));
$periodTo   ??= date('Y-m-d', strtotime($periodFrom . ' +13 days'));
$state      ??= ['blocks' => [], 'shifts' => new \stdClass(), 'templates' => []];
$employees  ??= [];
$halls      ??= [];
$zones      ??= [];
$snapshots  ??= [];

$schDowRu = ['вс','пн','вт','ср','чт','пт','сб'];
$schMonRu = ['','янв','фев','мар','апр','мая','июн','июл','авг','сен','окт','ноя','дек'];

// ─── Build $days array from period ───
$days = [];
$cur  = (int) strtotime($periodFrom);
$end  = (int) strtotime($periodTo);
if ($cur === false || $end === false || $cur > $end) {
    $days = [];
} else {
    $i = 0;
    while ($cur <= $end && $i < 366) {
        $days[] = [
            'idx'     => $i,
            'iso'     => date('Y-m-d', $cur),
            'date'    => (string) date('j', $cur),
            'mon'     => $schMonRu[(int) date('n', $cur)],
            'dow'     => $schDowRu[(int) date('w', $cur)],
            // Пт/Сб/Вс — выделяем как «уикенд» (5/6/0 в PHP date('w')).
            'weekend' => in_array((int) date('w', $cur), [0, 5, 6], true),
        ];
        $cur = (int) strtotime('+1 day', $cur);
        $i++;
    }
}

// ─── Normalize shifts (server-side stdClass becomes [] in PHP via decode) ───
$shifts = $state['shifts'] ?? [];
if (!is_array($shifts)) $shifts = [];

// ─── employees lookup ───
$empById = [];
foreach ($employees as $e) {
    $empById[(int)$e['id']] = $e;
}

// ─── halls lookup (live from Poster) ───────────────────────────────
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

// ─── Helper: get shift for a cell ───
$schGetShift = static function (string $iso, string $blockId, int $slotIdx) use (&$shifts): ?array {
    $key = $blockId . ':' . $slotIdx;
    return $shifts[$iso][$key] ?? null;
};

// ─── Helper: compute color css for block ───
$schBlockColor = static function (array $block): string {
    $c = $block['color'] ?? '';
    if (in_array($c, ['senior','main','banya','custom'], true)) return $c;
    if (($block['type'] ?? '') === 'senior') return 'senior';
    if (($block['type'] ?? '') === 'custom') return 'custom';
    // Heuristic fallback for hall by id
    if (($block['id'] ?? '') === 'hall:2') return 'banya';
    return 'main';
};

// ─── Helper: parse time range "HH:MM" → [h, m] ───
$schTimeToHours = static function (string $hhmm): float {
    if (!preg_match('/^(\d{1,2}):(\d{2})$/', $hhmm, $m)) return 0.0;
    return (int)$m[1] + ((int)$m[2]) / 60;
};

// ─── Compute heatmap stats per day per block-color ───
$schHourStart = 8;
$schHourEnd   = 24;
$schPerDayByBlock = [];
$schAggHourTotal  = array_fill(0, 24, 0);

foreach ($days as $d) {
    $perBlock = [
        'senior' => array_fill(0, 24, 0),
        'main'   => array_fill(0, 24, 0),
        'banya'  => array_fill(0, 24, 0),
        'custom' => array_fill(0, 24, 0),
    ];
    foreach ($state['blocks'] as $block) {
        $color = $schBlockColor($block);
        foreach ($block['slots'] as $slotIdx => $slot) {
            $sh = $schGetShift($d['iso'], $block['id'], $slotIdx);
            if (!$sh) continue;
            $sH = (int)floor($schTimeToHours($sh['start'] ?? ''));
            $eH = (int)ceil($schTimeToHours($sh['end']   ?? ''));
            for ($h = $sH; $h < $eH; $h++) {
                if ($h < 0 || $h > 23) continue;
                $perBlock[$color][$h]++;
                $schAggHourTotal[$h]++;
            }
        }
    }
    $schPerDayByBlock[$d['idx']] = $perBlock;
}
$schDayCount   = max(1, count($days));
$schAggHourAvg = array_map(static fn($v) => round($v / $schDayCount, 1), $schAggHourTotal);

// ─── Compute total slot count + grid-template-columns ───
$blocks = $state['blocks'];
$blockCount = count($blocks);
$gridColsArr = ['72px', '36px'];          // date + dow
foreach ($blocks as $block) {
    foreach ($block['slots'] as $_) $gridColsArr[] = '92px';
    $gridColsArr[] = '14px';              // divider after each block
}
$gridColsArr[] = '38px';                  // + add-block btn column
$gridColsArr[] = '56px';                  // warn
$gridColsArr[] = '70px';                  // budget
$gridCols = implode(' ', $gridColsArr);
$totalCols = count($gridColsArr);

// Per-block stats for totals
$blockShiftCounts = [];
foreach ($blocks as $blkIdx => $block) {
    $perSlot = array_fill(0, count($block['slots']), 0);
    foreach ($days as $d) {
        foreach ($block['slots'] as $sIdx => $_) {
            if ($schGetShift($d['iso'], $block['id'], $sIdx)) $perSlot[$sIdx]++;
        }
    }
    $blockShiftCounts[$blkIdx] = $perSlot;
}

// ─── Day-level warnings (parallel JS impl in schedule.js::dayWarnReasons) ─
// Returns a string[] of human-readable reasons. Empty array == OK day.
//
// Three checks, in order of severity:
//   1. "Нет старшего"         — day has some shifts but no senior block shift
//   2. "Двойное бронирование: <emp>" — same employee in overlapping ranges that day
//   3. "Не в графике: <emp>"  — assigned employee has in_schedule=false in tags
//
// Empty day (no shifts at all) is OK — it's an off day, not a warning.
$schDayWarnReasons = static function (string $iso) use (
    $blocks, $schGetShift, $schBlockColor, $schTimeToHours, $empById
): array {
    $reasons   = [];
    $anyShift  = false;
    $hasSenior = false;
    $empTimes  = []; // empId → [[startH, endH], …]
    foreach ($blocks as $block) {
        $isSenior = $schBlockColor($block) === 'senior';
        foreach ($block['slots'] as $sIdx => $_) {
            $sh = $schGetShift($iso, $block['id'], $sIdx);
            if (!$sh) continue;
            $anyShift = true;
            if ($isSenior) $hasSenior = true;
            $empId = (int)($sh['emp_id'] ?? 0);
            if ($empId <= 0) continue;
            $sH = $schTimeToHours($sh['start'] ?? '');
            $eH = $schTimeToHours($sh['end']   ?? '');
            if ($eH > $sH) {
                $empTimes[$empId] = $empTimes[$empId] ?? [];
                $empTimes[$empId][] = [$sH, $eH];
            }
            $emp = $empById[$empId] ?? null;
            if ($emp && array_key_exists('in_schedule', $emp) && $emp['in_schedule'] === false) {
                $name = (string)($emp['name'] ?? "uid:$empId");
                $r = "Не в графике: $name";
                if (!in_array($r, $reasons, true)) $reasons[] = $r;
            }
        }
    }
    if ($anyShift && !$hasSenior) array_unshift($reasons, 'Нет старшего');
    foreach ($empTimes as $empId => $intervals) {
        if (count($intervals) < 2) continue;
        usort($intervals, static fn($a, $b) => $a[0] <=> $b[0]);
        for ($i = 1, $n = count($intervals); $i < $n; $i++) {
            if ($intervals[$i][0] < $intervals[$i - 1][1]) {
                $emp  = $empById[$empId] ?? null;
                $name = (string)($emp['name'] ?? "uid:$empId");
                $r    = "Двойное бронирование: $name";
                if (!in_array($r, $reasons, true)) $reasons[] = $r;
                break;
            }
        }
    }
    return $reasons;
};

// ─── Forecast: total hours + ZP + warnings ───
$totalHours = 0.0;
$totalSalary = 0.0;
$warningDays = 0;
$warnByDay   = [];   // iso → reasons[] (also passed to JS via boot)
foreach ($days as $d) {
    foreach ($blocks as $block) {
        foreach ($block['slots'] as $sIdx => $_) {
            $sh = $schGetShift($d['iso'], $block['id'], $sIdx);
            if (!$sh) continue;
            $hrs = $schTimeToHours($sh['end'] ?? '') - $schTimeToHours($sh['start'] ?? '');
            if ($hrs > 0) {
                $totalHours += $hrs;
                $rate = (int)($empById[(int)($sh['emp_id'] ?? 0)]['rate_per_hour'] ?? 0);
                $totalSalary += $hrs * $rate;
            }
        }
    }
    $reasons = $schDayWarnReasons($d['iso']);
    $warnByDay[$d['iso']] = $reasons;
    if (!empty($reasons)) $warningDays++;
}
?>

<div class="container sch-wrap">

  <div class="sch-pagehead">
    <div>
      <h1>График смен</h1>
      <div class="sch-sub">Блочная модель: ⭐ Старшие · 🏛 Hall_ID из Poster · 🌿 кастомные зоны (Беседка и т.п.). Период — любой.</div>
    </div>
    <button type="button" id="schHelpBtn" class="sch-help-btn"
            aria-pressed="false" title="Подсказки по интерфейсу"
            data-help-abs="Включить/выключить режим подсказок. Когда включён — все важные элементы обведены пунктиром, при наведении показывается описание.">?</button>
  </div>

  <!-- Period picker -->
  <div class="sch-periodbar" data-help-abs="Период просмотра. Стрелки сдвигают окно сохраняя его длину. Date-инпуты задают любой диапазон руками. Пресеты — быстрые шаблоны.">
    <button class="sch-period-arrow" id="schPeriodPrev" title="Сдвинуть период назад">◀</button>
    <div class="sch-period-range">
      <input type="date" id="schPeriodFrom" class="sch-date-input" value="<?= htmlspecialchars($periodFrom) ?>">
      <span style="color: var(--muted)">→</span>
      <input type="date" id="schPeriodTo" class="sch-date-input" value="<?= htmlspecialchars($periodTo) ?>">
    </div>
    <button class="sch-period-arrow" id="schPeriodNext" title="Сдвинуть период вперёд">▶</button>
    <span class="sch-period-stats"><?= count($days) ?> дней</span>

    <div class="sch-period-presets">
      <button data-period-preset="7">Неделя</button>
      <button data-period-preset="14" class="active">2 недели</button>
      <button data-period-preset="30">Месяц</button>
      <button data-period-preset="custom">Произвольный</button>
    </div>
  </div>

  <!-- Summary -->
  <div class="sch-summarybar">
    <div class="sch-metric" data-help-abs="Сумма часов всех назначенных смен в выбранном периоде.">
      <span class="sch-metric-label">Часы</span>
      <span class="sch-metric-value"><?= number_format((int)round($totalHours), 0, '.', ' ') ?>ч</span>
    </div>
    <div class="sch-metric" data-help-abs="Прогноз ФОТ: ставка из staff_tags × часы. Пересчёт при каждом изменении.">
      <span class="sch-metric-label">Прогноз ЗП</span>
      <span class="sch-metric-value gold"><?= $totalSalary > 0 ? number_format($totalSalary/1_000_000, 2, '.', '') . 'M ₫' : '— ₫' ?></span>
    </div>
    <div class="sch-metric" data-help-abs="Количество разных сотрудников, у которых есть хотя бы одна смена в периоде.">
      <span class="sch-metric-label">Сотрудников</span>
      <span class="sch-metric-value"><?php
        $empSet = [];
        foreach ($days as $d) {
            foreach ($blocks as $block) {
                foreach ($block['slots'] as $sIdx => $_) {
                    $sh = $schGetShift($d['iso'], $block['id'], $sIdx);
                    if ($sh && !empty($sh['emp_id'])) $empSet[(int)$sh['emp_id']] = true;
                }
            }
        }
        echo count($empSet);
      ?></span>
    </div>
    <div class="sch-metric" data-help-abs="Сколько дней в периоде имеют хотя бы одну проблему: нет старшего, двойное бронирование сотрудника или назначен сотрудник «не в графике». Hover на ⚠ в строке дня покажет конкретную причину.">
      <span class="sch-metric-label">Предупреждений</span>
      <span class="sch-metric-value <?= $warningDays > 0 ? 'warn' : '' ?>"><?= $warningDays ?> ⚠</span>
    </div>
    <div></div>
    <button class="sch-btn" id="schStaffBtn"
            data-help-abs="Управление персоналом: кто появляется в графике, кто может быть старшим, какая ставка ₫/час. Список тянется из Poster `access.getEmployees`, локальные теги хранятся в schedule_staff_tags.">👥 Персонал</button>
    <button class="sch-btn" id="schCopyWeek"
            data-help-abs="Скопировать смены прошлой недели в следующую — экономит 80% времени при еженедельном планировании.">↳ Скопировать неделю</button>
    <button class="sch-btn primary" id="schSaveBtn"
            data-help-abs="Сохранить текущий график как версию (snapshot). История версий внизу страницы — можно откатиться.">Сохранить</button>
  </div>

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
          $color    = $schBlockColor($block);
          $headCls  = $color === 'senior' ? 'senior'
                    : ($color === 'banya'  ? 'hall-banya'
                    : ($color === 'custom' ? 'hall-custom'
                    : 'hall-main'));
          $divCls   = $color === 'main' ? 'main' : $color;
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
          $color  = $schBlockColor($block);
          $divCls = $color === 'main' ? 'main' : $color;
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
          $dayHasSenior = false;
          $daySalary = 0.0;
          $dayReasons = $warnByDay[$d['iso']] ?? [];
      ?>
        <div class="sch-row<?= $weekendCls ?> sch-date-cell">
          <span class="sch-day-num"><?= htmlspecialchars($d['date']) ?></span>
          <span class="sch-day-mon"><?= htmlspecialchars($d['mon']) ?></span>
        </div>
        <div class="sch-row<?= $weekendCls ?> sch-dow-cell"><?= htmlspecialchars($d['dow']) ?></div>

        <?php foreach ($blocks as $block):
            $color  = $schBlockColor($block);
            $divCls = $color === 'main' ? 'main' : $color;
            foreach ($block['slots'] as $sIdx => $slot):
                $sh = $schGetShift($d['iso'], $block['id'], $sIdx);
                if ($sh) {
                    $hrs = $schTimeToHours($sh['end'] ?? '') - $schTimeToHours($sh['start'] ?? '');
                    if ($hrs > 0) {
                        $rate = (int)($empById[(int)($sh['emp_id'] ?? 0)]['rate_per_hour'] ?? 0);
                        $daySalary += $hrs * $rate;
                    }
                    if ($color === 'senior') $dayHasSenior = true;
                }
        ?>
          <div class="sch-cell<?= $weekendCls ?>"
               data-block="<?= htmlspecialchars($block['id']) ?>"
               data-slot="<?= $sIdx ?>"
               data-day-iso="<?= htmlspecialchars($d['iso']) ?>"
               data-day-idx="<?= $d['idx'] ?>">
            <?php if ($sh):
                $emp = $empById[(int)($sh['emp_id'] ?? 0)] ?? null;
                $name = $emp['name'] ?? ($sh['emp_name'] ?? '?');
                $star = ($color === 'senior' && ($emp['can_be_senior'] ?? false)) ? ' ★' : '';
                $time = str_replace(':00', '', $sh['start'] ?? '') . '–' . str_replace(':00', '', $sh['end'] ?? '');
            ?>
              <div class="sch-shift <?= $color ?>" draggable="true">
                <span class="sch-name"><?= htmlspecialchars($name . $star) ?></span>
                <span class="sch-time"><?= htmlspecialchars($time) ?></span>
              </div>
            <?php else: ?>
              <span class="sch-empty"><?= ($color === 'banya' || $color === 'custom') ? '—' : '+' ?></span>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
          <div class="sch-divider <?= $divCls ?><?= $weekendCls ?>"></div>
        <?php endforeach; ?>

        <div class="sch-add-block-cell<?= $weekendCls ?>"></div>

        <?php if (!empty($dayReasons)): ?>
          <div class="sch-warn-cell bad<?= $weekendCls ?>"
               data-day-iso="<?= htmlspecialchars($d['iso']) ?>"
               title="<?= htmlspecialchars(implode("\n", $dayReasons)) ?>">⚠</div>
        <?php else: ?>
          <div class="sch-warn-cell ok<?= $weekendCls ?>" data-day-iso="<?= htmlspecialchars($d['iso']) ?>" title="Всё в порядке">✓</div>
        <?php endif; ?>
        <div class="sch-budget-cell<?= $weekendCls ?>" data-day-iso="<?= htmlspecialchars($d['iso']) ?>">
          <?= $daySalary > 0 ? number_format($daySalary / 1_000_000, 2, '.', '') . 'M' : '—' ?>
        </div>
      <?php endforeach; ?>


      <!-- ────── Totals row ────── -->
      <?php if (!empty($days)): ?>
        <div class="sch-totals-cell" style="grid-column: span 2;"
             data-help-abs="Итого за период: количество назначенных смен в каждом слоте + общий ФОТ.">Итого</div>
        <?php foreach ($blocks as $blkIdx => $block):
            $divCls = $schBlockColor($block) === 'main' ? 'main' : $schBlockColor($block);
            foreach ($block['slots'] as $sIdx => $_):
        ?>
          <div class="sch-totals-cell" data-totals-slot="<?= htmlspecialchars($block['id']) ?>:<?= $sIdx ?>"><?= $blockShiftCounts[$blkIdx][$sIdx] ?? 0 ?></div>
        <?php endforeach; ?>
          <div class="sch-divider"></div>
        <?php endforeach; ?>
        <div class="sch-add-block-cell"></div>
        <div class="sch-warn-cell bad" data-totals="warn" style="background: rgba(184,135,70,.08); font-weight: 700;"><?= $warningDays ?> ⚠</div>
        <div class="sch-totals-cell" data-totals="salary" style="font-size: 12px;">
          <?= $totalSalary > 0 ? number_format($totalSalary / 1_000_000, 2, '.', '') . 'M' : '—' ?>
        </div>
      <?php endif; ?>

    </div>
  </div>


  <!-- ════════ Coverage by hour — heatmap + aggregate histogram ════════ -->
  <section class="sch-coverage-section"
           data-help-abs="Загрузка по часам: сколько людей одновременно работает в каждом временном интервале. Сверху — heatmap, снизу — горизонтальная гистограмма средней нагрузки по часам за весь период. Шаг и фильтр меняются live.">
    <div class="sch-coverage-head">
      <h3>📊 Загрузка по часам</h3>
      <div class="sch-coverage-controls">
        <label>Шаг:</label>
        <select id="schBucketSize" data-help-abs="1 час даёт детальную картину, 2-3 часа агрегируют.">
          <option value="1">1 час</option>
          <option value="2" selected>2 часа</option>
          <option value="3">3 часа</option>
          <option value="4">4 часа</option>
        </select>
        <label>Считать:</label>
        <select id="schCoverageFilter" data-help-abs="Фильтр: всех / только старших / только определённый блок.">
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
    </div>

    <!-- Server-rendered initial heatmap; JS overrides on bucket/filter change. -->
    <?php
    // Default: 2h buckets, all-blocks filter — same as JS default
    $schBucket = 2;
    $maxCount = 0;
    foreach ($schPerDayByBlock as $blocksByH) {
        $sum = array_fill(0, 24, 0);
        foreach (['senior','main','banya','custom'] as $k) {
            foreach ($blocksByH[$k] as $h => $c) $sum[$h] += $c;
        }
        for ($h = $schHourStart; $h < $schHourEnd; $h += $schBucket) {
            $bucketMax = 0;
            for ($k = 0; $k < $schBucket && $h + $k < $schHourEnd; $k++) {
                $bucketMax = max($bucketMax, $sum[$h + $k]);
            }
            $maxCount = max($maxCount, $bucketMax);
        }
    }
    $maxCount = max(1, $maxCount);
    $colCount = (int) ceil(($schHourEnd - $schHourStart) / $schBucket);
    ?>
    <div class="sch-cov-grid" id="schCovGrid" style="--cov-cols: <?= $colCount ?>;">
      <div class="sch-cov-corner">День \ Час</div>
      <?php for ($h = $schHourStart; $h < $schHourEnd; $h += $schBucket): ?>
        <div class="sch-cov-col-head"><?= sprintf('%02d–%02d', $h, min($h + $schBucket, $schHourEnd)) ?></div>
      <?php endfor; ?>

      <?php foreach ($days as $d):
          $weekendCls = $d['weekend'] ? ' weekend' : '';
          $perBlock = $schPerDayByBlock[$d['idx']] ?? null;
      ?>
        <div class="sch-cov-row-head<?= $weekendCls ?>"><?= htmlspecialchars($d['dow']) ?> <?= htmlspecialchars($d['date']) ?></div>
        <?php
        $sum = array_fill(0, 24, 0);
        if ($perBlock) {
            foreach (['senior','main','banya','custom'] as $k) {
                foreach ($perBlock[$k] as $h => $c) $sum[$h] += $c;
            }
        }
        for ($h = $schHourStart; $h < $schHourEnd; $h += $schBucket):
            $bMax = 0;
            for ($k = 0; $k < $schBucket && $h + $k < $schHourEnd; $k++) {
                $bMax = max($bMax, $sum[$h + $k]);
            }
            $intensity = $bMax / $maxCount;
            $alpha = $bMax > 0 ? 0.05 + $intensity * 0.90 : 0;
            $txt = $intensity > 0.55 ? '#0f1117' : 'var(--text)';
            $label = $bMax > 0 ? $bMax : '·';
        ?>
          <div class="sch-cov-cell"
               data-count="<?= $bMax ?>"
               style="background: rgba(184,135,70,<?= $alpha ?>); color: <?= $txt ?>;"><?= $label ?></div>
        <?php endfor; ?>
      <?php endforeach; ?>
    </div>

    <div class="sch-agg-histogram"
         data-help-abs="Средняя нагрузка по часам за весь период. Длина бара — среднее количество человек в этом часе.">
      <h4>Средняя загрузка по часам за период (<?= count($days) ?> дней)</h4>
      <div class="sch-bar-grid">
        <?php
        $aggMax = max(1, ...$schAggHourAvg);
        for ($h = $schHourStart; $h < $schHourEnd; $h++):
            $avg = $schAggHourAvg[$h];
            $w = $aggMax > 0 ? round(($avg / $aggMax) * 1000) / 10 : 0;
        ?>
          <div class="sch-bar-label"><?= sprintf('%02d:00', $h) ?></div>
          <div class="sch-bar-track"><div class="sch-bar-fill" style="width: <?= $w ?>%;"></div></div>
          <div class="sch-bar-value"><?= $avg ?> чел</div>
        <?php endfor; ?>
      </div>
    </div>
  </section>

  <?php
  // ─── Stats payload for JS heatmap rebucketization ───
  $schStatsPayload = [
      'hourStart' => $schHourStart,
      'hourEnd'   => $schHourEnd,
      'dayCount'  => count($days),
      'days'      => [],
  ];
  foreach ($days as $d) {
      $schStatsPayload['days'][] = [
          'date'    => $d['date'],
          'dow'     => $d['dow'],
          'mon'     => $d['mon'],
          'weekend' => $d['weekend'],
          'hours'   => $schPerDayByBlock[$d['idx']] ?? null,
      ];
  }
  ?>
  <script id="schStatsData" type="application/json"><?= json_encode($schStatsPayload, JSON_UNESCAPED_UNICODE) ?></script>

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


  <!-- Senior shifts summary -->
  <div class="sch-senior-block" data-help-abs="Сводка по старшим за период — кто и когда был старшим. ⚠ на днях без назначенного старшего.">
    <h3>⭐ Старшие смены периода</h3>
    <div class="sch-senior-list" id="schSeniorList">
      <?php
      // Group senior shifts by employee
      $seniorByEmp = [];
      $daysWithoutSenior = [];
      foreach ($days as $d) {
          $hasSr = false;
          foreach ($blocks as $block) {
              if ($schBlockColor($block) !== 'senior') continue;
              foreach ($block['slots'] as $sIdx => $_) {
                  $sh = $schGetShift($d['iso'], $block['id'], $sIdx);
                  if (!$sh) continue;
                  $hasSr = true;
                  $key = (int)($sh['emp_id'] ?? 0);
                  $seniorByEmp[$key] ??= ['name' => $empById[$key]['name'] ?? ($sh['emp_name'] ?? '?'), 'days' => []];
                  $seniorByEmp[$key]['days'][] = $d['dow'] . ' ' . str_replace(':00', '', $sh['start']) . '–' . str_replace(':00', '', $sh['end']);
              }
          }
          if (!$hasSr) $daysWithoutSenior[] = $d['dow'] . ' ' . $d['date'];
      }
      ?>
      <?php foreach ($seniorByEmp as $e): ?>
        <div class="sch-senior-row">
          <span class="who"><?= htmlspecialchars($e['name']) ?></span>
          <span class="days"><?= htmlspecialchars(implode(', ', $e['days'])) ?></span>
        </div>
      <?php endforeach; ?>
      <?php if (empty($seniorByEmp) && empty($daysWithoutSenior)): ?>
        <div style="color: var(--muted); font-size: 12px;">Старшие пока не назначены — кликни в любую ячейку «Старшие смены» и выбери сотрудника с ★.</div>
      <?php endif; ?>
      <?php foreach ($daysWithoutSenior as $dy): ?>
        <div class="sch-senior-row" style="color: var(--danger);">
          <span class="who">⚠ <?= htmlspecialchars($dy) ?></span>
          <span class="days">нет старшего на смену</span>
        </div>
      <?php endforeach; ?>
    </div>
  </div>


  <!-- Snapshots -->
  <div class="sch-snapshots" data-help-abs="Версии графика. Кнопкой «Сохранить версию» создаётся snapshot — можно вернуться к любой прошлой версии.">
    <span class="sch-snap-label">Версии:</span>
    <?php foreach ($snapshots as $snap):
        $cls = $snap['is_current'] ? ' current' : '';
        $when = preg_replace('/^(\d{4})-(\d{2})-(\d{2}) (\d{2}:\d{2}).*$/', '$3.$2 $4', (string)$snap['created_at']);
    ?>
      <span class="sch-snap-pill<?= $cls ?>" data-snap-id="<?= (int)$snap['id'] ?>">
        <?= htmlspecialchars($snap['label'] ?: 'auto') ?>
        <span class="when"><?= htmlspecialchars($when) ?></span>
      </span>
    <?php endforeach; ?>
    <?php if (empty($snapshots)): ?>
      <span style="color: var(--muted); font-size: 12px;">Нет сохранённых версий. Первое сохранение создаст snapshot.</span>
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

<script src="/schedule/assets/js/schedule.js?v=20260520_weekend" defer></script>
