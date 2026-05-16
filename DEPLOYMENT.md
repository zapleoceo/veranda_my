## GitHub → server: автодеплой

Репозиторий: `git@github.com:zapleoceo/veranda_my.git`

### 1) Что хранится в Git, а что нет

- `.env` и любые `.env.*` не коммитятся.
- Логи (`*.log`) не коммитятся.

Файлы исключений: [.gitignore](file:///d:/Projects/Veranda%20site%202/.gitignore)

### 2) Подготовка SSH-ключа для деплоя (GitHub Actions → сервер)

На своём компьютере сгенерируйте отдельный ключ для GitHub Actions:

```bash
ssh-keygen -t ed25519 -C "github-actions-veranda-deploy" -f ~/.ssh/veranda_github_actions -N ""
```

Добавьте **публичный** ключ на сервер в `~/.ssh/authorized_keys` пользователя, который владеет веб-директориями (обычно `veranda_my_usr`):

```bash
mkdir -p ~/.ssh
chmod 700 ~/.ssh
cat ~/.ssh/veranda_github_actions.pub >> ~/.ssh/authorized_keys
chmod 600 ~/.ssh/authorized_keys
```

### 3) GitHub Secrets (Settings → Secrets and variables → Actions)

Нужные секреты для workflow:

- `SSH_HOST`: IP/домен сервера (например `5.101.179.132` или `veranda.my`)
- `SSH_PORT`: `22`
- `SSH_USER`: `veranda_my_usr`
- `DEPLOY_PATH`: `/var/www/veranda_my_usr/data/www/veranda.my`
- `SSH_PRIVATE_KEY`: содержимое приватного ключа `~/.ssh/veranda_github_actions` (целиком, включая строки BEGIN/END)

### 4) Workflow

Workflow деплоя находится тут: [.github/workflows/deploy.yml](file:///d:/Projects/Veranda%20site%202/.github/workflows/deploy.yml)

Триггер: push в ветку `main`.

### 5) Проверка

- Любой push в `main` запускает Action “Deploy to veranda.my”.
- После деплоя workflow выполняет `php -l` на ключевых файлах на сервере.

### 6) Cron / sync на сервере

Все cron-задания выполняются под `/opt/php82/bin/php` пользователем `veranda_my_usr`.
Источник истины — [`cron/crontab.txt`](cron/crontab.txt). Установлен через
`crontab cron/crontab.txt` (резервная копия предыдущей версии хранится в
`~/crontab.bak.YYYYMMDD_HHMMSS`):

```cron
# Kitchen sync — KitchenSyncService->run() (Poster → kitchen_stats), каждые 5 минут
*/5 * * * * /opt/php82/bin/php /var/www/veranda_my_usr/data/www/veranda.my/cron/kitchen_sync.php >> /var/www/veranda_my_usr/data/www/veranda.my/cron.log 2>&1

# Telegram alerts + reservation reminders (обе службы в одном entry-point), каждую минуту
*/1 * * * * /opt/php82/bin/php /var/www/veranda_my_usr/data/www/veranda.my/cron/telegram_alerts.php >> /var/www/veranda_my_usr/data/www/veranda.my/telegram.log 2>&1

# Menu sync — legacy `menu_cron.php` (TODO: переписать в `cron/menu_sync.php`), раз в час
0 * * * * /opt/php82/bin/php /var/www/veranda_my_usr/data/www/veranda.my/scripts/menu/cron.php >> /var/www/veranda_my_usr/data/www/veranda.my/menu_sync.log 2>&1

# Daily summary — legacy `daily_summary.php` (TODO: переписать в `cron/daily_summary.php`), 03:00
0 3 * * * /opt/php82/bin/php /var/www/veranda_my_usr/data/www/veranda.my/daily_summary.php >> /var/www/veranda_my_usr/data/www/veranda.my/daily_summary.log 2>&1

# Daily booking reminder, 03:00
0 3 * * * /opt/php82/bin/php /var/www/veranda_my_usr/data/www/veranda.my/scripts/reservations/daily_booking_reminder.php >> /var/www/veranda_my_usr/data/www/veranda.my/reservations_daily_reminder.log 2>&1
```

**Важно — почему PHP 8.2, а не 7.4:**

- Кодовая база использует `str_starts_with()`, `readonly`, named args, match — это PHP 8.0+.
- Старый crontab под `/opt/php74/bin/php` падал каждую минуту с `Call to undefined function str_starts_with()` / `veranda_base_url()` и т.п.
- `/opt/php82/bin/php` — единственный путь, согласованный с deploy workflow ([.github/workflows/deploy.yml](.github/workflows/deploy.yml)).

**Установить/обновить crontab вручную (если деплой не покрывает):**

```bash
# на сервере, под veranda_my_usr
crontab -l > ~/crontab.bak.$(date +%Y%m%d_%H%M%S)
# отредактируйте через `crontab -e` или подставьте файл:
crontab /path/to/new_crontab.txt
crontab -l   # проверка
```

**Куда смотреть, если что-то сломалось:**

| Файл | Что внутри |
|------|------------|
| `cron.log` | вывод `cron/kitchen_sync.php` |
| `telegram.log` | вывод `cron/telegram_alerts.php` (alerts + reminders) |
| `menu_sync.log` | вывод legacy `scripts/menu/cron.php` |
| `daily_summary.log` | вывод `daily_summary.php` |
| `reservations_daily_reminder.log` | вывод legacy `scripts/reservations/daily_booking_reminder.php` |
| `/var/www/veranda_my_usr/data/logs/veranda.my-frontend.error.log` | nginx + php-fpm ошибки web-запросов |

### 7) WhatsApp listener (pm2 + systemd)

`wa_listener/` — Node.js-сервис, который держит WhatsApp Web-сессию через
Baileys и выставляет локальный HTTP-эндпойнт `127.0.0.1:3210` для PHP-сайта.
Используется через `wa_bridge_send($phone, $text)` (см. [tr3/api_context.php](tr3/api_context.php)).

**Управляется через pm2** (2 процесса: `veranda-wa-listener` + `veranda-wa-watchdog`),
а pm2-демон поднимается при загрузке систему systemd-юнитом:

| Источник истины | Путь на сервере |
|------------------|-----------------|
| [`cron/pm2-veranda_my_usr.service`](cron/pm2-veranda_my_usr.service) | `/etc/systemd/system/pm2-veranda_my_usr.service` |

Юнит `Type=oneshot, RemainAfterExit=yes` — `ExecStart=pm2 resurrect` отрабатывает
один раз и оставляет pm2-демон висеть в cgroup. `enabled` — запускается при boot.

**Healthcheck:**

```bash
ssh veranda_my_usr@5.101.179.132 "curl -sS http://127.0.0.1:3210/healthz"
# {"ok":true,"connected":true,"version":"1.0.0"}
ssh veranda_my_usr@5.101.179.132 "pm2 list"
```

**Перезапустить вручную:**

```bash
ssh veranda_my_usr@5.101.179.132 "pm2 restart all"
# или полный цикл через systemd (требует root):
sudo systemctl restart pm2-veranda_my_usr
```

**Логи** pm2 пишет в `~/.pm2/logs/veranda-wa-listener-{out,error}.log` и
`~/.pm2/logs/veranda-wa-watchdog-{out,error}.log`.

**Первоначальная установка systemd-юнита** (одноразово, требует root):

```bash
# 1) Скопировать юнит на сервер
scp cron/pm2-veranda_my_usr.service root@5.101.179.132:/etc/systemd/system/

# 2) Включить и запустить
sudo systemctl daemon-reload
sudo systemctl enable --now pm2-veranda_my_usr
```

После любых изменений в pm2 (новые процессы, изменение порядка) — сохранить
дамп: `ssh veranda_my_usr@... pm2 save`. Иначе при ребуте они не восстановятся.
