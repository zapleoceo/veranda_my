<?php
/**
 * @var array|null  $data   Full month grid from ReportService::month(), or null on error.
 * @var string|null $error
 * @var string      $prev   YYYY-MM for the previous month
 * @var string      $next   YYYY-MM for the next month
 */
declare(strict_types=1);

$fmt = static fn ($v): string => (int) $v === 0 ? '' : number_format((int) $v, 0, '.', ' ');
$wd  = [1 => 'Пн', 2 => 'Вт', 3 => 'Ср', 4 => 'Чт', 5 => 'Пт', 6 => 'Сб', 7 => 'Вс'];
?>
<div class="cf-head">
  <h1 class="cf-title">Финансовый отчёт</h1>
  <div class="cf-nav">
    <a class="btn btn-secondary btn-sm" href="?ym=<?= htmlspecialchars($prev) ?>" title="Предыдущий месяц">←</a>
    <span class="cf-month"><?= htmlspecialchars($data['label'] ?? '') ?></span>
    <a class="btn btn-secondary btn-sm" href="?ym=<?= htmlspecialchars($next) ?>" title="Следующий месяц">→</a>
  </div>
  <?php if ($data): $rc = $data['reconcile']; ?>
    <?php if ($rc['analytics'] === null): ?>
      <span class="cf-badge cf-badge-warn">сверка недоступна</span>
    <?php elseif ($rc['ok']): ?>
      <span class="cf-badge cf-badge-ok">✓ выручка сверена с Poster</span>
    <?php else: ?>
      <span class="cf-badge cf-badge-err">расхождение выручки <?= $fmt($rc['delta']) ?> ₫</span>
    <?php endif; ?>
    <?php if (!$data['financeOk']): ?>
      <span class="cf-badge cf-badge-err">финмодуль недоступен</span>
    <?php endif; ?>
  <?php endif; ?>
</div>

<?php if ($error !== null): ?>
  <div class="msg-err"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<?php if ($data): ?>
<div class="cf-note">
  Всё автоматически из Poster, вручную ничего не вводится. <b>Еда = вся выручка дня − кальяны.</b>
  Прибыль = (еда + кальяны + мероприятия) − расходы. Расходы берутся только из финмодуля Poster:
  что оплачено мимо Poster (часть рекламы, налоги) в отчёт не попадёт — поэтому прибыль может быть
  выше фактической, пока всё не проводится через Poster. День = дата закрытия чека (Asia/Ho_Chi_Minh).
  Дивиденды — следующий этап.
</div>

<?php if (!empty($data['unmapped'])): ?>
  <div class="msg-err">
    В финмодуле есть категории без привязки к колонке (не учтены в расходах):
    <?= htmlspecialchars(implode(', ', array_map(
        static fn ($cat, $sum) => "#{$cat} (" . number_format((int) $sum, 0, '.', ' ') . ' ₫)',
        array_keys($data['unmapped']),
        array_values($data['unmapped'])
    ))) ?>. Проверьте маппинг.
  </div>
<?php endif; ?>

<div class="cf-grid-wrap">
  <table class="cf-grid">
    <thead>
      <tr>
        <th class="cf-col-day">День</th>
        <?php foreach ($data['columns'] as $col): ?>
          <th class="cf-num cf-kind-<?= $col['kind'] ?>" title="<?= htmlspecialchars($col['label']) ?>"><?= htmlspecialchars($col['label']) ?></th>
        <?php endforeach; ?>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($data['rows'] as $r): ?>
        <tr class="<?= $r['weekday'] >= 5 ? 'cf-weekend' : '' ?>">
          <td class="cf-col-day"><?= (int) $r['day'] ?><span class="cf-wd"><?= $wd[$r['weekday']] ?? '' ?></span></td>
          <?php foreach ($data['columns'] as $col):
              $k = $col['key'];
              $v = $k === 'profit' ? $r['profit'] : ($r['values'][$k] ?? 0);
              $cls = 'cf-num cf-kind-' . $col['kind'];
              if ($k === 'profit') { $cls .= ' cf-col-profit' . ($v < 0 ? ' cf-neg' : ''); }
          ?>
            <td class="<?= $cls ?>"><?= $k === 'profit' ? number_format((int) $v, 0, '.', ' ') : $fmt($v) ?></td>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr class="cf-total-row">
        <td class="cf-col-day">ИТОГО</td>
        <?php foreach ($data['columns'] as $col):
            $k = $col['key'];
            $v = $data['totals'][$k] ?? 0;
            $cls = 'cf-num cf-kind-' . $col['kind'];
            if ($k === 'profit') { $cls .= ' cf-col-profit' . ($v < 0 ? ' cf-neg' : ''); }
        ?>
          <td class="<?= $cls ?>"><?= number_format((int) $v, 0, '.', ' ') ?></td>
        <?php endforeach; ?>
      </tr>
    </tfoot>
  </table>
</div>
<div class="cf-foot">Обновлено из Poster: <?= htmlspecialchars($data['generatedAt']) ?> · дней: <?= count($data['rows']) ?>. Часть расходных колонок — «правда Poster», может отличаться от старого Excel на пару миллионов.</div>
<?php endif; ?>
