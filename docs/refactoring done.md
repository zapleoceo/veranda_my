# Payday2 — что уже сделано (по [PAYDAY2_REFACTORING_PLAN.md](PAYDAY2_REFACTORING_PLAN.md))

Актуальная версия ассетов в `payday2/view.php`: **`20260419_0010`** (после деплоя — Ctrl+F5 на `/payday2/`).

---

## Этап 1 — критичные уязвимости и баги (сделано)

| Пункт | Суть |
|-------|------|
| **1.1** | `payday2/post/create_transfer.php` — убрана синтаксическая ошибка; fallback без JS даёт понятное сообщение; основной сценарий — `?ajax=create_transfer` в `ajax.php`. |
| **1.2** | `veranda_require('payday')` + `auth_check` в `post.php`; `veranda_require('payday')` в `ajax.php`. |
| **1.3** | CSRF: `payday2_ensure_csrf` / `payday2_csrf_valid` в `functions.php`, токен в `PAYDAY_CONFIG` и скрытые поля в POST-формах `view.php`, заголовок `X-Payday2-Csrf` через обёртку `fetch` в `payday2.js`, проверка POST в `ajax.php` и `post.php`. |

---

## Этап 2 — хардкод и настройки (сделано)

| Пункт | Суть |
|-------|------|
| **2.1** | `payday2/local_config.json`, `local_config.example.json`, класс `payday2/LocalSettings.php`, подключение из `config.php`; значения Telegram, `service_user_id`, счета Poster, `balance_sinc_account_id` читаются из конфига в `ajax.php` / `view.php`. |
| **2.2** | Кнопка ⚙ в шапке, модалка настроек в `view.php`, стили в `payday2.css`. |
| **2.3** | `?ajax=save_local_config` (POST JSON, CSRF), `LocalSettings::persistPayload()` — атомарная запись JSON. |

Дополнительно (не в исходном списке пунктов): подсказки про `-100…` и `chat_id`, ответы Telegram API как **400** с текстом ошибки; правки текста/вёрстки модалки (предупреждение красным, сетка полей).

---

## Этап 3 — оптимизация бэкенда

| Пункт | Суть |
|-------|------|
| **3.1** | `mail_out` в `ajax.php`: не перечитываем `.env` (используется `$_ENV` после `auth_check`); в `imap_search` добавлен **`BEFORE`** (день после `dateTo`), вместе с **`SINCE`** задаёт узкий диапазон дат на стороне почты. |
| **3.2** | По плану: Poster API / пакетный SQL во `view.php` — ещё не делалось. |
| **3.3** | По плану: текст «Сбросить день (Soft Reset)» и логика восстановления записей — ещё не делалось. |

---

## Чеклист для проверки после релиза

1. Жёсткое обновление `/payday2/` (новый `?v=` у JS/CSS).
2. Пользователь **с** правом `payday`: даты, IN/OUT, синки, связи, finance, Telegram-скрин, ⚙ сохранение настроек.
3. Пользователь **без** права: 403 на защищённые обработчики.
4. При устаревшей сессии CSRF — полное обновление страницы.
