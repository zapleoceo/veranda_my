# TR4: scheme_num mapping + rotate_180 per hall

## Контекст

- В Poster у столов есть `table_id` (внутренний ID Poster) и текстовые поля `table_num/table_title`.
- В legacy TR3 логика бронирования и доступности оперирует “номером стола на схеме” (далее `scheme_num`) и исторически вытаскивала его только если `table_title` или `table_num` — чисто цифровые.
- В новой БД таблице `reservation_table_settings` мы уже храним:
  - `poster_table_id` = Poster `table_id` (стабильный ключ)
  - `scheme_num` (число для бронирования/схемы)
  - `display_name`, `capacity`, `show_on_canvas`, `bookable`

## Проблемы

1. **`scheme_num` ≠ `table_id`**
   - `table_id` — идентификатор Poster.
   - `scheme_num` — номер, который используется клиентом и legacy-логикой для доступности/брони.
   - Они могут совпадать случайно, но смысл разный.

2. **Столы типа “Room”**
   - Если в Poster `table_num/table_title` не цифровые, старая логика не может вычислить `scheme_num`, и стол становится “небронируемым”, даже если в админке выставлены флаги/ёмкость.

3. **Поворот схемы для hall_id=7**
   - В админке есть кнопка “Rotate”, но флаг поворота не сохраняется и не применяется в TR4.
   - Для hall_id=7 нужно преобразование координат “0 в правом нижнем углу” (эквивалент поворота на 180° вокруг bounding-box world).

## Цели

- Сделать бронирование независимым от того, “цифровой” ли `table_title/table_num` в Poster.
- Сохранить UX TR3: при превышении ёмкости — только предупреждение в форме, не блокировка и без модалки подтверждения.
- Сделать поворот схемы сохраняемым в админке и применяемым в TR4, в т.ч. для hall_id=7.

## Дизайн решений

### A. Маппинг по poster_table_id → scheme_num (Рекомендуется)

1. Источник истины:
   - `poster_table_id` берём из Poster (как сейчас).
   - `scheme_num` берём из `reservation_table_settings.scheme_num`.
2. В TR4 API:
   - `free_tables`: строит `scheme_num` через `tableSettingsByHall[hall_id][poster_table_id].scheme_num`, а не через “цифровой title/num”.
   - `reservations`: аналогично маппит `row.table_id` (Poster) → `scheme_num`.
   - `cap_check`: получает `hall_id` и ищет `capacity` по `scheme_num` в настройках зала.
3. В TR4 фронте:
   - Остаётся договор: `data-table` на DOM = `scheme_num`.
   - “Room” становится bookable, если задан `scheme_num` и `bookable=1`.

Плюсы:
- Работает для любых имён (`Room`, `VIP`, и т.д.) без требований к Poster.
- Не ломает существующие цифровые столы.

### B. Rotate_180 как часть настроек зала

1. Добавить таблицу `reservation_hall_settings`:
   - `spot_id`, `hall_id` (unique)
   - `rotate_180` tinyint(1) default 0
2. /reservations:
   - Кнопка `#resHallRotate` начинает сохранять `rotate_180` в БД через новый `ajax=res_hall_rotate`.
   - При загрузке `res_hall_data` отдаём `rotate_180` и инициализируем UI (state.rot) из БД.
3. TR4:
   - В bootstrap вернуть `hallSettingsByHall[hall_id].rotate_180`.
   - В `renderHallTables(...)` перед масштабированием применять:
     - `x' = minX + maxX - (x + w)`
     - `y' = minY + maxY - (y + h)`
     где `minX/minY/maxX/maxY` — world bounds текущего hall.

Плюсы:
- Единый источник истины: и админка, и TR4 используют один флаг.
- Без хардкода hall_id=7 в коде (можно включить в БД).

## Изменения UI админки (/reservations)

- В модалке редактирования стола добавить поле ввода `scheme_num` (число).
  - Валидация: пусто или int в диапазоне 1..500.
  - Сохранять через существующий `res_table_update` (уже поддерживает `scheme_num`).
- Кнопка Rotate:
  - Делать POST на `res_hall_rotate` и перерендер.

## Изменения в TR4

- `tr3.boot.js` прокидывает `hallSettingsByHall` в `window.__TR_CONFIG__`.
- `app.js`:
  - Использует `hallSettingsByHall` при рендере схемы (поворот на 180°).
- `api_poster.php`:
  - `tr3_api_free_tables` и `tr3_api_reservations` маппят `poster_table_id` → `scheme_num` через `$ctx['tableSettingsByHall']`.
  - `tr3_api_cap_check` работает по `hall_id` и настройкам зала.

## Миграции

- Добавить новую миграцию:
  - `reservations/migrations/002_create_hall_settings.php` (создаёт `reservation_hall_settings`)

## Проверка (Acceptance)

- Для стола с нецифровым именем (`Room`) при заданном `scheme_num` + `bookable=1`:
  - стол становится кликабельным в TR4
  - `free_tables`/`reservations` корректно учитывают его занятость
- Для hall_id=7 при `rotate_180=1`:
  - схема в TR4 визуально разворачивается на 180°
  - совпадает с тем, что видно при включённом rotate в админке

