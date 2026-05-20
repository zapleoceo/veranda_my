<?php
/**
 * Public read-only view of a saved schedule version.
 *
 * Variables (from ScheduleController::publicVersion):
 *   $versionLabel, $versionCreatedAt
 *   $state, $employees, $halls, $days, $heatmap
 *   $periodFrom, $periodTo
 *
 * Intentionally NO sidebar, NO money, NO controls — just the grid +
 * the heatmap so staff can see who works when without seeing rates.
 * Fully server-rendered (no schedule.js): filter/bucket dropdowns are
 * absent on this page since they need the admin state machine.
 *
 * All heatmap math comes from HeatmapBuilder (PHP) — same code path
 * as the admin /schedule view. No closures in this file.
 */

use App\Schedule\Domain\BlockColor;
use App\Schedule\Domain\TimeRange;
use App\Schedule\Services\HeatmapBuilder;

$blocks    = $state['blocks'] ?? [];
$shifts    = (array) ($state['shifts'] ?? []);
$empById   = [];
foreach ($employees as $e) $empById[(int)$e['id']] = $e;
$hallById  = [];
foreach ($halls as $h) {
    if (!is_array($h)) continue;
    $hid = (int) ($h['id'] ?? 0);
    if ($hid > 0) $hallById[$hid] = $h;
}

// Grid columns: date + dow + sum(slots per block) + spacer (per divider) — no warn/budget tail.
$gridColsArr = ['72px', '36px'];
foreach ($blocks as $block) {
    $slotsCnt = count($block['slots'] ?? []);
    for ($i = 0; $i < $slotsCnt; $i++) $gridColsArr[] = '110px';
    $gridColsArr[] = '6px';
}
$gridCols = implode(' ', $gridColsArr);

// ─── Heatmap inputs from $heatmap (HeatmapBuilder output) ─────────
$schHourStart = $heatmap['hourStart'] ?? 8;
$schHourEnd   = $heatmap['hourEnd']   ?? 24;
$perDayBlock  = $heatmap['perDayBlock'] ?? [];

// Order = first appearance in $blocks so the heatmap legend matches
// the visual order of blocks in the grid above.
$schColorsPresent = [];
foreach ($blocks as $b) {
    $c = BlockColor::of($b);
    if (!in_array($c, $schColorsPresent, true)) $schColorsPresent[] = $c;
}
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

$schBucket = 2;
$dayCount  = max(1, count($days));
$colCount  = (int) ceil(($schHourEnd - $schHourStart) / $schBucket);

$schDayBuckets = [];
$maxCount      = 0.0;
foreach ($days as $d) {
    $perColor = [];
    foreach ($schColorsPresent as $c) {
        $hours = $perDayBlock[$d['idx']][$c] ?? array_fill(0, 24, 0);
        $perColor[$c] = HeatmapBuilder::bucketize($hours, $schHourStart, $schHourEnd, $schBucket);
    }
    $schDayBuckets[$d['idx']] = $perColor;
    for ($i = 0; $i < $colCount; $i++) {
        $total = 0.0;
        foreach ($schColorsPresent as $c) $total += $perColor[$c][$i]['value'];
        $maxCount = max($maxCount, $total);
    }
}
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
?><!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>График смен · <?= htmlspecialchars($versionLabel ?? 'Версия') ?></title>
  <link rel="stylesheet" href="/assets/css/common.css?v=20260516_tokens2">
  <link rel="stylesheet" href="/schedule/assets/css/schedule.css?v=20260520_perf">
  <style>
    /* Public view: standalone layout (no sidebar). Edit affordances and
       totals/budget cells stay hidden via CSS — keeps the markup simple. */
    body { background: var(--bg); color: var(--text); font-family: inherit; margin: 0; padding: 24px; }
    .public-wrap { max-width: 100%; margin: 0 auto; }
    .public-head {
        display: flex; flex-wrap: wrap; gap: 12px; align-items: baseline; justify-content: space-between;
        padding-bottom: 12px; margin-bottom: 16px;
        border-bottom: 1px solid var(--border);
    }
    .public-head h1 { font-size: 18px; margin: 0; color: var(--text); }
    .public-head .meta { color: var(--muted); font-size: 12px; }
    .public-head .meta b { color: var(--accent); font-weight: 600; }
    /* Disable interactive bits — read-only view, no JS. */
    .sch-cell { cursor: default !important; }
    .sch-shift { cursor: default !important; }
    .sch-empty { opacity: .2; }
    /* No editing tools should appear if the markup ever sneaks them in. */
    .sch-block-add-slot, .sch-block-del, .sch-slot-del, .sch-col-actions,
    .sch-warn-cell, .sch-budget-cell, .sch-add-block-cell { display: none !important; }
  </style>
