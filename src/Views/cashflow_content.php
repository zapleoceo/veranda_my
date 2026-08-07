<?php
/**
 * @var array|null  $data   Month grid from RevenueService::month(), or null on error.
 * @var string|null $error
 * @var string      $prev   YYYY-MM for the previous month
 * @var string      $next   YYYY-MM for the next month
 */
declare(strict_types=1);

$fmt = static fn ($v): string => number_format((int) $v, 0, '.', ' ');
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
      <span class="cf-badge cf-badge-ok">✓ сверено с Poster</span>
    <?php else: ?>
      <span class="cf-badge cf-badge-err">расхождение <?= $fmt($rc['delta']) ?> ₫</span>
    <?php endif; ?>
  <?php endif; ?>
</div>

<?php if ($error !== null): ?>
  <div class="msg-err"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<?php if ($data): ?>
<div class="cf-note">
  Выручка считается автоматически из Poster: <b>еда/напитки = вся выручка дня − кальяны</b>,
  кальяны = категория «Кальян / Shisha». Вручную ввести нельзя — двойной учёт невозможен.
  День = дата закрытия чека (Asia/Ho_Chi_Minh). Расходы и прибыль — следующий этап.
</div>

<div class="cf-grid-wrap">
  <table class="cf-grid">
    <thead>
      <tr>
        <th class="cf-col-day">День</th>
        <th class="cf-num">Продажи еды/напитков</th>
        <th class="cf-num">Продажи кальянов</th>
        <th class="cf-num cf-col-total">Всего</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($data['rows'] as $r): ?>
        <tr class="<?= $r['weekday'] >= 5 ? 'cf-weekend' : '' ?>">
          <td class="cf-col-day"><?= (int) $r['day'] ?><span class="cf-wd"><?= $wd[$r['weekday']] ?? '' ?></span></td>
          <td class="cf-num"><?= $fmt($r['food']) ?></td>
          <td class="cf-num"><?= $fmt($r['hookah']) ?></td>
          <td class="cf-num cf-col-total"><?= $fmt($r['total']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr class="cf-total-row">
        <td class="cf-col-day">ИТОГО</td>
        <td class="cf-num"><?= $fmt($data['totals']['food']) ?></td>
        <td class="cf-num"><?= $fmt($data['totals']['hookah']) ?></td>
        <td class="cf-num cf-col-total"><?= $fmt($data['totals']['total']) ?></td>
      </tr>
    </tfoot>
  </table>
</div>
<div class="cf-foot">Обновлено из Poster: <?= htmlspecialchars($data['generatedAt']) ?> · дней в таблице: <?= count($data['rows']) ?></div>
<?php endif; ?>
