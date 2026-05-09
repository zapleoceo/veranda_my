## Цель

Сделать изолированную подсистему `/testai`:

- Telegram webhook принимает сообщения из чатов/групп.
- Gemini Flash 2.5 классифицирует каждое сообщение: это “анонс события” или нет, и на какую дату анонс.
- В БД хранится одна таблица только с релевантными “анонс-сообщениями” (минимум данных, но достаточно для повторной генерации).
- Страница `/testai` показывает HTML-анонс на выбранную дату, генерируя его вручную по запросу (и кешируя результат).

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

### Таблица `testai_tg_announces`

Одна таблица, только для сообщений, которые Gemini посчитал анонсами:

- `id` BIGINT auto_increment
- `tg_chat_id` BIGINT NOT NULL
- `tg_message_id` BIGINT NOT NULL
- `tg_user_id` BIGINT NULL
- `received_at` DATETIME NOT NULL
- `text` TEXT NOT NULL
- `announce_date` DATE NOT NULL
- `confidence` INT NOT NULL
- `meta_json` TEXT NULL

Уникальность:

- UNIQUE(`tg_chat_id`,`tg_message_id`)

Очистка:

- по cron можно удалять записи старше N дней (опционально, вне текущего MVP).

## Контракты Gemini

### 1) Классификация входящего сообщения (webhook)

Вход: текст сообщения + контекст (chat title/type, user, timestamp).

Выход (строгий JSON):

- `is_announce`: boolean
- `announce_date`: string `YYYY-MM-DD` или пусто
- `confidence`: number 0..100
- `reason`: string (кратко)

Правило записи в БД:

- если `is_announce=true` и дата валидна → пишем строку в таблицу.

### 2) Генерация HTML анонса на дату (ручной запрос со страницы)

Вход: выбранная дата + список всех записей из `testai_tg_announces` (или только релевантных по окну дат, если будет нужно).

Выход: HTML (строка) для вставки в страницу.

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
- Для каждого текстового сообщения вызывает Gemini классификацию.
- При `is_announce=true` сохраняет строку в таблицу.
- Всегда отвечает `ok` (чтобы Telegram не ретраил бесконечно).

## Кеш

Файловый кеш HTML:

- `/testai/cache/announce_YYYY-MM-DD.html`

Правило:

- `generate` всегда перезаписывает кеш.
- `get` читает кеш, если файл есть.

## Изоляция

- В `/testai` свой webhook и свои env-ключи, не пересекающиеся с существующим `telegram_webhook.php`.
- Логика Gemini и санитайзер не должны менять поведение остального проекта.

