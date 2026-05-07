# TR4: Poster table_id booking + rotate_180 per hall + decor fixes

## Контекст

- В Poster у столов есть `table_id` (внутренний ID Poster) и текстовые поля `table_num/table_title`.
- В legacy TR3 UI/доступность оперируют “номером стола на схеме” (далее `scheme_num`) и исторически вытаскивали его только если `table_title` или `table_num` — чисто цифровые.
- При реальном создании брони в Poster используется **`table_id`** (Poster table_id). Сейчас он вычисляется по `table_num`, что ломается для столов с нецифровыми названиями.
- В новой БД таблице `reservation_table_settings` мы уже храним:
  - `poster_table_id` = Poster `table_id` (стабильный ключ)
  - `scheme_num` (номер на схеме, опционально)
  - `display_name`, `capacity`, `show_on_canvas`, `bookable`

## Проблемы

1. **`scheme_num` ≠ `table_id`**
   - `table_id` — идентификатор Poster, именно он участвует в запросе `incomingOrders.createReservation`.
   - `scheme_num` — номер на схеме (для UI/надписей/legacy-совместимости).
   - Они могут совпадать случайно, но смысл разный.

2. **Столы типа “Room”**
   - Сейчас `PosterReservationHelper` ищет `table_id` через совпадение текстового `table_num`/`table_title`. Для `Room` это не срабатывает, поэтому бронь не может корректно уйти в Poster.
   - Для TR4 клиентской доступности/цветов мы тоже не должны требовать “цифровой” `scheme_num`, если есть `poster_table_id` и `bookable=1`.

3. **Поворот схемы для hall_id=7**
   - В админке есть кнопка “Rotate”, но флаг поворота не сохраняется и не применяется в TR4.
   - Для hall_id=7 нужно преобразование координат “0 в правом нижнем углу” (эквивалент поворота на 180° вокруг bounding-box world).

## Цели

- Сделать бронирование независимым от того, “цифровой” ли `table_title/table_num` в Poster: использовать `poster_table_id` как ключ бронирования.
- Сохранить UX TR3: при превышении ёмкости — только предупреждение в форме, не блокировка и без модалки подтверждения.
- Сделать поворот схемы сохраняемым в админке и применяемым в TR4, в т.ч. для hall_id=7.
- Исправить декор: круглый фонтан, правильное положение фонтана, “трава” по ширине канваса и прижатая к низу.

## Дизайн решений

### A. Ключ бронирования = poster_table_id (Рекомендуется)

1. Источник истины:
   - `poster_table_id` используется для бронирования и для статусов free/busy.
   - `scheme_num` остаётся опциональным: для отображения “цифры стола”, сортировки и legacy-меты.
2. Изменение модели брони в нашей БД:
   - В таблицу `reservations` добавить колонку `poster_table_id INT NULL`.
   - TR4 submit сохраняет и `table_num` (для совместимости и читаемости), и `poster_table_id`.
3. Создание брони в Poster:
   - Если в нашей брони есть `poster_table_id`, то `PosterReservationHelper` отправляет его напрямую в `incomingOrders.createReservation` как `table_id`, без lookup по `table_title/table_num`.
4. Доступность/цвета в TR4:
   - `free_tables` и `reservations` возвращают `free_table_ids`/`occupied_now_table_ids`/`soon_booking_table_ids` на базе Poster `table_id`.
   - TR4 клиент хранит наборы по `poster_table_id` и раскрашивает/блокирует столы по ним.
   - Для UI используется `display_name`, а для подписи “номер” используется `scheme_num` если задан, иначе `display_name`.
5. Предупреждение по ёмкости:
   - `cap_check` принимает `poster_table_id` и `hall_id`, возвращает warn, если guests > capacity для этого `poster_table_id` в `reservation_table_settings`.

Плюсы:
- Работает для любых имён (`Room`, `VIP`, и т.д.) без требований к Poster.
- Убирает зависимость от “цифровых” `table_num/table_title` для брони в Poster.

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

### C. Декор hall_id=2 (Veranda): фонтан + трава

1. Фонтан должен быть круглым вне зависимости от aspect ratio world bounds:
   - Если декор `fountain` задан в режиме `rel`, то размер считается от `min(boxW, boxH)` (изотропно), а не отдельно от `boxW` и `boxH`.
2. Позиция фонтана:
   - Разместить справа, но чуть выше столика `scheme_num=13` (если существует) через вычисление в world координатах на базе таблиц (без ручного подбора пикселей).
3. Трава:
   - Декор “трава/растения” должен занимать всю ширину канваса и быть прижатым низом к низу канваса (anchor bottom), чтобы не “висел” при смене bounds.

## Изменения UI админки (/reservations)

- В модалке редактирования стола показывать `poster_table_id` (read-only).
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
  - `tr3_api_free_tables` и `tr3_api_reservations` работают через `poster_table_id` и возвращают списки `*_table_ids`.
  - `tr3_api_cap_check` работает по `poster_table_id` + `hall_id` и настройкам зала.

## Изменения в бронированиях (наша БД → Poster)

- `reservations` таблица:
  - добавить `poster_table_id INT NULL`
- `tr4/api_booking.php`:
  - принимать `poster_table_id` из payload и сохранять в таблицу брони
- `PosterReservationHelper`:
  - если `poster_table_id` задан, использовать его как `table_id` для `incomingOrders.createReservation` без поиска по `table_num/table_title`

## Миграции

- Добавить новую миграцию:
  - `reservations/migrations/002_create_hall_settings.php` (создаёт `reservation_hall_settings`)
  - `reservations/migrations/003_add_poster_table_id_to_reservations.php` (добавляет `poster_table_id` в `reservations`)

## Проверка (Acceptance)

- Для стола с нецифровым именем (`Room`) при `bookable=1` и наличии `poster_table_id`:
  - стол становится кликабельным в TR4
  - бронь корректно уходит в Poster (используется `table_id = poster_table_id`)
  - `free_tables`/`reservations` корректно учитывают его занятость по `poster_table_id`
- Для hall_id=7 при `rotate_180=1`:
  - схема в TR4 визуально разворачивается на 180°
  - совпадает с тем, что видно при включённом rotate в админке
- Для декора hall_id=2:
  - фонтан остаётся круглым при любых bounds
  - фонтан расположен справа-чуть-выше столика 13
  - трава занимает всю ширину и прижата низом к низу канваса
