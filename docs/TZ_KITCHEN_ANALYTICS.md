# Проект Veranda: Kitchen Analytics, КухняОнлайн, Онлайн‑меню и внутренние инструменты
Документ ТЗ: сначала бизнес‑логика (без техдеталей), далее техническая спецификация, достаточная для воспроизведения проекта целиком (frontend + backend + cron + UX‑поведение). ТЗ для внутреннего использования (локально), не публикуется. В репозитории эти документы не должны храниться: папки `/docs/` и `/ops/` находятся в `.gitignore` (локальные файлы).

---

## Часть 1. Бизнес‑ТЗ

### 1. Цели
- Следить за скоростью кухни/бара “в моменте” (табло) и по часам (аналитика).
- Разбирать спорные кейсы по конкретным позициям чека: когда отправили на кухню и когда нажали “готово”.
- Автоматические Telegram‑алерты и статусное сообщение по “долгим” позициям.
- Управление онлайн‑меню: публикация, видимость категорий, переводы, сортировка.
- Инструменты менеджера: доступы/права, логи, внутренние финансовые сверки OUT/IN.
- Отдельные вспомогательные экраны:
  - Zapara — нагрузка по часам/дням недели (чеки/блюда).
  - Cooked (errors) — проверка корректности событий “отправили/приготовили/удалили” по истории Poster.
  - TableReservation — схема столов и проверка занятости/бронирования.

### 2. Роли и доступы
- Авторизация по Google‑аккаунту.
- После авторизации доступы выдаются галочками в админке (Access).
- Права выдаются точечно: не все менеджеры должны видеть Payday/логи/админку.
- В проекте есть “общая шапка” — меню пользователя (аватарка, раскрытие по клику/тапу и по hover на десктопе).

