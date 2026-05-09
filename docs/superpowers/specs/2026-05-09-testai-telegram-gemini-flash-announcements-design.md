## Цель

Сделать изолированную подсистему `/testai`:

- Telegram webhook принимает сообщения из чатов/групп.
- В БД хранится “сырое” хранилище сообщений за последние ~3 месяца (с источником: chat/message id, автор, время), плюс распознанный текст медиа (аудио/картинка) без хранения самого файла.
- Раз в день делается саммари дня (и структурированное извлечение событий) из накопленных сообщений.
- Страница `/testai` показывает HTML-анонс на выбранную дату, генерируя его вручную по запросу через Gemini Flash 2.5 (и кешируя результат).

## Нельзя

- Не сохранять токены/ключи в репозиторий.
- Не отдавать “сырой” HTML от ИИ без санитайза.

## Переменные окружения

Используются существующие `.env` механизмы проекта.

- `ai_tg_bot` — токен Telegram-бота для `/testai`.
- `gemini_key` — API ключ Gemini.
- `POSTER_SPOT_TIMEZONE` / `POSTER_API_TIMEZONE` — переиспользуются как timezone по умолчанию.

Рекомендуемые дополнительные:

- `TESTAI_ALLOWED_CHAT_IDS` — список разрешённых чатов/групп (через запятую).
- `TESTAI_ADMIN_KEY` — ключ для ручной генерации через web (если нужно ограничить доступ).

## Хранилище

### Таблица `testai_tg_messages_raw`

Основная таблица: хранит все сообщения (и результаты распознавания медиа) за последние ~3 месяца.

- `id` BIGINT auto_increment
- `tg_chat_id` BIGINT NOT NULL
- `tg_chat_type` VARCHAR(16) NOT NULL
- `tg_chat_title` VARCHAR(255) NULL
- `tg_message_id` BIGINT NOT NULL
- `tg_user_id` BIGINT NULL
- `tg_username` VARCHAR(64) NULL
- `tg_name` VARCHAR(128) NULL
- `received_at` DATETIME NOT NULL
- `text` TEXT NOT NULL
- `media_type` VARCHAR(16) NULL
- `media_file_id` VARCHAR(255) NULL
- `media_file_unique_id` VARCHAR(255) NULL
- `media_mime` VARCHAR(128) NULL
- `media_duration_sec` INT NULL
- `media_text` TEXT NULL
- `meta_json` TEXT NULL

Уникальность:

- UNIQUE(`tg_chat_id`,`tg_message_id`)

Очистка:

- по cron удалять записи старше 90 дней.

### Таблица `testai_daily_summaries`

Хранит результаты “сжатия” за день (чтобы не гонять Gemini по всем сообщениям каждый раз).

- `day` DATE PRIMARY KEY
- `summary_text` TEXT NOT NULL
- `events_json` TEXT NOT NULL
- `created_at` DATETIME NOT NULL

Очистка:

### 1) Распознавание медиа (webhook, при наличии)

Триггеры:

- фото → OCR (вытянуть текст с афиши/картинки)
- голос/аудио → transcript

Вход: контент медиа (скачанный из Telegram по `file_id`) + минимальный контекст.

Выход (строгий JSON):

- `text`: string
- `lang`: string или пусто
- `confidence`: number 0..100

Правило хранения:

- в `testai_tg_messages_raw.media_text` сохраняется распознанный текст, файл не сохраняется.

Примечание по “ссылке на файл”:

- постоянную ссылку на Telegram файл хранить нельзя безопасно (она содержит bot token и зависит от файлового пути).
- вместо этого храним `media_file_id`/`media_file_unique_id`, чтобы при необходимости скачивать заново.

### 2) Классификация/извлечение событий для daily summary

Вход: все сообщения за день (text + media_text) и минимальный контекст.
 
Выход (строгий JSON):

- `summary_text`: string
- `events`: array объектов:
  - `announce_date`: `YYYY-MM-DD`
  - `title`: string
  - `facts`: array[string] (время, место, условия)
  - `confidence`: number 0..100
  - `sources`: array объектов `{ tg_chat_id, tg_message_id }`

Правило записи:

- ежедневно формируется строка в `testai_daily_summaries` за предыдущий день.

### 3) Генерация HTML анонса на дату (ручной запрос со страницы)

Вход: выбранная дата + `events_json` за последние N дней. Если уверенности недостаточно или в summary нет данных по дате — дополнительно подмешиваются сырые сообщения за “сегодня” и/или дни-кандидаты.

## Безопасность HTML

Перед показом на странице результат проходит whitelist-санитайзер:

- Разрешённые теги (минимум): `div`, `p`, `br`, `strong`, `b`, `em`, `i`, `ul`, `ol`, `li`, `h2`, `h3`, `a`, `span`.
- Разрешённые атрибуты: у `a` только `href`, `target`, `rel`.
- Запрещено: `script`, `style`, любые `on*` атрибуты, `javascript:` ссылки.

## Маршруты /testai

### `/testai/index.php`

- UI: выбор даты (`?date=YYYY-MM-DD`), кнопка “Сгенерировать”.
- Рендерит кешированный HTML, если он есть.

### `/testai/api.php`

AJAX endpoints:

- `ajax=get&date=YYYY-MM-DD` → возвращает `{ ok: true, html }` из кеша (если нет — `html: ""`).
- `ajax=generate&date=YYYY-MM-DD` → генерирует HTML через Gemini, санитайзит, сохраняет кеш, возвращает `{ ok: true, html }`.

### `/testai/webhook.php`

- Принимает Telegram updates.
- Фильтрует чаты, если задан `TESTAI_ALLOWED_CHAT_IDS`.
- Сохраняет сообщение в `testai_tg_messages_raw`.
- Если есть медиа (аудио/картинка) — скачивает по `file_id`, вызывает распознавание и сохраняет `media_text`.
- Всегда отвечает `ok` (чтобы Telegram не ретраил бесконечно).

### `/testai/daily.php` (cron)

- Запускается 1 раз в день.
- Берёт сообщения за вчера, вызывает Gemini: `summary_text + events`.
- Сохраняет в `testai_daily_summaries`.

## Кеш

Файловый кеш HTML:

- `/testai/cache/announce_YYYY-MM-DD.html`

Правило:

- `generate` всегда перезаписывает кеш.
- `get` читает кеш, если файл есть.

## Изоляция

- В `/testai` свой webhook и свои env-ключи, не пересекающиеся с существующим `telegram_webhook.php`.
- Логика Gemini и санитайзер не должны менять поведение остального проекта.
