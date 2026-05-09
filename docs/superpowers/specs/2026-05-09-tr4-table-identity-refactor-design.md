# TR4: разделение ID стола и названия (display_name)

Дата: 2026-05-09

## Цель
Убрать путаницу между `table_id` (Poster ID) и “номером/названием” стола в интерфейсах и сообщениях.

В системе бронирования должны существовать ровно два индикатора стола:
- `poster_table_id` — ID стола в Poster (используется для запросов к Poster и отправки брони в Poster).
- `table_label` — отображаемое имя стола (используется во всех UI и текстах сообщений). Источник истины — `reservation_table_settings.display_name`.

## Текущее состояние (проблема)
- TR4 UI получает столы через `ajax=hall_tables`, который возвращает сырые поля Poster (`table_num`, `table_title`, `table_id`).
- В Poster поле `table_num` может содержать значения, похожие на ID (например, 118), из-за чего UI/запросы/Telegram начинают показывать и сохранять не то, что нужно.
- Текст сообщения менеджерам в Telegram формируется из поля `reservations.table_num`, поэтому при попадании туда Poster-значений сообщение показывает ID.

## Целевое поведение (to-be)
- UI отображает только `table_label`.
- При создании брони всегда сохраняются:
  - `poster_table_id`
  - `table_label`
- При отправке в Poster используется только `poster_table_id`.
- Telegram/WhatsApp/Zalo тексты всегда используют `table_label`.
- `table_num` становится legacy-полем: может оставаться для обратной совместимости, но не является источником отображаемого имени.

## Модель данных
Таблица `reservations` (миграция через `Database::createReservationsTable()`):
- добавить `poster_table_id BIGINT NULL` (если ещё нет)
- добавить `table_label VARCHAR(64) NULL`

Правила заполнения:
- при создании брони из TR4:
  - `poster_table_id` берётся из payload
  - `table_label` вычисляется на сервере по `reservation_table_settings` для данного `poster_table_id` (использовать `display_name`, если пусто — fallback на `scheme_num`, если пусто — `#<poster_table_id>`)
  - `table_num` может быть заполнено для legacy (например, `scheme_num`), но не используется в сообщениях/UI

## Контракты API

### 1) `ajax=hall_tables` (TR4)
Возвращать таблицы с явными полями:
- `poster_table_id` (вместо/в дополнение `table_id`)
- `table_label` (строго из `reservation_table_settings.display_name`, fallback на `scheme_num`, затем `#id`)
- геометрия/shape остаются как есть

UI не должен отображать `table_num`/`table_title` от Poster.

### 2) `ajax=submit_booking` (TR4)
Принимать минимум:
- `poster_table_id` (обязательный)
- `table_label` (можно принимать, но сервер всё равно пересчитывает и сохраняет свой `table_label`)

Сервер:
- пересчитывает `table_label` по `reservation_table_settings`
- сохраняет `poster_table_id`, `table_label` в `reservations`

### 3) `ajax=tg_state_create` (TR4)
Хранить state с `poster_table_id` и `table_label` (сервер пересчитывает `table_label` так же, как в submit_booking).

## Генерация сообщений
`ReservationTelegram::buildManagerText()`:
- выводить `table_label` (если пусто — fallback на `table_num`, затем `poster_table_id`).

## Стратегия миграции/совместимости
- Новые брони записывают `poster_table_id` + `table_label`.
- Старые брони:
  - Telegram редактирования и просмотр должны fallback-ить на `table_num`, чтобы ничего не ломалось.
  - При необходимости можно добавить “ленивую” миграцию: если у брони есть `poster_table_id`, но пуст `table_label`, при следующем действии (например, vposter) заполнить `table_label`.

## Тестирование
Добавить тесты (node scripts/tests):
- проверка, что `hall_tables` отдаёт `table_label`
- проверка, что `submit_booking` записывает `table_label` и `poster_table_id`
- проверка, что `ReservationTelegram` использует `table_label` в тексте

## Не входит в объём
- массовая миграция всех существующих записей в БД
- переименование/удаление legacy-колонок

