# TR3 → Telegram: действия менеджера по брони (дизайн)

Дата: 2026-04-30

## Цель
Расширить сценарии работы менеджера с бронью из TR3 в Telegram, сохранив текущую логику отправки в Poster, но добавив:
- кнопку «отказать» и «восстановить»
- обработку «бронь устарела» при нажатии «в Poster» и возможность «обновить время и отправить»

## Текущий поток данных (as-is)
- TR3 UI: [tr3/index.php](file:///workspace/tr3/index.php), [tr3/assets/app.js](file:///workspace/tr3/assets/app.js)
- Backend TR3: [tr3/api.php](file:///workspace/tr3/api.php) (`ajax=submit_booking`)
- БД: `reservations` создаётся/мигрируется в [Database::createReservationsTable](file:///workspace/src/classes/Database.php#L572-L641)
- Telegram webhook/callback: [telegram_webhook.php](file:///workspace/telegram_webhook.php)
- Callback «в Poster»: [webhook_actions/vposter.php](file:///workspace/webhook_actions/vposter.php)
- Poster push: [PosterReservationHelper::pushToPoster](file:///workspace/src/classes/PosterReservationHelper.php)

Схема: TR3 → backend (submit_booking) → INSERT в reservations → Telegram (сообщение менеджеру + inline кнопка) → callback_query → webhook action → Poster API.

## Хранилище состояния брони (to-be)
Используем существующие поля таблицы `reservations`:
- Активна/новая: `deleted_at IS NULL`
- Отклонена: `deleted_at IS NOT NULL`
- Метка «кто отклонил»: `deleted_by` (строка)

Существующая логика Poster:
- `poster_id`, `is_poster_pushed` остаются без изменений (как сейчас используется при отправке в Poster).

## Стандартизация callback_data
Формат: `<action>:<reservation_id>`

Планируемые actions:
- `vposter:<id>` — отправка в Poster (как сейчас)
- `vdecline:<id>` — отказ
- `vrestore:<id>` — восстановление после отказа
- `vposter_fix:<id>` — «обновить время и отправить в Poster» (для сценария “устарела”)
- `vposter_cancel:<id>` — «отмена» (вернуть сообщение/кнопки к исходному состоянию)

## Авторизация callback
Проверка прав идентична текущей для `vposter`:
- разрешены пользователи, у которых `permissions_json` содержит `vposter_button` или `admin`.

## Telegram UX и изменение сообщений
Все изменения состояния выполняются через редактирование исходного сообщения в Telegram:
- `editMessageText` (обновление текста)
- `editMessageReplyMarkup` (обновление клавиатуры)

## Новые сценарии

### 1) Новая бронь (сообщение менеджеру)
Inline-клавиатура при создании брони (вместо одной кнопки) становится 2-кнопочной:
- «в Poster» (`vposter:<id>`)
- «отказать» (`vdecline:<id>`)

### 2) «Отказать»
Действия:
- найти бронь по `reservations.id`
- поставить `deleted_at = NOW()`, `deleted_by = <manager_label>`
- отредактировать сообщение: добавить строку внизу вида
  - `Бронь отказана менеджером <имя/ID менеджера>`
- заменить клавиатуру на одну кнопку:
  - «восстановить» (`vrestore:<id>`)

### 3) «Восстановить»
Действия:
- найти бронь по `reservations.id`
- снять отказ: `deleted_at = NULL`, `deleted_by = NULL`
- отредактировать сообщение: вернуть исходный текст (без строки «Бронь отказана…»)
- вернуть клавиатуру «в Poster» + «отказать»

### 4) «в Poster» + ошибка “бронь устарела”
Перед отправкой в Poster:
- сравнить `reservations.start_time` с текущим временем сервера
- если `start_time > now`: работать по текущей логике без изменений
- если `start_time <= now`: считать бронь устаревшей и не отправлять в Poster

При “устарела”:
- отредактировать сообщение, добавив понятный блок:
  - `Время начала брони уже прошло. Можно обновить бронь так, чтобы она начиналась с текущего времени, а время окончания осталось прежним.`
- заменить inline-клавиатуру на:
  - «Обновить время и отправить в Poster» (`vposter_fix:<id>`)
  - «Отмена» (`vposter_cancel:<id>`)

#### 4.1) «Обновить время и отправить в Poster» (`vposter_fix`)
Действия:
- новое `start_time = now` (секунды = 0)
- `end_time` должно остаться прежним (логически): `old_end = old_start + duration`
- обновить `duration` так, чтобы `end_time` совпал с `old_end`: `duration = max(1, round((old_end - new_start)/60))`
- обновить запись в БД
- отправить в Poster по текущей логике
- обновить сообщение (например, дописать, что отправлено с обновлённым временем)

#### 4.2) «Отмена» (`vposter_cancel`)
Действия:
- БД не менять
- вернуть исходный текст сообщения и исходную клавиатуру «в Poster» + «отказать»

## Рефакторинг (минимально-инвазивный)
Чтобы избежать хрупких операций “вырезать строку” из сообщения:
- вынести генерацию “базового” текста сообщения менеджеру в общий helper (используется при создании и при восстановлении/отмене)
- вынести генерацию клавиатур в общий helper (две кнопки / одна кнопка / “устарела” две кнопки)

## Ошибки и логирование
- Логировать (через существующий механизм проекта) события:
  - decline / restore / poster_fix / poster_cancel / vposter success / vposter error
- Логировать исключения Telegram/Poster с причиной, но без секретов.

