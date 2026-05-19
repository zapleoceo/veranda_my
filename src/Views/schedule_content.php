<?php
/**
 * /schedule — view (демо-каркас).
 *
 * Все интерактивные элементы помечены атрибутом `data-help-abs="…"`:
 * клик по кнопке `?` (#schHelpBtn) включает body.sch-help-mode, и
 * наведение на элемент показывает всплывающую подсказку (как на /payday3).
 *
 * Данные ниже — статические демо-значения для визуальной проверки UX.
 * Когда блочная модель утверждена, $rows / $blocks / $employees
 * подтягиваются через сервис и snapshot из БД.
 */
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

  <div class="sch-notice"
       data-help-abs="Страница — рабочий каркас UX. Сохранение, drag-n-drop и привязка к Poster Hall_ID появятся, как только утвердим эту модель. Данные ниже — демо.">
    <strong>📐 Прототип:</strong> страница показывает финальную UX-модель, но сохранение/drag-n-drop пока не подключены — это для согласования с партнёрами. Включите справку <code>?</code>, наводите на элементы.
  </div>

  <!-- ════════ Period picker ════════ -->
  <div class="sch-periodbar" data-help-abs="Период просмотра. Стрелки сдвигают окно сохраняя его длину. Date-инпуты задают любой диапазон руками. Пресеты справа — быстрые шаблоны (неделя / 2 недели / месяц).">
    <button class="sch-period-arrow" data-demo-noop="period-prev" title="Сдвинуть период назад">◀</button>
    <div class="sch-period-range">
      <input type="date" value="2026-05-13" class="sch-date-input">
      <span style="color: var(--muted)">→</span>
      <input type="date" value="2026-05-26" class="sch-date-input">
    </div>
    <button class="sch-period-arrow" data-demo-noop="period-next" title="Сдвинуть период вперёд">▶</button>
    <span class="sch-period-stats">14 дней · 21–22 неделя</span>

    <div class="sch-period-presets">
      <button data-demo-noop="period-week">Неделя</button>
      <button class="active" data-demo-noop="period-2weeks">2 недели</button>
      <button data-demo-noop="period-month">Месяц</button>
      <button data-demo-noop="period-custom">Произвольный</button>
    </div>
  </div>

  <!-- ════════ Summary ════════ -->
  <div class="sch-summarybar">
    <div class="sch-metric" data-help-abs="Сумма часов всех назначенных смен в выбранном периоде.">
      <span class="sch-metric-label">Часы</span>
      <span class="sch-metric-value">712ч</span>
    </div>
    <div class="sch-metric" data-help-abs="Прогноз ФОТ: ставка из /employees × часы каждого сотрудника. Пересчёт live при любом изменении.">
      <span class="sch-metric-label">Прогноз ЗП</span>
      <span class="sch-metric-value gold">18.4M ₫</span>
    </div>
    <div class="sch-metric" data-help-abs="Количество разных сотрудников, у которых хоть одна смена в периоде.">
      <span class="sch-metric-label">Сотрудников</span>
      <span class="sch-metric-value">9</span>
    </div>
    <div class="sch-metric" data-help-abs="Дни с предупреждениями: нет старшего, мало людей на смене, конфликты по времени.">
      <span class="sch-metric-label">Предупреждений</span>
      <span class="sch-metric-value warn">3 ⚠</span>
    </div>
    <div></div>
    <button class="sch-btn" data-demo-noop="copy-week"
            data-help-abs="Скопировать смены прошлой недели в следующую — экономит 80% времени при еженедельном планировании.">↳ Скопировать неделю</button>
    <button class="sch-btn primary" data-demo-noop="save"
            data-help-abs="Сохранить текущий график как версию (snapshot). История версий внизу страницы — можно откатиться.">Сохранить</button>
  </div>

  <!-- ════════ Toolbar ════════ -->
  <div class="sch-toolbar">
    <span class="sch-tool-label">Шаблоны времени (drag в ячейку):</span>
    <span class="sch-chip" data-help-abs="Drag в любую ячейку → автоматически заполнит время этого шаблона. Шаблоны редактируются в настройках.">Д 09–17</span>
    <span class="sch-chip">В 16–23</span>
    <span class="sch-chip">У 09–14</span>
    <span class="sch-chip">Полный 09–23</span>
    <span class="sch-chip add" data-demo-noop="add-template"
          data-help-abs="Добавить свой шаблон времени (например «обед 12–16»).">+ Шаблон</span>
    <span style="flex:1"></span>
    <button class="sch-btn ghost" data-demo-noop="clear-period"
            data-help-abs="Очистить все смены за выбранный период. Запрашивает подтверждение.">Очистить период</button>
  </div>

  <!-- ════════ THE GRID ════════ -->
  <div class="sch-grid-scroll" data-help-abs="Сетка дней (вниз) × слотов в блоках. Hover на шапку блока → справа кнопки + / − для управления слотами. Клик в ячейку → форма назначения сотрудника + времени.">
    <div class="sch-grid">

      <!-- Block headers row -->
      <div class="sch-block-head" style="grid-column: span 2; background: rgba(255,255,255,.025); border-bottom: 1.5px solid var(--border);">
        <span class="sch-block-name" style="color: var(--muted); font-size: 11px;">Дата</span>
      </div>

      <div class="sch-block-head senior" style="grid-column: span 3;"
           data-help-abs="Блок «Старшие смены». Сюда попадают сотрудники, у которых на странице «Настройка персонала» включена ★. На день обычно нужен 1, иногда 2 старших (на смену день + смена вечер).">
        <span class="sch-block-icon">⭐</span>
        <span class="sch-block-name">Старшие смены</span>
        <span class="sch-block-meta">· 3 слота</span>
        <span class="sch-col-actions">
          <button title="Добавить слот" data-demo-noop="add-slot-senior" data-help-abs="Добавить ещё один слот старшего (увеличить вместимость блока на одну колонку).">+</button>
          <button title="Убрать слот" data-demo-noop="del-slot-senior" data-help-abs="Убрать последний слот блока. Если в нём есть смены — запрос подтверждения.">−</button>
          <button title="Настройки блока" data-demo-noop="cfg-senior" data-help-abs="Настройки блока: переименовать, изменить иконку, удалить целиком.">⋮</button>
        </span>
      </div>
      <div class="sch-divider senior head-row"></div>

      <div class="sch-block-head hall-main" style="grid-column: span 4;"
           data-help-abs="Главный зал ресторана (hall_id 1 из Poster). 4 слота — типичное число официантов в смене.">
        <span class="sch-block-icon">🏛</span>
        <span class="sch-block-name">Главный зал</span>
        <span class="sch-block-meta">· hall_id 1 · 4 слота</span>
        <span class="sch-col-actions">
          <button data-demo-noop="add-slot-main">+</button>
          <button data-demo-noop="del-slot-main">−</button>
          <button data-demo-noop="cfg-main">⋮</button>
        </span>
      </div>
      <div class="sch-divider main head-row"></div>

      <div class="sch-block-head hall-banya" style="grid-column: span 1;"
           data-help-abs="Баня (hall_id 2 из Poster). Один слот — обычно работает 1 человек.">
        <span class="sch-block-icon">♨</span>
        <span class="sch-block-name">Баня</span>
        <span class="sch-col-actions">
          <button data-demo-noop="add-slot-banya">+</button>
          <button data-demo-noop="del-slot-banya">−</button>
          <button data-demo-noop="cfg-banya">⋮</button>
        </span>
      </div>
      <div class="sch-divider banya head-row"></div>

      <div class="sch-block-head hall-custom" style="grid-column: span 1;"
           data-help-abs="Кастомная зона — например «Беседка» для аренды. Не привязана к Hall_ID в Poster, хранится в schedule_zones.">
        <span class="sch-block-icon">🌿</span>
        <span class="sch-block-name">Беседка</span>
        <span class="sch-col-actions">
          <button data-demo-noop="add-slot-besedka">+</button>
          <button data-demo-noop="del-slot-besedka">−</button>
          <button data-demo-noop="cfg-besedka">⋮</button>
        </span>
      </div>
      <div class="sch-divider custom head-row"></div>

      <div class="sch-add-block-cell" data-help-abs="Добавить целый блок: либо новый Hall из Poster, либо кастомная зона (Беседка / Терраса / VIP).">
        <button class="sch-add-block-btn" title="Добавить новый блок"
                data-demo-noop="add-block">+</button>
      </div>

      <div class="sch-block-head" style="grid-column: span 2; background: rgba(255,255,255,.025); border-bottom: 1.5px solid var(--border);"
           data-help-abs="Сводка дня: красный ⚠ если есть проблемы (нет старшего, мало людей), и прогноз ЗП этого дня.">
        <span class="sch-block-name" style="color: var(--muted); font-size: 10px; text-align: center; width: 100%;">Сводка дня</span>
      </div>

      <!-- Slot sub-headers row -->
      <div class="sch-slot-head"></div>
      <div class="sch-slot-head"></div>

      <div class="sch-slot-head"><span class="sch-slot-num">1</span><span class="sch-slot-default">день</span></div>
      <div class="sch-slot-head"><span class="sch-slot-num">2</span><span class="sch-slot-default">день</span></div>
      <div class="sch-slot-head"><span class="sch-slot-num">3</span><span class="sch-slot-default">вечер</span></div>
      <div class="sch-divider senior"></div>

      <div class="sch-slot-head"><span class="sch-slot-num">1</span><span class="sch-slot-default">09–17</span></div>
      <div class="sch-slot-head"><span class="sch-slot-num">2</span><span class="sch-slot-default">09–17</span></div>
      <div class="sch-slot-head"><span class="sch-slot-num">3</span><span class="sch-slot-default">16–23</span></div>
      <div class="sch-slot-head"><span class="sch-slot-num">4</span><span class="sch-slot-default">16–23</span></div>
      <div class="sch-divider main"></div>

      <div class="sch-slot-head"><span class="sch-slot-num">1</span><span class="sch-slot-default">10–18</span></div>
      <div class="sch-divider banya"></div>

      <div class="sch-slot-head"
           data-help-abs="Слот по бронированию — заполняется только когда беседка арендована.">
        <span class="sch-slot-num">1</span><span class="sch-slot-default">по брони</span>
      </div>
      <div class="sch-divider custom"></div>

      <div class="sch-add-block-cell" style="border-bottom: 1px solid var(--border);"></div>

      <div class="sch-slot-head" data-help-abs="Предупреждения дня: ⚠ нет старшего, ⚠ мало людей, ⚠ конфликт расписания.">⚠</div>
      <div class="sch-slot-head" data-help-abs="Прогноз ФОТ за этот день.">₫/день</div>


      <?php
      // Демо-данные для отображения. Каждая строка — один день.
      // В реальной странице это будет цикл по дням периода + lookup в snapshot.
      $rows = [
          ['date' => '13', 'mon' => 'мая', 'dow' => 'пн', 'weekend' => false,
           'senior' => [['Лёша ★', '09–17', 'senior'], null, ['Phai ★', '16–23', 'senior']],
           'main'   => [['Султан', '09–22'], ['Оля', '09–22'], ['Саша', '17–23'], ['Вася', '17–23']],
           'banya'  => [['An', '10–18']],
           'custom' => [null],
           'warn' => null, 'budget' => '1.32M'],
          ['date' => '14', 'mon' => 'мая', 'dow' => 'вт', 'weekend' => false,
           'senior' => [null, null, null],
           'main'   => [['Phai', '09–17'], ['Long', '09–17'], ['Саша', '17–23'], ['Вася', '17–23']],
           'banya'  => [null],
           'custom' => [null],
           'warn' => 'Нет старшего!', 'budget' => '0.98M'],
          ['date' => '15', 'mon' => 'мая', 'dow' => 'ср', 'weekend' => false,
           'senior' => [['Султан ★', '09–17', 'senior'], null, null],
           'main'   => [['Оля', '09–17'], ['Лёша', '14–22'], ['Long', '16–23'], ['Вася', '17–23']],
           'banya'  => [['Phai', '09–23']],
           'custom' => [null],
           'warn' => null, 'budget' => '1.45M'],
          ['date' => '16', 'mon' => 'мая', 'dow' => 'чт', 'weekend' => false,
           'senior' => [['Лёша ★', '09–17', 'senior'], ['Phai ★', '09–17', 'senior'], null],
           'main'   => [null, ['Оля', '16–23'], ['Саша', '17–23'], null],
           'banya'  => [['An', '10–18']],
           'custom' => [['Long', '18–22']],
           'warn' => null, 'budget' => '1.28M'],
          ['date' => '17', 'mon' => 'мая', 'dow' => 'пт', 'weekend' => false,
           'senior' => [null, ['Лёша ★', '16–23', 'senior'], ['Phai ★', '16–23', 'senior']],
           'main'   => [['Султан', '16–23'], ['Саша', '09–17'], ['Long', '09–22'], ['Вася', '17–23']],
           'banya'  => [null],
           'custom' => [['Оля', '19–23']],
           'warn' => null, 'budget' => '1.62M'],
          ['date' => '18', 'mon' => 'мая', 'dow' => 'сб', 'weekend' => true,
           'senior' => [['Long ★', '16–23', 'senior'], null, null],
           'main'   => [['Султан', '09–22'], ['Лёша', '09–22'], null, ['Вася', '17–23']],
           'banya'  => [['An', '10–18']],
           'custom' => [['Phai', '16–23']],
           'warn' => null, 'budget' => '1.51M'],
          ['date' => '19', 'mon' => 'мая', 'dow' => 'вс', 'weekend' => true,
           'senior' => [null, null, null],
           'main'   => [['Оля', '09–22'], ['Саша', '17–23'], ['Long', '09–17'], null],
           'banya'  => [null],
           'custom' => [null],
           'warn' => 'Нет старшего и мало людей', 'budget' => '0.92M'],
          ['date' => '20', 'mon' => 'мая', 'dow' => 'пн', 'weekend' => false,
           'senior' => [['Султан ★', '09–17', 'senior'], null, ['Лёша ★', '16–23', 'senior']],
           'main'   => [['Оля', '09–22'], ['Long', '09–17'], ['Саша', '17–23'], ['Вася', '17–23']],
           'banya'  => [['Phai', '10–18']],
           'custom' => [null],
           'warn' => null, 'budget' => '1.38M'],
      ];

      foreach ($rows as $r):
          $weekendCls = $r['weekend'] ? ' weekend' : '';
          $cellCls    = $r['weekend'] ? ' weekend' : '';
      ?>
        <div class="sch-row<?= $weekendCls ?> sch-date-cell">
          <span class="sch-day-num"><?= $r['date'] ?></span>
          <span class="sch-day-mon"><?= $r['mon'] ?></span>
        </div>
        <div class="sch-row<?= $weekendCls ?> sch-dow-cell"><?= $r['dow'] ?></div>

        <?php foreach ($r['senior'] as $s): ?>
          <div class="sch-cell<?= $cellCls ?>" data-help-abs="Кликни → форма «выбрать сотрудника (только с ★) + время + опционально зал».">
            <?php if ($s): ?>
              <div class="sch-shift senior"><span class="sch-name"><?= htmlspecialchars($s[0]) ?></span><span class="sch-time"><?= htmlspecialchars($s[1]) ?></span></div>
            <?php else: ?>
              <span class="sch-empty">+</span>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
        <div class="sch-divider senior"></div>

        <?php foreach ($r['main'] as $s): ?>
          <div class="sch-cell<?= $cellCls ?>">
            <?php if ($s): ?>
              <div class="sch-shift main"><span class="sch-name"><?= htmlspecialchars($s[0]) ?></span><span class="sch-time"><?= htmlspecialchars($s[1]) ?></span></div>
            <?php else: ?>
              <span class="sch-empty">+</span>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
        <div class="sch-divider main"></div>

        <?php foreach ($r['banya'] as $s): ?>
          <div class="sch-cell<?= $cellCls ?>">
            <?php if ($s): ?>
              <div class="sch-shift banya"><span class="sch-name"><?= htmlspecialchars($s[0]) ?></span><span class="sch-time"><?= htmlspecialchars($s[1]) ?></span></div>
            <?php else: ?>
              <span class="sch-empty">—</span>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
        <div class="sch-divider banya"></div>

        <?php foreach ($r['custom'] as $s): ?>
          <div class="sch-cell<?= $cellCls ?>">
            <?php if ($s): ?>
              <div class="sch-shift custom"><span class="sch-name"><?= htmlspecialchars($s[0]) ?></span><span class="sch-time"><?= htmlspecialchars($s[1]) ?></span></div>
            <?php else: ?>
              <span class="sch-empty">—</span>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
        <div class="sch-divider custom"></div>

        <div class="sch-add-block-cell"></div>

        <?php if ($r['warn']): ?>
          <div class="sch-warn-cell bad" title="<?= htmlspecialchars($r['warn']) ?>">⚠</div>
        <?php else: ?>
          <div class="sch-warn-cell ok">✓</div>
        <?php endif; ?>
        <div class="sch-budget-cell"><?= $r['budget'] ?></div>
      <?php endforeach; ?>

      <!-- ИТОГО row -->
      <div class="sch-totals-cell" style="grid-column: span 2;" data-help-abs="Итог за период по каждому слоту: количество назначенных смен (для слотов) и сумма часов / ФОТ (справа).">Итого</div>
      <div class="sch-totals-cell">5 шифтов</div>
      <div class="sch-totals-cell">1</div>
      <div class="sch-totals-cell">4</div>
      <div class="sch-divider"></div>
      <div class="sch-totals-cell">14</div>
      <div class="sch-totals-cell">14</div>
      <div class="sch-totals-cell">12</div>
      <div class="sch-totals-cell">10</div>
      <div class="sch-divider"></div>
      <div class="sch-totals-cell">5</div>
      <div class="sch-divider"></div>
      <div class="sch-totals-cell">3</div>
      <div class="sch-divider"></div>
      <div class="sch-add-block-cell"></div>
      <div class="sch-warn-cell bad" style="background: rgba(184,135,70,.08); font-weight: 700;">3 ⚠</div>
      <div class="sch-totals-cell" style="font-size: 12px;">18.4M ₫</div>
    </div>
  </div>

  <!-- Senior shifts summary -->
  <div class="sch-senior-block" data-help-abs="Сводка по старшим за период — кто и когда был старшим. ⚠ на днях без назначенного старшего.">
    <h3>⭐ Старшие смены недели (13–19 мая)</h3>
    <div class="sch-senior-list">
      <div class="sch-senior-row"><span class="who">Султан</span><span class="days">ср 09–17, пн (20.05) 09–17</span></div>
      <div class="sch-senior-row"><span class="who">Лёша</span><span class="days">пн 09–17, чт 09–17, пт 16–23, пн (20.05) 16–23</span></div>
      <div class="sch-senior-row"><span class="who">Phai</span><span class="days">пн 16–23, чт 09–17, пт 16–23</span></div>
      <div class="sch-senior-row"><span class="who">Long</span><span class="days">сб 16–23</span></div>
      <div class="sch-senior-row" style="color: var(--danger);"><span class="who">⚠ вт</span><span class="days">нет старшего на смену</span></div>
      <div class="sch-senior-row" style="color: var(--danger);"><span class="who">⚠ вс</span><span class="days">нет старшего на смену</span></div>
    </div>
  </div>

  <!-- Snapshots -->
  <div class="sch-snapshots" data-help-abs="Версии графика. Кнопкой «Сохранить версию» создаётся snapshot — можно вернуться к любой прошлой версии (если поменяли черновик и хотите откатить).">
    <span class="sch-snap-label">Версии:</span>
    <span class="sch-snap-pill current">текущая <span class="when">17.05 14:30</span></span>
    <span class="sch-snap-pill" data-demo-noop="load-snapshot">Май чистовая <span class="when">15.05 09:12</span></span>
    <span class="sch-snap-pill" data-demo-noop="load-snapshot">авто-бэкап <span class="when">16.05 11:40</span></span>
    <span class="sch-snap-pill" data-demo-noop="load-snapshot">черновик-март <span class="when">28.04 17:55</span></span>
    <span style="flex:1"></span>
    <button class="sch-btn" data-demo-noop="save-snapshot">Сохранить текущую версию</button>
  </div>

</div>

<script src="/assets/js/schedule.js?v=20260517_v4_floating_tip" defer></script>