</head>
<body>
  <div class="public-wrap">
    <div class="public-head">
      <h1>📅 <?= htmlspecialchars($versionLabel ?? 'Версия графика') ?></h1>
      <span class="meta">
        Период: <b><?= htmlspecialchars($periodFrom) ?></b> — <b><?= htmlspecialchars($periodTo) ?></b>
        · Сохранено: <?= htmlspecialchars($versionCreatedAt ?? '') ?>
      </span>
    </div>

    <!-- ════════ THE GRID (read-only) ════════ -->
    <div class="sch-grid-scroll">
      <div class="sch-grid" style="grid-template-columns: <?= $gridCols ?>;">
        <!-- Block headers row -->
        <div class="sch-block-head" style="grid-column: span 2; background: rgba(255,255,255,.025);">
          <span class="sch-block-head-main"><span class="sch-block-name" style="color: var(--muted); font-size: 11px;">Дата</span></span>
        </div>
        <?php foreach ($blocks as $block):
            $color    = BlockColor::of($block);
            $headCls  = BlockColor::headerClass($color);
            $divCls   = BlockColor::dividerClass($color);
            $slotsCnt = count($block['slots']);
            $isHall   = ($block['type'] ?? '') === 'hall' && !empty($block['hall_id']);
            $hallRow  = $isHall ? ($hallById[(int)$block['hall_id']] ?? null) : null;
            $blkName  = $hallRow['name'] ?? ($block['name'] ?? '');
            $blkIcon  = $hallRow['icon'] ?? ($block['icon'] ?? '');
        ?>
          <div class="sch-block-head <?= $headCls ?>" style="grid-column: span <?= $slotsCnt ?>;">
            <span class="sch-block-head-main">
              <span class="sch-block-icon"><?= htmlspecialchars($blkIcon) ?></span>
              <span class="sch-block-name"><?= htmlspecialchars($blkName) ?></span>
            </span>
          </div>
          <div class="sch-divider <?= $divCls ?> head-row"></div>
        <?php endforeach; ?>

        <!-- Slot sub-headers -->
        <div class="sch-slot-head"></div>
        <div class="sch-slot-head"></div>
        <?php foreach ($blocks as $blkIdx => $block):
            $divCls = BlockColor::dividerClass(BlockColor::of($block));
            foreach ($block['slots'] as $sIdx => $_):
        ?>
          <div class="sch-slot-head"><span class="sch-slot-num"><?= $sIdx + 1 ?></span></div>
        <?php endforeach; ?>
          <div class="sch-divider <?= $divCls ?>"></div>
        <?php endforeach; ?>

        <!-- Data rows -->
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
              foreach ($block['slots'] as $sIdx => $_):
                  $sh = $shifts[$d['iso']][$block['id'] . ':' . $sIdx] ?? null;
          ?>
            <div class="sch-cell<?= $weekendCls ?>">
              <?php if ($sh):
                  $emp  = $empById[(int)($sh['emp_id'] ?? 0)] ?? null;
                  $name = $emp['name'] ?? ($sh['emp_name'] ?? '?');
                  $star = ($color === BlockColor::SENIOR && ($emp['can_be_senior'] ?? false)) ? ' ★' : '';
                  $time = TimeRange::shortRange((string)($sh['start'] ?? ''), (string)($sh['end'] ?? ''));
              ?>
                <div class="sch-shift <?= $color ?>">
                  <span class="sch-name"><?= htmlspecialchars($name . $star) ?></span>
                  <span class="sch-time"><?= htmlspecialchars($time) ?></span>
                </div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
            <div class="sch-divider <?= $divCls ?><?= $weekendCls ?>"></div>
          <?php endforeach; ?>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- ════════ Hour-load heatmap ════════ -->
    <section class="sch-coverage-section">
      <div class="sch-coverage-head">
        <h3>📊 Загрузка по часам</h3>
      </div>
      <div class="sch-cov-grid"
           style="--cov-cols: <?= $colCount ?>;"
           title="В ячейке: <?= htmlspecialchars($schBreakdownHint) ?>">
        <div class="sch-cov-corner">День \ Час<br><small><?= htmlspecialchars($schBreakdownHint) ?></small></div>
        <?php foreach (($schAvgBuckets[$schColorsPresent[0] ?? 'main'] ?? []) as $b): ?>
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
                 style="background: rgba(184,135,70,<?= $a['alpha'] ?>); color: <?= $a['text'] ?>;"
                 title="<?= htmlspecialchars($schBreakdownHint . ': ' . $label) ?>"><?= $label ?></div>
          <?php endfor; ?>
        <?php endforeach; ?>

        <div class="sch-cov-row-head avg" title="Среднее по <?= count($days) ?> д">Сред.</div>
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
               style="background: rgba(184,135,70,<?= $a['alpha'] ?>); color: <?= $a['text'] ?>;"
               title="<?= htmlspecialchars('Сред. ' . $schBreakdownHint . ': ' . $label) ?>"><?= $label ?></div>
        <?php endfor; ?>
      </div>
    </section>
  </div>
</body>
</html>