### 3. Публичные страницы (без авторизации)
**3.1. /links/** (быстрые ссылки)
- Страница‑визитка с выбором языка (cookie) и ссылками: Telegram, Instagram, онлайн‑меню, директор, Google Maps.

**3.2. /links/menu-beta.php** (онлайн‑меню)
- Публичная витрина блюд с фильтрами по цехам/категориям.
- Данные берутся из нашей БД (кеш/реплика меню Poster), с переводами RU/EN/VI/KO.
- Показываются только опубликованные позиции и категории/цеха, разрешённые для сайта.

**3.3. /sepay/** (webhook SePay)
- JSON‑endpoint для приёма входящих банковских событий.
- Сохраняет статистику обращений/последний запрос в system_meta.
- Используется Payday для сверки “приходов” SePay с Poster.

**3.4. /tr3** (публичное бронирование)
- Публичная страница бронирования столика с UI‑схемой, выбором даты/времени/стола и предзаказом.
- Локализация: RU/EN/VI (берётся из cookie `links_lang` и параметра `lang`).
- Минимальный предзаказ на гостя (₫/guest) подтягивается из `system_meta` ключа `preorder_min_per_guest_vnd` (управляется в админ‑панели бронирований).
- Основная цель: гость/хостес оформляет бронь в одном потоке, а менеджер получает данные в Telegram/Poster.

**3.5. Legacy: /TableReservation**
- В `.htaccess` есть роут `^TableReservation/?$` → `TableReservation.php`, но в текущей версии репозитория файла `TableReservation.php` нет (роут считается устаревшим/неиспользуемым).
- В `/links/table-reservation.js` есть логика, рассчитанная на отдельную страницу; фактически “публичная бронь” сейчас — `/tr3`, а “админ‑панель броней и схема столов” — `/reservations`.

### 4. Страницы с авторизацией (меню “Отчёты/Управление”)

**4.1. Кухня**
- **КухняОнлайн (/kitchen_online.php)**:
  - Табло текущих блюд: что сейчас готовится, таймер ожидания, фильтр станций (kitchen/bar).
  - Подсветки “долго готовится”, звуковое уведомление, текст “ВСЕ ЗАКАЗЫ ВЫДАНЫ” при пустом списке.
- **Дашборд (/dashboard.php)**:
  - Аналитика по часам за период: среднее/максимум ожидания, нагрузка, фильтры.
  - Переход в детализацию по клику.
- **Таблица (/rawdata.php)**:
  - Детализация по чекам/позициям с временными отметками и статусами.
  - Действие “Не учитывать” исключает позицию из аналитики/табло/алертов.
- **Cooked (/errors.php)**:
  - Отчёт контроля качества данных: сверка sendtokitchen/finishedcooking/delete по истории Poster, без кальянов.
- **Zapara (/zapara.php)**:
  - Графики нагрузки 09:00–24:00 по дням недели и “Среднее”.
  - Два измерения: “Чеки” и “Блюда”, переключение “Колонки/Линия”.
  - Период выбирается “с/по”, значения и типы переключателей запоминаются в браузере.

**4.2. Управление (/admin.php)**
- Вкладка **Access**: список пользователей, выдача прав (галочки).
- Вкладка **Reservations**: настройка соответствия “столы на схеме ↔ столы Poster” и “вместимость столов” (👤).
- Вкладки **Меню**: управление публикацией/переводами/видимостью и выгрузка/экспорт.
- Настройки Telegram: пороги, whitelist, управление статусным сообщением и логами.

Примечание по URL: фактический роут — `/admin/` (см. `.htaccess`), старый `/admin.php` делает 301 редирект на `/admin/`.

**4.3. Payday (/payday) — финансовые сверки (внутренний инструмент)**
- Сверка “входящие/исходящие” платежи между источниками:
  - IN: SePay приход (выписки) ↔ чеки Poster.
  - OUT: “Mail (банк)” ↔ операции Poster Finance.
- Связывание строк “парами” (авто/ручное), отрисовка линий между связанными.
- “Скрытие” строк:
  - крестик/минус скрывает транзакцию с комментарием (без удаления оригинала),
  - глазик в заголовке таблицы показывает/скрывает скрытые,
  - при показе скрытых в колонке Content показывается комментарий, строка подсвечена тёмно‑зелёным.

**4.3.1. Payday2 (/payday2) — кассовые операции и чеки (внутренний инструмент)**
- Отдельный интерфейс для работы с чеками Poster “как в кассе”:
  - поиск чеков, просмотр состава (позиции/цены/кол‑во),
  - операции удаления/редактирования оплаты (через Poster Admin AJAX),
  - Telegram‑уведомления по действиям (удаление/добавление/редактирование, в зависимости от настроек страницы).
- Считается внутренним инструментом, доступ регулируется правами.

**4.4. ЗП сотрудников (/employees.php)**
- Загрузка данных по сотрудникам/типсам/ЗП.
- Доп. строка внизу: баланс “Tips (на счету BIDV)” (счёт Poster id=8), сумма типсов из таблицы и остаток (разница).

**4.5. Прочие отчёты**
- **Баня (/banya.php)** — отдельный отчёт.
- **Roma (/roma.php)** — отдельный отчёт.
- **Логи (/logs.php)** — просмотр/очистка cron/telegram/menu/php_errors.

### 5. Поэлементное UX‑описание (что где и зачем)
Цель раздела: описать “каждый элемент интерфейса” с бизнес‑смыслом, чтобы повторить UX и не потерять функциональность при переписке.

**5.1. КухняОнлайн (/kitchen_online.php)**
- Верхняя панель:
  - Название экрана “КухняОнлайн” — быстрое понимание режима “табло”.
  - “Последнее обновление из Poster” — доверие к данным; помогает понять, что зависло/не синкается.
  - Переключатель/фильтр станции (all/kitchen/bar) — разгрузить экран на станции, где стоит устройство.
  - Меню пользователя (аватар) — единая навигация на другие отчёты/в админку.
- Карточка чека (ko-card):
  - Заголовок `# <receipt_number>` — главный идентификатор для кухни/мена.
  - “🍽️ <table_number>” — контекст, где гости (важно при выдаче).
  - “Офик: <waiter_name>” — кому уточнять детали.
  - Комментарий к чеку (если есть) — быстрые пометки: “без лука”, “срочно” и т.п.
- Строка блюда (ko-item):
  - Название блюда — что готовят.
  - “Старт: HH:MM:SS” — во сколько отправили на кухню (начало ожидания).
  - Live‑таймер — время ожидания “в моменте”, обновляется без перезагрузки страницы.
  - Подсветка “долго” (ko-item-overdue) — визуальный триггер, когда превышен лимит ожидания.
  - Индикатор Telegram (иконка) — показывает, было ли отправлено уведомление и было ли оно обновлено; кликабельная ссылка открывает конкретное сообщение/тред в Telegram.
  - Кнопка “✕ Игнор” (если есть право) — исключить позицию из табло/алертов, чтобы не мешали “аномальные” строки.
- Пустое состояние:
  - Текст “ВСЕ ЗАКАЗЫ ВЫДАНЫ” / пустой список — важный UX‑сигнал, что табло “живое”, а не сломалось.

**5.2. Дашборд (/dashboard.php)**
- Фильтры периода (range picker) — основной способ выбрать интервал для анализа.
- Фильтры часов — чтобы исключать ночные/утренние провалы или анализировать конкретные пики.
- Resync (checkbox) — бизнес‑смысл: “пересчитать данные из Poster заново”, когда есть спорные кейсы/потеря событий.
- Графики:
  - Серии по станциям (Kitchen/Bar) — сравнение узких мест.
  - Метрики: среднее ожидание / максимум ожидания / количество позиций — три измерения “скорость vs нагрузка”.
- Переход в детализацию — из агрегата к конкретным чекам/позициям (rawdata) по тем же фильтрам.

**5.3. Rawdata (/rawdata.php)**
- Фильтры (даты/статусы/часы/станция) — сузить до конкретного потока.
- Группировка по чеку — чтобы видеть цепочку событий в разрезе одного заказа.
- Действие “Не учитывать/Игнор” — убрать мусор/аномалии (удалённые позиции, кальяны, “потерянные” готовности).
- Подгрузка чанками — UX для больших периодов: быстро открыть и докрутить до нужного чека.

**5.4. Cooked (/errors.php)**
- График/сводка по часу — где чаще всего ломается цепочка “send→finish”.
- Список чеков “missing” — быстрый список на проверку и разбор причин.
- Просмотр истории конкретного чека — доказательная база (что реально писал Poster History).

**5.5. Zapara (/zapara.php)**
- Период (с/по) — основная ось анализа.
- Метрика “Чеки ↔ Блюда” — смотреть нагрузку по заказам или по количеству позиций.
- Тип графика “Колонки ↔ Линия” — удобство чтения для разных задач (сравнение vs тренд).
- Прогресс загрузки (X/Y дней) — UX‑прозрачность, т.к. данные грузятся по дням параллельно.

**5.6. Публичная бронь (/tr3)**
- Язык (cookie/параметр) — тексты формы и сообщений.
- Выбор даты/времени (кратность 15 минут) — совпадает с доступностью в Poster.
- Схема столов — визуальный выбор, понятный гостю; подсветка доступности.
- Гости/контакты/комментарий — данные для менеджера.
- Предзаказ — бизнес‑рычаг: при больших компаниях заставляет фиксировать депозит/минималку.
- Модалка “предзаказ принят” — явное завершение потока и очистка черновика формы.

**5.7. Админ бронирований (/reservations)**
- Секция “Столы” (схема) — менеджерский инструмент управления доступностью/вместимостью.
- Настройка `min ₫/guest` — единая точка управления минималкой предзаказа для TR3.
- Принудительная подгрузка данных/диагностика пустой схемы — UX‑страховка при сбоях синка.

---

## Часть 2. Техническая спецификация

### 0. Восстановление проекта (как собрать заново)
Цель раздела: если репозиторий/сервер “пропал”, по этому описанию можно восстановить проект с нуля: структура, конфиги, БД, cron, деплой.

**0.1. Структура репозитория**
- `/`:
  - `index.php` — редирект/входная точка (публичная).
  - `login.php`, `logout.php`, `auth_callback.php` — Google OAuth flow.
  - `auth_check.php` — загрузка `.env`, подключение DB/Auth, requireAuth, функции прав/доступов.
  - `dashboard.php`, `rawdata.php`, `rawdata_receipts_chunk.php`, `kitchen_online.php`, `errors.php`, `zapara.php` — Kitchen Analytics UI.
  - `cron.php`, `telegram_alerts.php`, `menu_cron.php` — CLI/cron точки входа (в папках `scripts/*` лежат thin‑wrappers).
  - `telegram_webhook.php` — вебхук Telegram (✅ Принято/ack и тех. команды).
  - `telegram_alerts.php` — генератор/обновитель Telegram‑алертов по кухне (используется cron).
  - `neworder/` — страница нового заказа (в разработке/переписке).
  - `reservations/` — админ‑панель броней + схема столов + настройки (min ₫/guest и т.д.).
  - `tr3/` — публичное бронирование.
  - `payday/`, `payday2/` — финансовые сверки и инструменты чеков.
  - `admin/` — админ‑панель (Access/Menu/Telegram/Logs/Reservations) в формате controllers+views.
  - `banya/`, `roma/`, `employees/` — отдельные страницы‑модули (в папках, роутятся через `.htaccess`).
  - `.htaccess` — маршрутизация красивых URL (`/links`, `/payday`, `/sepay`, `/TableReservation`).
- `/src/classes/`:
  - `Database.php` — PDO wrapper + DDL для kitchen/menu/payday таблиц.
  - `PosterAPI.php` — клиент Poster API.
  - `KitchenAnalytics.php` — сбор kitchen_stats из Poster history.
  - `PosterMenuSync.php`, `MenuAutoFill.php`, `MenuCategoryAutoFill.php` — синк/автозаполнение меню.
  - `MetaRepository.php` — чтение `system_meta`.
  - `Auth.php` — Google OAuth exchange + userinfo.
- `/scripts/kitchen/`:
  - `resync_range.php` — фоновая пересборка диапазона дат в kitchen_stats.
  - `refresh_poster_closed_current_month.php` — утилита уточнения закрытий.
  - `cron.php` — wrapper на корневой `cron.php` (для cron runner).
  - `telegram_alerts.php` — wrapper на корневой `telegram_alerts.php` (для cron runner).
- `/assets/`:
  - `app.css` — базовые стили (включая user-menu).
  - `app.js` — “kick resize” для исправления адаптива (fires resize on load/pageshow/visibilitychange + ResizeObserver).
  - `user_menu.js` — логика раскрытия меню (hover + click/tap, close delay, Esc).
  - доп. css (datepicker).
- `/links/` — публичные страницы links + online menu (`menu-beta.php`).
- `/sepay/` — webhook endpoint.
- `/payday/` — инструмент сверок (одна страница + много ajax).

**0.1.1. Карта файлов (по модулям, “по‑файлово”)**
Ниже список “что за что отвечает”, чтобы можно было восстановить проект без догадок.

- Auth / общие:
  - [auth_check.php](file:///d:/Projects/Veranda%20site%202/auth_check.php) — загрузка `.env`, DB/Auth, сессия, `veranda_require()` и проверки прав.
  - [login.php](file:///d:/Projects/Veranda%20site%202/login.php), [logout.php](file:///d:/Projects/Veranda%20site%202/logout.php), [auth_callback.php](file:///d:/Projects/Veranda%20site%202/auth_callback.php) — Google OAuth flow.
  - [partials/user_menu.php](file:///d:/Projects/Veranda%20site%202/partials/user_menu.php) + [/assets/user_menu.js](file:///d:/Projects/Veranda%20site%202/assets/user_menu.js) — единая навигация и UX‑поведение меню.
- Kitchen Analytics UI:
  - [kitchen_online.php](file:///d:/Projects/Veranda%20site%202/kitchen_online.php) + [/assets/js/kitchen_online.js](file:///d:/Projects/Veranda%20site%202/assets/js/kitchen_online.js) — табло “в моменте”, авто‑обновления, игнор позиций, ссылки на Telegram.
  - [dashboard.php](file:///d:/Projects/Veranda%20site%202/dashboard.php) — агрегаты по `kitchen_stats`, графики (Chart.js), запуск resync.
  - [rawdata.php](file:///d:/Projects/Veranda%20site%202/rawdata.php) + [rawdata_receipts_chunk.php](file:///d:/Projects/Veranda%20site%202/rawdata_receipts_chunk.php) — детализация по чекам/позициям, чанки.
  - [errors.php](file:///d:/Projects/Veranda%20site%202/errors.php) — Cooked отчёт (контроль целостности send/finish).
  - [zapara.php](file:///d:/Projects/Veranda%20site%202/zapara.php) — нагрузка по часам/дням.
- Kitchen Analytics backend/cron:
  - [cron.php](file:///d:/Projects/Veranda%20site%202/cron.php) + [scripts/kitchen/cron.php](file:///d:/Projects/Veranda%20site%202/scripts/kitchen/cron.php) — синк сегодня, prob_close_at эвристика, авто‑исключения.
  - [telegram_alerts.php](file:///d:/Projects/Veranda%20site%202/telegram_alerts.php) + [scripts/kitchen/telegram_alerts.php](file:///d:/Projects/Veranda%20site%202/scripts/kitchen/telegram_alerts.php) — отправка/редактирование алертов.
  - [telegram_webhook.php](file:///d:/Projects/Veranda%20site%202/telegram_webhook.php) — обработка callback’ов (ack/ignore/vposter) и `/start`‑связки с сайтом.
  - [scripts/kitchen/resync_range.php](file:///d:/Projects/Veranda%20site%202/scripts/kitchen/resync_range.php) + [scripts/kitchen/resync_lib.php](file:///d:/Projects/Veranda%20site%202/scripts/kitchen/resync_lib.php) — пересборка диапазона (фоновая).
  - [scripts/kitchen/backfill_prob_close_at.php](file:///d:/Projects/Veranda%20site%202/scripts/kitchen/backfill_prob_close_at.php) — утилита backfill prob_close_at.
- Public:
  - [links/index.php](file:///d:/Projects/Veranda%20site%202/links/index.php) — быстрые ссылки + выбор языка.
  - [links/menu-beta.php](file:///d:/Projects/Veranda%20site%202/links/menu-beta.php) — публичное меню из локальной БД (переводы/фильтры/формат цен).
  - [sepay/index.php](file:///d:/Projects/Veranda%20site%202/sepay/index.php) — webhook SePay, запись в `sepay_transactions` + метрики в `system_meta`.
- Reservations / TR3:
  - [tr3/index.php](file:///d:/Projects/Veranda%20site%202/tr3/index.php) + [tr3/api.php](file:///d:/Projects/Veranda%20site%202/tr3/api.php) + [/tr3/assets/app.js](file:///d:/Projects/Veranda%20site%202/tr3/assets/app.js) — публичная бронь.
  - [reservations/index.php](file:///d:/Projects/Veranda%20site%202/reservations/index.php) + [reservations/view.php](file:///d:/Projects/Veranda%20site%202/reservations/view.php) + [/reservations/assets/js/reservations_hall.js](file:///d:/Projects/Veranda%20site%202/reservations/assets/js/reservations_hall.js) — админ‑панель броней и схема столов.
- Admin (MVC):
  - [admin/index.php](file:///d:/Projects/Veranda%20site%202/admin/index.php) — фронт‑контроллер вкладок.
  - [admin/controllers](file:///d:/Projects/Veranda%20site%202/admin/controllers) + [admin/views](file:///d:/Projects/Veranda%20site%202/admin/views) — вкладки Access/Menu/Telegram/Logs/Reservations/Sync.
- Payday:
  - [payday/index.php](file:///d:/Projects/Veranda%20site%202/payday/index.php) — сверки IN/OUT.
  - [payday2/index.php](file:///d:/Projects/Veranda%20site%202/payday2/index.php) + [payday2/ajax.php](file:///d:/Projects/Veranda%20site%202/payday2/ajax.php) + [payday2/post.php](file:///d:/Projects/Veranda%20site%202/payday2/post.php) — новый интерфейс “чеков” и операций.
- Библиотеки/классы:
  - [src/classes/Database.php](file:///d:/Projects/Veranda%20site%202/src/classes/Database.php) — DDL + сохранение/чтение статистики/кэшей.
  - [src/classes/PosterAPI.php](file:///d:/Projects/Veranda%20site%202/src/classes/PosterAPI.php) — клиент Poster API (v2 + v3).
  - [src/classes/KitchenAnalytics.php](file:///d:/Projects/Veranda%20site%202/src/classes/KitchenAnalytics.php) — построение “инстансов блюд” из истории Poster.
  - [src/classes/TelegramBot.php](file:///d:/Projects/Veranda%20site%202/src/classes/TelegramBot.php), [src/classes/MetaRepository.php](file:///d:/Projects/Veranda%20site%202/src/classes/MetaRepository.php) — Telegram и meta‑настройки.

**0.2. Среда выполнения**
- PHP 8.x + расширения: `pdo_mysql`, `curl`, `mbstring`, `json`, `openssl`.
- Для Payday OUT (Mail) нужен IMAP: `imap` extension + доступ к почтовому ящику.
- MySQL 8.x.
- Веб‑сервер: Apache или Nginx; для Apache используется `.htaccess`.

**0.3. .env (обязательные переменные)**
- DB: `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`, `DB_TABLE_SUFFIX` (опционально).
- Poster: `POSTER_API_TOKEN` (обязательно).
- Google: `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI`.
- Telegram: `TELEGRAM_BOT_TOKEN`, `TELEGRAM_CHAT_ID`, `TELEGRAM_THREAD_ID` (если включены алерты).
- TableReservation TZ: `POSTER_API_TIMEZONE`, `POSTER_SPOT_TIMEZONE`.
- App: `APP_URL` (для генерации абсолютных ссылок, если нужно).

**0.4. Развёртывание БД (DDL)**
- Часть таблиц создаётся автоматически кодом:
  - `Database::createTables()` — `kitchen_stats`
  - `Database::createMenuTables()` — меню витрины
  - `Database::createPaydayTables()` — кэши SePay/Poster для Payday IN
  - `payday/index.php` создаёт таблицы: `sepay_hidden`, `poster_finance_hidden`, `mail_hidden`, `out_links`
- Часть таблиц должна существовать заранее (создаётся вручную 1 раз):
  - `users` (для авторизации/доступов)
  - `system_meta` (key-value для настроек/кэшей)
  - (опционально) логовые таблицы/файлы (в проекте используются файлы *.log)

**0.5. Восстановление доступа**
- Авторизация разрешает вход только тем email, которые есть в `users` и `is_active=1`.
- Если таблица `users` пустая/поломана, `admin.php?tab=access` умеет кнопкой “Добавить себя” вставить текущего пользователя (если страница доступна).

### 1. Технологии
- Backend: PHP 8.x.
- Frontend: HTML/CSS/JS (без фреймворков).
- DB: MySQL 8.x.
- Интеграции: Poster API, Telegram Bot API, Google OAuth, SePay webhook (JSON).
- Деплой: GitHub Actions + rsync по SSH; сервер Linux (Apache/Nginx + PHP).

### 2. Конфигурация/секреты (.env, не коммитится)
- DB_HOST/DB_NAME/DB_USER/DB_PASS, DB_TABLE_SUFFIX (опционально).
- POSTER_API_TOKEN.
- POSTER_API_TIMEZONE (часовой пояс, который ожидает Poster API для date_reservation; по умолчанию Europe/Kyiv).
- POSTER_SPOT_TIMEZONE (часовой пояс заведения для отображения; по умолчанию Asia/Ho_Chi_Minh).
- GOOGLE_CLIENT_ID/GOOGLE_CLIENT_SECRET/GOOGLE_REDIRECT_URI.
- TELEGRAM_BOT_TOKEN/TELEGRAM_CHAT_ID/TELEGRAM_THREAD_ID/TELEGRAM_CHAT_USERNAME (опционально).
- APP_URL.

### 3. Авторизация и права
- Google OAuth; в сессии: user_email, user_name, user_avatar.
- Таблица `users`: email, permissions_json.
- Ключи прав (актуальные):
  - dashboard, rawdata, kitchen_online
  - errors (Cooked), zapara
  - admin, payday
  - banya, roma, employees
  - exclude_toggle, telegram_ack
- Проверка прав на страницах: `veranda_require('<perm>')`.
- Меню пользователя: `partials/user_menu.php` (только аватарка; тултип с именем; плавное раскрытие; открытие по клику/тапу и hover).

### 4. Модель данных (основные таблицы/ключи)
**4.1. kitchen_stats (ядро аналитики)**
- Гранулярность хранения: **строка = одна “позиция блюда” в чеке** (product instance), вычисленная из истории Kitchen Kit (`sendtokitchen/finishedcooking/delete…`).
- Идентификаторы:
  - transaction_date (DATE, по дате открытия чека)
  - transaction_id (Poster transaction_id)
  - receipt_number (номер чека / receipt_number)
  - item_seq (порядковый номер “инстанса” внутри блюда, нужен чтобы различать 2 одинаковых блюда в одном чеке)
  - dish_id (Poster product_id)
- Атрибуты чека:
  - table_number (table_name/table_id)
  - waiter_name (имя официанта)
  - status, pay_type, close_reason
  - service_type (service_mode)
  - total_sum (payed_sum/100)
  - transaction_comment (transaction_comment)
- Атрибуты блюда/цеха:
  - dish_name (по справочнику продуктов Poster)
  - dish_category_id/dish_sub_category_id (по справочнику категорий Poster)
  - station (название цеха/станции: workshop_name)
- Времена (все в TZ Asia/Ho_Chi_Minh):
  - transaction_opened_at (date_start)
  - transaction_closed_at (date_close / date_close_date / вычисление)
  - ticket_sent_at (время отправки на кухню, из истории)
  - ready_pressed_at (время “готово”, из истории)
  - ready_chass_at (если используется отдельная отметка готовности/вручную — хранится, но в текущем UX может не отображаться)
  - prob_close_at (вероятное время закрытия позиции, эвристика)
- Исключения:
  - was_deleted (позиция удалена, вычисляется по delete/deleteitem/changeitemcount)
  - exclude_from_dashboard, exclude_auto (ручные/авто исключения)
- Telegram поля:
  - tg_message_id, tg_sent_at, tg_last_edit_at
  - tg_acknowledged, tg_acknowledged_at, tg_acknowledged_by

**4.1.4. Telegram‑состояния (kitchen_stats + служебные таблицы)**
В проекте одновременно используются:
- Поля в `kitchen_stats` для “быстрой индикации” и связи строки с Telegram: `tg_message_id`, `tg_sent_at`, `tg_last_edit_at`, `tg_acknowledged*`.
- Служебные таблицы для дедупликации/перерисовки сообщений:
  - `tg_alert_threads` — 1 запись на чек (transaction_date + transaction_id): message_id треда/сообщения, хэш последнего текста, last_seen/last_edited.
  - `tg_alert_items` — 1 запись на позицию (transaction_date + kitchen_stats_id): message_id, хэш текста, last_seen.
Назначение: не спамить Telegram повторными сообщениями и уметь “редактировать существующее” вместо отправки нового.

DDL (создаётся автоматически в [telegram_alerts.php](file:///d:/Projects/Veranda%20site%202/telegram_alerts.php)):
```sql
CREATE TABLE IF NOT EXISTS tg_alert_threads (
  transaction_date DATE NOT NULL,
  transaction_id BIGINT NOT NULL,
  message_id BIGINT NULL,
  receipt_number VARCHAR(64) NULL,
  table_number VARCHAR(64) NULL,
  waiter_name VARCHAR(255) NULL,
  last_text_hash CHAR(40) NULL,
  last_edited_at DATETIME NULL,
  last_seen_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (transaction_date, transaction_id),
  KEY idx_tg_threads_msg (message_id)
);

CREATE TABLE IF NOT EXISTS tg_alert_items (
  transaction_date DATE NOT NULL,
  kitchen_stats_id BIGINT NOT NULL,
  transaction_id BIGINT NOT NULL,
  message_id BIGINT NULL,
  last_text_hash CHAR(40) NULL,
  last_seen_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (transaction_date, kitchen_stats_id),
  KEY idx_tg_items_tx (transaction_date, transaction_id),
  KEY idx_tg_items_msg (message_id)
);
```

**4.1.1. Маппинг из Poster (как строится kitchen_stats)**
- Источник: `dash.getTransactions` (include_products=true, include_history=true, status=0, limit+offset).
- Для каждого transaction:
  - Берём `products[]` и строим `productQtyById` (максимум count/quantity по каждому product_id).
  - Берём `history[]` и извлекаем “инстансы” продукта:
    - `sendtokitchen` даёт список items в `value_text` (array/JSON). Для каждого item:
      - product_id, count → генерируем count штук “sent time” для этого продукта.
    - `finishedcooking` даёт product_id в `value` → добавляет 1 “ready time” для этого продукта.
    - `deleteitem`/`delete`/`changeitemcount` уменьшают/помечают последние неготовые инстансы как deleted.
  - Кол-во инстансов `qty` для продукта берётся как max(
    - количество в products,
    - суммарно отправлено в sendtokitchen,
    - maxCount из changeitemcount
    ).
  - Для i=1..qty создаётся строка:
    - ticket_sent_at = i‑й send time или fallback (первый send), если не хватает send событий
    - ready_pressed_at = i‑й finish time (если есть)
    - was_deleted выставляется начиная с конца для неготовых инстансов (если были delete события)

**4.1.2. Справочники (как мапим id → названия/категории/станции)**
- Продукты/категории/цеха загружаются в `KitchenAnalytics::loadProductData()`:
  - названия продуктов → `productNames[product_id]`
  - категории → `productMainCategories[product_id]`, `productSubCategories[product_id]`
  - привязка продукта к workshop → `productWorkshops[product_id]`
  - workshop_id → workshop_name → `workshopNames[workshop_id]`

**4.1.3. Как мапим официанта (waiter_name)**
- Источник: `transaction.name` (не всегда офиц. id), поэтому используется история:
  - `dash.getTransactionHistory(transaction_id)` → `history_user_id`
  - `access.getEmployees` → map user_id → name
- На cron/табло также используется прямой `dash.getTransaction(transaction_id)` для уточнения close/pay_type/reason.

**4.2. system_meta**
- Кеши Poster: poster_last_sync_at, poster_workshops_json, poster_products_cat_map_json и др.
- Ключи для бронирований (TR3/Reservations):
  - reservations_allowed_scheme_nums_hall_<hallId>
  - reservations_table_caps_hall_<hallId>
- Ключи предзаказа:
  - preorder_min_per_guest_vnd (минимальная сумма предзаказа на 1 гостя для TR3)
- Сервисные ключи: pid/статусы resync, статистика webhook SePay, настройки меню/Telegram.

**4.2.1. DDL: system_meta (обязательная таблица)**
Проект ожидает `UNIQUE`/`PRIMARY KEY` по `meta_key` и возможность upsert через `ON DUPLICATE KEY`.

```sql
CREATE TABLE IF NOT EXISTS system_meta (
  meta_key VARCHAR(191) NOT NULL,
  meta_value LONGTEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (meta_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**4.2.2. DDL: users (обязательная таблица для авторизации и прав)**
Использование:
- `Auth::handleCallback()` проверяет `email` + `is_active=1`.
- `admin.php?tab=access` читает/пишет `permissions_json`, поддерживает `telegram_username` (если колонка добавлена).
- `auth_check.php` читает `permissions_json` и формирует `$_SESSION['user_permissions']`.

```sql
CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email VARCHAR(255) NOT NULL,
  name VARCHAR(255) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  permissions_json LONGTEXT NULL,
  telegram_username VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**4.3. Онлайн‑меню (кеш/витрина)**
- poster_menu_items, menu_workshops/menu_workshop_tr, menu_categories/menu_category_tr, menu_items/menu_item_tr.
- Сортировки и флаги show_on_site/is_published.

**4.4. Payday / сверки**
- sepay_transactions (входящие транзакции), sepay_hidden (скрытые + комментарий).
- mail_hidden (скрытые письма банка + комментарий).
- out_links (связки mail_uid ↔ finance_id).
- poster_checks, poster_transactions, poster_accounts, poster_payment_methods, check_payment_links.
- poster_finance_hidden (скрытие строк finance при необходимости).

**4.4.1. DDL: таблицы Payday (создаются автоматически)**
Часть таблиц создаёт `Database::createPaydayTables()`:
- `sepay_transactions`
- `poster_checks`
- `poster_payment_methods`
- `poster_transactions`
- `poster_accounts`
- `check_payment_links`

Таблицы `sepay_hidden`, `poster_finance_hidden`, `mail_hidden`, `out_links` создаёт `payday/index.php` при первом открытии.

Ключевые ограничения/индексы:
- `sepay_transactions.uq_sepay_id(sepay_id)`
- `poster_checks.uq_poster_tx(transaction_id)`
- `check_payment_links.uq_link_pair(poster_transaction_id,sepay_id)` + FK на `poster_checks` и `sepay_transactions`
- `mail_hidden.uniq_mail_date(mail_uid,date_to)`
- `out_links.uniq_pair_date(date_to,mail_uid,finance_id)`

### 5. Правила расчётов и таймзоны
**5.1. Кухонная аналитика**
- Ожидание позиции: старт = ticket_sent_at, конец = ready_pressed_at; если конца нет — “готовится” до текущего времени.
- Динамический лимит ожидания: alert_timing_low_load/alert_timing_high_load с порогом alert_load_threshold.
- Исключение кальянов (категория id=47) и исключённых позиций.

**5.2. Telegram**
- Алерты по “долгим” позициям + статусное сообщение (всегда должно существовать и обновляться).
- ✅ Принято: подавление повторов по whitelist и/или правам.

**5.3. TableReservation и часовые пояса**
- В проекте разделяются:
  - “API TZ” (Kiev) — формат даты для Poster API.
  - “Display TZ” (Vietnam) — что видит пользователь в UI.
- Для бронирований/занятости времена конвертируются Display ↔ API.

**5.4. Zapara**
- Источник: `dash.getTransactions` (status=2, timezone=client).
- “Чек” = уникальный `transaction_id` (с дедупликацией на случай пагинации).
- “Блюда” = сумма qty/count по массиву `products` в транзакции.
- В интерфейсе:
  - Переключатель метрики: **Чеки ↔ Блюда**.
  - Переключатель отрисовки: **Колонки ↔ Линия**.
  - Значения на графиках показываются как **среднее в час на 1 день**:
    - для каждого DOW: sum(counts for that dow,hour) / number_of_days_with_that_dow
    - “Среднее”: sum(counts for all dows,hour) / total_days_in_range

**5.4.1. Zapara: backend‑агрегация (ajax=day)**
- URL: `/zapara.php?ajax=day&date=YYYY-MM-DD`
- Внутри:
  - Запрос в Poster:
    - method: `dash.getTransactions`
    - params:
      - dateFrom/dateTo = YYYYMMDD (один день)
      - status=2
      - timezone=client
      - include_products=true (чтобы считать блюда)
      - include_history=false, include_delivery=false
      - next_tr для пагинации
  - Для каждой транзакции (уникальный transaction_id):
    - час открытия берётся по `date_start_new` (fallback `date_start`), приводится к секундам (ms → /1000), TZ Asia/Ho_Chi_Minh
    - учитываются только часы 09–23 включительно
    - counts_by_hour_checks[hour] += 1
    - counts_by_hour_dishes[hour] += sum(products[].count/quantity)
- Ответ (JSON):
  - hours: [9..23]
  - counts_by_hour_checks: { "9": int, … "23": int }
  - counts_by_hour_dishes: { "9": int, … "23": int }
  - total_checks, total_dishes (за 09–23)
  - dow (1..7)

### 6. Страницы и эндпоинты (актуально)
Ниже — техническое описание “что вызывает что”, чтобы можно было оценивать доработки.

**6.1. /kitchen_online.php (табло, auth + kitchen_online)**
- Основной режим: HTML+JS, автообновление через AJAX.
- Query:
  - `?ajax=1&action=list` — принудительный sync за сегодня и выдача HTML карточек + мета.
  - `?ajax=1&action=refresh` — sync не чаще чем раз в 10 сек по meta `poster_last_sync_at`, затем выдача.
  - `?ajax=1&action=exclude` (POST form):
    - body: toggle_exclude_item=<kitchen_stats.id>
    - действие: `UPDATE kitchen_stats SET exclude_from_dashboard=1, exclude_auto=0 WHERE id=?`
    - доступ: require `exclude_toggle`
  - `?ajax=1&action=set_logclose` (POST JSON):
    - body: { use: 0|1 }
    - meta: ko_use_logical_close
  - `?ajax=1&action=refresh` (POST form) — “точечная проверка” транзакций:
    - body: tx_ids[]=… (max 40)
    - Poster: `dash.getTransaction(transaction_id)`, `dash.getTransactionHistory(transaction_id)` и employee map `access.getEmployees`
    - обновляет строки kitchen_stats по конкретным tx (нужен для спорных кейсов)

**6.2. scripts/kitchen/cron.php (5 минут)**
- Реализация: `cron.php` в корне.
- Poster API:
  - `dash.getTransactions(dateFrom,dateTo,include_products=true,include_history=true,status=0,limit,offset)`
  - при необходимости: `dash.getTransaction(transaction_id)` (детали закрытия/платежа)
- DB:
  - `Database::saveStats($stats)` вставляет/апдейтит kitchen_stats
  - meta: `poster_last_sync_at`
- Пост‑обработка:
  - refresh close metadata для status>1 и пустого transaction_closed_at (до 200 tx): `dash.getTransaction`
  - вычисление `prob_close_at` по эвристике “следующий чек на этой станции” (receipt_number +1..+3)
  - автоисключения (hookah category id=47, и позиции без ready после prob_close/после закрытия чека)

**6.3. /dashboard.php (auth + dashboard)**
- Источник данных: `kitchen_stats` (SQL агрегаты по датам/часам/станциям).
- Входные параметры:
  - dateFrom, dateTo (Y-m-d)
  - hourStart, hourEnd (0..23)
  - station (all|kitchen|bar) — через список workshop’ов
- Действие resync:
  - `?resync=1` запускает `scripts/kitchen/resync_range.php dateFrom dateTo jobId` в фоне
  - meta: kitchen_resync_job_pid, kitchen_resync_job_status

**6.3.1. SQL агрегат дашборда (среднее/максимум/нагрузка)**
Формула ожидания (в минутах) для строки `kitchen_stats`:
- start = `ticket_sent_at`
- end:
  - если `ready_pressed_at` — он
  - иначе, если `prob_close_at` и чек закрыт (`status>1` и `transaction_closed_at` валиден) — min(prob_close_at, transaction_closed_at)
  - иначе, если `prob_close_at` — он
  - иначе, если чек закрыт — `transaction_closed_at`

Схема запроса (упрощённо, как в коде):
```sql
SELECT sid, d_iso, h_int,
       ROUND(AVG(wait_min), 1) AS avg_wait,
       ROUND(MAX(wait_min), 1) AS max_wait,
       COUNT(*) AS cnt
FROM (
  SELECT
    CASE
      WHEN station IN ('2', 2, 'Kitchen', 'Main') THEN '2'
      WHEN station IN ('3', 3, 'Bar Veranda') THEN '3'
      ELSE NULL
    END AS sid,
    DATE(transaction_opened_at) AS d_iso,
    HOUR(transaction_opened_at) AS h_int,
    (TIMESTAMPDIFF(SECOND, ticket_sent_at,
      CASE
        WHEN ready_pressed_at IS NOT NULL THEN ready_pressed_at
        WHEN prob_close_at IS NOT NULL AND status > 1 AND transaction_closed_at IS NOT NULL
          THEN CASE WHEN prob_close_at < transaction_closed_at THEN prob_close_at ELSE transaction_closed_at END
        WHEN prob_close_at IS NOT NULL THEN prob_close_at
        WHEN status > 1 AND transaction_closed_at IS NOT NULL THEN transaction_closed_at
        ELSE NULL
      END
    ) / 60) AS wait_min
  FROM kitchen_stats
  WHERE transaction_date BETWEEN :dateFrom AND :dateTo
    AND COALESCE(exclude_from_dashboard,0)=0
    AND COALESCE(was_deleted,0)=0
    AND ticket_sent_at IS NOT NULL
    AND transaction_opened_at IS NOT NULL
    AND HOUR(transaction_opened_at) BETWEEN :hourStart AND :hourEnd
    AND NOT (COALESCE(dish_category_id,0)=47 OR COALESCE(dish_sub_category_id,0)=47)
    AND (
      ready_pressed_at IS NOT NULL
      OR prob_close_at IS NOT NULL
      OR (status>1 AND transaction_closed_at IS NOT NULL)
    )
) x
WHERE sid IS NOT NULL AND wait_min IS NOT NULL AND wait_min >= 0
GROUP BY sid, d_iso, h_int;
```

**6.4. /rawdata.php (auth + rawdata)**
- Источник: `kitchen_stats` (SQL с фильтрами, сортировка).
- Действия:
  - toggle exclude (только при праве exclude_toggle): обновляет exclude_from_dashboard/exclude_auto.
  - resync диапазона — аналогично dashboard.
- Доп. endpoint:
  - `/rawdata_receipts_chunk.php` — чанки/вспомогательная подгрузка (используется UI таблицы).

**6.4.1. Контракт ajax‑подгрузки чеков**
- Запрос: `GET /rawdata.php?ajax=1&dateFrom=...&dateTo=...&status=...&station=...&hourStart=...&hourEnd=...&offset=0&limit=20`
- 1) SQL для получения списка чеков (страницы):
  - группировка по `transaction_id`, сортировка по `MAX(ticket_sent_at)` (последнее отправленное блюдо в чеке).
```sql
SELECT transaction_id, receipt_number, MAX(ticket_sent_at) AS last_sent_at
FROM kitchen_stats
WHERE ...filters...
GROUP BY transaction_id, receipt_number
ORDER BY last_sent_at DESC
LIMIT :limit OFFSET :offset;
```
- 2) SQL для получения всех строк `kitchen_stats` по этим transaction_id:
```sql
SELECT * FROM kitchen_stats
WHERE transaction_id IN (:txIds...)
ORDER BY ticket_sent_at DESC;
```
- На фронте строки группируются обратно “по чеку” и рисуются как карточки/таблица.

**6.5. /errors.php (Cooked, auth + errors)**
Цель: найти закрытые чеки, у которых “отправили на кухню” есть, а “finishedcooking” не покрывает всё (с учётом удаления позиций), без кальянов.
- `/errors.php?ajax=day&date=YYYY-MM-DD`
  - Poster: `dash.getTransactions(dateFrom=dateTo=day,status=2,include_products=false,include_history=false,timezone=client,next_tr)`
  - Для всех transaction_id пачками тянется история:
    - `dash.getTransactionHistory(transaction_id)` через curl_multi (batch size 10)
  - Из history считаются:
    - sent: type_history=sendtokitchen, value_text (array/JSON), поля product_id/count
    - finished: type_history=finishedcooking, value=product_id
    - deleted: type_history=delete/deleteitem/changeitemcount (value/value2)
  - missing=true если по какому‑то product_id: finished_count < (sent_count - deleted_count)
  - Ответ: total, missing, hours[0..23]{total,missing} (по часу date_close_date)
- `/errors.php?ajax=day_checks&date=YYYY-MM-DD`
  - Возвращает список чеков с missing=true/false и базовыми полями (transaction_id, receipt_number, close_time, waiter, sum_minor…).
- `/errors.php?ajax=tx_history&transaction_id=…`
  - Прямая выдача истории `dash.getTransactionHistory` для UI‑разбора.

**6.6. /zapara.php (auth + zapara)**
- UI грузит выбранный период параллельно “по дням”:
  - Для каждой даты: `/zapara.php?ajax=day&date=YYYY-MM-DD`
  - concurrency=8; прогресс: X/Y
- Дальше на клиенте агрегирует:
  - counts_checks_by_dow / counts_dishes_by_dow
  - days_by_dow и days_total
  - рисует 7 графиков + “Среднее”

**6.7. /TableReservation (публичная схема, без auth)**
Таймзоны:
- Display TZ: POSTER_SPOT_TIMEZONE (обычно Vietnam)
- API TZ: POSTER_API_TIMEZONE (обычно Kyiv)

Статус: legacy. В текущей версии “публичная бронь” реализована в `/tr3`, а админ‑панель бронирований — в `/reservations`. Раздел ниже оставлен как референс для исторического JS (`/links/table-reservation.js`) и логики API Poster по доступности столов.

Endpoints:
- `?ajax=free_tables` (GET)
  - params: date_reservation, duration, guests_count, spot_id
  - Poster: `incomingOrders.getTablesForReservation(date_reservation=<API TZ>,duration,spot_id,guests_count)`
  - Ответ: free_table_nums[] (номера на схеме), free_tables[] (raw rows Poster), request.* (и display и api)
- `?ajax=reservations` (GET)
  - params: date_reservation, duration, spot_id
  - Poster:
    - `incomingOrders.getReservations(date_from=<API day start>, date_to=<API day end>)`
    - `spots.getTableHallTables(spot_id,hall_id=2,without_deleted=1)` для map table_id → scheme_num (1..20)
    - если у брони нет table_id: `incomingOrders.getReservation(incoming_order_id)`
  - Ответ: reservations_items[]:
    - table_title = номер на схеме (строка), date_start/date_end в Display TZ
- `?ajax=busy_ranges` (GET)
  - params: date=YYYY-MM-DD, spot_id
  - Логика: по сетке слотов (шаг 15 мин) дергает Poster availability и формирует интервалы занятости по каждому столику.
  - Ответ используется UI‑схемой для подсветки и текста внутри столика.

**6.8. /admin.php (auth + admin)**
- Access:
  - users.permissions_json хранит выдачу прав (checkboxes по ключам).
  - UI: список пользователей, модалка прав.
- Reservations:
  - meta:
    - reservations_allowed_scheme_nums_hall_<hallId> (список номеров столов на схеме, которые участвуют)
    - reservations_table_caps_hall_<hallId> (map номер→вместимость)
- Menu:
  - читает meta: menu_last_sync_at/menu_last_sync_result/menu_last_sync_error
  - экспорт CSV из таблиц меню
- Telegram:
  - управляет alert_* настройками в system_meta и whitelist для ✅ Принято.

**6.9. /payday (auth + payday)**
Внутри одна страница с множеством AJAX‑эндпойнтов.

IN (SePay ↔ Poster):
- `?ajax=links` — получить связки sepay_id ↔ transaction_id (таблица check_payment_links).
- `?ajax=manual_link` (POST JSON) — записать ручную связку.
- `?ajax=auto_link` (POST JSON) — записать автосвязку.
- `?ajax=unlink` (POST JSON) — разорвать связь.
- `?ajax=clear_links` — очистить автосвязи/кэш за выбранный день (по логике страницы).
- `?ajax=sepay_hide` (POST JSON: {sepay_id, comment}) — записать в sepay_hidden.

OUT (Mail ↔ Poster Finance):
- `?ajax=mail_out&dateFrom=YYYY-MM-DD&dateTo=YYYY-MM-DD&include_hidden=1`
  - источник: IMAP‑почта (парсинг subject/amount/time) + таблица mail_hidden
  - ответ rows[]: mail_uid, date, tx_time, amount, content, is_hidden, hidden_comment
- `?ajax=mail_hide` (POST JSON: {mail_uid,dateTo,comment}) — записать в mail_hidden.
- `?ajax=finance_out&dateFrom=…&dateTo=…` — загрузить/кешировать операции Poster Finance (таблица out_finance / poster_transactions).
- `?ajax=out_links&dateTo=YYYY-MM-DD` — получить связки out_links (mail_uid ↔ finance_id).
- `?ajax=out_manual_link` / `?ajax=out_auto_link` / `?ajax=out_unlink` — управление связями.
- `?ajax=out_clear_links` — очистка связей за дату.

Справочники/балансы:
- `?ajax=poster_employees` — список сотрудников Poster (для подписей).
- `?ajax=finance_categories` — категории расходов Poster.
- `?ajax=poster_accounts` — счета Poster (в т.ч. Tips account 8).
- `?ajax=create_transfer` — создание transfer в Poster Finance.
- `?ajax=delete_finance_transfer` — удаление transfer.
- `?ajax=balance_sinc_plan` / `?ajax=balance_sinc_commit` — операции синхронизации балансов (логика страницы).

**6.9.1. UI‑состояния и локальное хранение (frontend)**
- Показывать скрытые SePay IN: `localStorage.payday_show_sepay_hidden = "1"`.
- Показывать скрытые Mail OUT: `localStorage.payday_show_out_mail_hidden = "1"`.
- Скрывать “Vietnam Company”: `localStorage.payday_hide_vietnam = "1"`.
- Лоадеры/перерисовка линий: `drawLines()/positionLines()/positionWidgets()` вызываются после каждой операции (линк/анлинк/скрытие/показ скрытых).

**6.9.2. Таблицы и поля (что куда пишется)**
Основные таблицы:
- `sepay_transactions` — сырые события SePay (in/out) + `payment_method`, `raw_request_body`, `was_deleted/deleted_at` (soft delete).
- `sepay_hidden(sepay_id, comment, created_by, created_at, updated_at)` — скрытия IN.
- `poster_checks` — кэш чеков Poster (по `dash.getTransactions`) для диапазона дат.
- `poster_payment_methods` — кэш методов оплаты Poster.
- `poster_transactions` — кэш транзакций Poster (минимальные поля для связок/подсветок).
- `poster_accounts` — кэш счетов (балансы).
- `check_payment_links(poster_transaction_id,sepay_id,link_type)` — связи IN.
- `mail_hidden(mail_uid,date_to,comment,created_by,created_at)` — скрытия OUT (Mail).
- `out_links(date_to,mail_uid,finance_id,link_type,is_manual,created_by,created_at)` — связи OUT.
- `poster_finance_hidden(date_to,kind,transfer_id,tx_id,comment,created_by)` — скрытие/маркировка операций finance для блока “переводы”.

**6.9.3. IN: контракты endpoints (SePay ↔ Poster)**
Ниже форматы “как в коде” (JSON всегда UTF‑8).

- `GET ?ajax=links`
  - Response: `{ ok: true, links: [{ poster_transaction_id, sepay_id, link_type, created_at, updated_at }...] }`.
  - SQL: `SELECT ... FROM check_payment_links WHERE ...`.

- `POST ?ajax=manual_link`
  - Body JSON:
    - либо `{ poster_transaction_id:int, sepay_id:int }`
    - либо `{ poster_transaction_ids:int[], sepay_ids:int[] }` (разрешено: “1 к многим” или “многие к 1”, но не many-to-many).
  - Validation: запрещает “много‑ко‑много” (проверка, что выбранные ids не связаны с другими).
  - SQL:
    - `INSERT INTO check_payment_links(poster_transaction_id,sepay_id,link_type='manual') ON DUPLICATE KEY UPDATE link_type='manual'`.
  - Response: `{ ok:true, created:true, pairs:int }`.

- `POST ?ajax=auto_link`
  - Назначение: попытка автоматической связки за выбранный диапазон дат.
  - Вход: без body, диапазон берётся из `dateFrom/dateTo` страницы.
  - Источник данных:
    - Poster checks: `SELECT transaction_id,date_close,payed_card,payed_third_party,tip_sum,poster_payment_method_id FROM poster_checks WHERE day_date BETWEEN ...`
    - SePay IN: `SELECT sepay_id,transaction_date,transfer_amount FROM sepay_transactions WHERE ... transfer_type='in' AND payment_method IS NULL OR IN('Card','Bybit') AND NOT EXISTS(sepay_hidden)`
  - Алгоритм:
    - сопоставление по сумме (VND) и близости времени (окно), выделение кандидатов:
      - `auto_green` если кандидат уникален
      - `auto_yellow` если кандидатов > 1
    - запись в `check_payment_links` с `link_type`.
  - Response: `{ ok:true, created:int, updated:int, skipped:int }` (точные поля зависят от реализации в файле).

- `POST ?ajax=unlink`
  - Body JSON: `{ poster_transaction_id:int, sepay_id:int }`.
  - SQL: `DELETE FROM check_payment_links WHERE poster_transaction_id=? AND sepay_id=?`.
  - Response: `{ ok:true }`.

- `POST ?ajax=clear_links`
  - Назначение: очистка связок по дате чека.
  - SQL: `DELETE l FROM check_payment_links l JOIN poster_checks p ON p.transaction_id=l.poster_transaction_id WHERE p.day_date BETWEEN dateFrom AND dateTo`.
  - Response: `{ ok:true }`.

- `POST ?ajax=sepay_hide`
  - Body JSON: `{ sepay_id:int, comment:string }`.
  - Side effects:
    - удаляет связи `check_payment_links` для этого sepay_id (чтобы скрытый платёж не был связан).
  - SQL:
    - `DELETE FROM check_payment_links WHERE sepay_id=?`
    - upsert в `sepay_hidden(sepay_id,comment,created_by)`.
  - Response: `{ ok:true }`.

**6.9.4. OUT: контракты endpoints (Mail ↔ Poster Finance)**
- `GET ?ajax=mail_out&dateFrom=YYYY-MM-DD&dateTo=YYYY-MM-DD&include_hidden=1`
  - Источник: IMAP mailbox (парсинг subject/date/amount) + таблица `mail_hidden` по `date_to`.
  - Response: `{ ok:true, rows:[{ mail_uid, date, tx_time, amount, content, is_hidden, hidden_comment }...] }`.

- `POST ?ajax=mail_hide`
  - Body JSON: `{ mail_uid:int, dateTo:YYYY-MM-DD, comment:string }`.
  - SQL: upsert `mail_hidden(mail_uid,date_to,comment,created_by)`.
  - UI: строка помечается скрытой и прячется, если глазик “показывать скрытые” выключен.

- `GET ?ajax=finance_out&dateFrom=YYYY-MM-DD&dateTo=YYYY-MM-DD`
  - Источник: Poster `finance.getTransactions` (по датам) + нормализация сумм/типов.
  - Пишет/обновляет локальные кэши `out_finance` (исторический) и/или использует `poster_transactions`.
  - Response: `{ ok:true, rows:[...] }` (поля описываются по фактическим колонкам кэша).

- `GET ?ajax=out_links&dateTo=YYYY-MM-DD`
  - SQL: `SELECT * FROM out_links WHERE date_to=?`.
  - Response: `{ ok:true, links:[{ mail_uid, finance_id, link_type, is_manual }...] }`.

- `POST ?ajax=out_manual_link`
  - Body JSON: `{ dateTo, mail_uid, finance_id }`.
  - SQL: upsert `out_links(..., link_type='manual', is_manual=1)`.

- `POST ?ajax=out_auto_link`
  - Body JSON: `{ dateTo }` (+ параметры по суммам/окну в зависимости от UI).
  - Алгоритм: автосвязь по сумме и близости времени (похожие правила как IN), `auto_green/auto_yellow`.

- `POST ?ajax=out_unlink`
  - Body JSON: `{ dateTo, mail_uid, finance_id }`.
  - SQL: delete pair.

- `POST ?ajax=out_clear_links`
  - Body JSON: `{ dateTo }`.
  - SQL: `DELETE FROM out_links WHERE date_to=?`.

**6.9.5. Общие справочники/переводы/балансы (Payday)**
- `GET ?ajax=poster_employees`
  - Poster: `access.getEmployees`
  - Response: `{ ok:true, employees:[{ user_id,name }...] }`.
- `GET ?ajax=finance_categories`
  - Poster: `finance.getCategories`
  - Response: `{ ok:true, categories:[...] }`.
- `GET ?ajax=poster_accounts`
  - Poster: `finance.getAccounts`
  - Response: `{ ok:true, accounts:[{ account_id,name,balance,... }...] }`.

**6.9.6. Переводы/синхронизация балансов (Payday)**
- `POST ?ajax=create_transfer`
  - Body JSON: `{ kind:'vietnam'|'tips', dateFrom, dateTo }`.
  - Вычисление суммы:
    - kind=vietnam: SUM(payed_card + payed_third_party + tip_sum) по `poster_checks` с `poster_payment_method_id=11`.
    - kind=tips: SUM(tip_sum) только по “связанным чекам” (join `check_payment_links`), исключая pm_id=11.
  - Poster:
    - сначала `finance.getTransactions` за день `dateTo` → проверка “перевод уже создан”
    - затем `finance.createTransactions` (type=2, user_id=4, account_from=1, account_to=9|8, date=<dateTo 23:55>, comment=…)
  - Response:
    - `{ ok:true, already:true, date, time, sum, user, comment }` или `{ ok:true, already:false, ... }`.

- `POST ?ajax=delete_finance_transfer`
  - Body JSON: `{ kind, transfer_id?, tx_id?, dateTo, comment? }`.
  - Poster: `finance.getTransactions` за день → проверка, что это “наш” перевод (accId+comment+kind) → `finance.deleteTransaction`/`finance.deleteTransactions` (в зависимости от реализации).
  - Пишет отметку скрытия в `poster_finance_hidden` (чтобы UI не показывал уже удалённые/неактуальные).
  - Response: `{ ok:true, remaining:int }` (сколько осталось не скрытых).

- `POST ?ajax=balance_sinc_plan` / `POST ?ajax=balance_sinc_commit`
  - Назначение: пошаговый мастер синхронизации балансов (сначала план, потом коммит).
  - План рассчитывает “что нужно сделать” (какие суммы перевести/создать операции), коммит создаёт операции в Poster и/или помечает скрытые.
  - Оба endpoint возвращают JSON со списком действий/результатов (см. реализацию payday/index.php).

**6.10. /employees.php (auth + employees)**
- Загрузка данных (страница) — внутренние таблицы проекта.
- `?ajax=tips_balance`:
  - Poster: `finance.getAccounts()`
  - Берётся account_id=8 (“Tips (на счету BIDV)”) и отдаётся balance_minor.

### 7. Cron
- Kitchen sync: scripts/kitchen/cron.php каждые 5 минут.
- Telegram alerts: scripts/kitchen/telegram_alerts.php каждую минуту.
- Menu sync: scripts/menu/cron.php каждый час.

### 8. Деплой
- Автодеплой запускается на `push` в ветку `main`.
- Workflow: `.github/workflows/deploy.yml`.
- Concurrency: `deploy-veranda-my` (последний деплой отменяет предыдущий).

**8.1. Шаги деплоя (как в workflow)**
- Checkout репозитория (shallow fetch-depth=1).
- Установка `rsync` (если нет на runner).
- Настройка SSH:
  - private key из `secrets.SSH_PRIVATE_KEY` → `~/.ssh/id_ed25519`
  - `ssh-keyscan` добавляет host key в `known_hosts` (StrictHostKeyChecking=yes).
- Копирование файлов:
  - `rsync -az` проекта на сервер: `${DEPLOY_PATH}/`
  - исключения: `.env`, `.env.*`, `*.log`, `telegram.log`, `prob_close_backfill.log`, `.git/`, `.github/`.
- Пост‑обработка на сервере:
  - удаление debug файлов `mail.php`, `access.php` (если внезапно попали в репо).
  - `php -l` (syntax check) для ключевых файлов: `index.php`, `login.php`, `logout.php`, `auth_callback.php`, `dashboard.php`, `rawdata.php`, `rawdata_receipts_chunk.php`, `kitchen_online.php`, `admin.php`, `logs.php`, `cron.php`, `telegram_alerts.php`, `march.php`, `roma.php`, `banya.php`, `employees.php`.

**8.2. Secrets (GitHub Actions)**
- `SSH_PRIVATE_KEY` (ed25519)
- `SSH_HOST`, `SSH_PORT`, `SSH_USER`
- `DEPLOY_PATH`

### 9. UX/производительность/безопасность
- Все экраны с авторизацией используют общую шапку/меню, адаптив без необходимости ручного ресайза.
- Избегаем тяжёлых фронтенд‑зависимостей; обновления — JSON/частичные.
- Не логировать токены/секреты; .env не коммитится.

---

Документ покрывает публичные страницы (links/онлайн‑меню/webhook SePay/схема столов) и все страницы с авторизацией (включая вложенные вкладки admin/payday) и описывает бизнес‑цели и техспеку текущей версии проекта.

---

## Приложение. Оценка времязатрат по Git
Методика:
- Считаем разницу между соседними коммитами в `git log --no-merges`.
- Учитываем только интервалы <= 30 минут (всё, что больше — считаем “пауза/сон/не работали” и исключаем).
- Интервал относится к следующему коммиту и распределяется по разделам пропорционально количеству изменённых файлов в этом коммите.

### Времязатраты по Git (оценка)
- Правило: считаем только интервалы между соседними коммитами <= 30 минут
- Интервал относится к следующему коммиту и распределяется по разделам пропорционально числу изменённых файлов
- Учтённых интервалов: 1185
- Итого эффективного времени: 131 ч 29 мин

| Раздел | Время |
|---|---:|
| Прочее | 67 ч 31 мин |
| Payday | 20 ч 52 мин |
| Payday2 | 14 ч 20 мин |
| TR3 (публичная бронь) | 6 ч 11 мин |
| Telegram alerts/webhook | 5 ч 29 мин |
| КухняОнлайн | 3 ч 12 мин |
| Core (DB/Poster/Meta) | 2 ч 49 мин |
| Онлайн-меню | 1 ч 49 мин |
| Rawdata | 1 ч 38 мин |
| Cooked (errors) | 1 ч 30 мин |
| Auth/UI shell | 1 ч 24 мин |
| Kitchen sync (cron) | 1 ч 12 мин |
| Дашборд | 1 ч 3 мин |
| Zapara | 47 мин |
| Reservations | 41 мин |
| Admin | 22 мин |
| SePay webhook | 16 мин |
| Neworder | 11 мин |
| **Итого** | **131 ч 29 мин** |
