#!/usr/bin/env bash
# Активация @Veranda_aibot (финансовые черновики «Инвесторы»).
#
# ЗАПУСКАЕТ ТОЛЬКО ВЛАДЕЛЕЦ, на прод-сервере, пользователем veranda_my_usr:
#   bash /var/www/veranda_my_usr/data/www/veranda.my/scripts/aibot_activate.sh
#
# Что делает (ничего секретного не печатает, секреты не попадают в аргументы
# процессов и в историю shell, очередь апдейтов НЕ очищает):
#   1) бэкап .env → .env.bak.<unix-ts> (права 600);
#   2) AIBOT_WEBHOOK_SECRET — генерирует на сервере (openssl rand -hex 32), если ещё не задан;
#   3) берёт существующий ai_tg_bot (если нет — останавливается);
#   4) AIBOT_FINANCE_ALLOWED_TG_IDS=169510539, AIBOT_FINANCE_ANY_GROUP=1,
#      AIBOT_CONFIRM_NOT_BEFORE=<сейчас>;
#   5) setWebhook: url=https://veranda.my/aibot_webhook, secret_token, allowed_updates=
#      ["message","callback_query"], drop_pending_updates=false. DNS для api.telegram.org —
#      через DoH 1.1.1.1 (локальный резолвер хостинга его не резолвит);
#   6) печатает только безопасную проверку.
#
# Откат: deleteWebhook (без сброса очереди) и восстановление .env из бэкапа
#   ~/.aibot_env_backups/.env.bak.<ts> — команды в описании задачи / чате.
set -euo pipefail
umask 077

APP=/var/www/veranda_my_usr/data/www/veranda.my
ENV="$APP/.env"
OWNER_ID=169510539
URL=https://veranda.my/aibot_webhook
DOH=https://1.1.1.1/dns-query

[ -f "$ENV" ] || { echo "ERR: нет $ENV"; exit 1; }
command -v openssl >/dev/null || { echo "ERR: нет openssl"; exit 1; }

# Значение ключа из .env без вывода (снимает кавычки и \r).
env_get() { { grep -E "^$1=" "$ENV" || true; } | tail -n1 | cut -d= -f2- | tr -d '\r' | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'\$//"; }

# Записать/заменить KEY=VALUE в .env (значения только [A-Za-z0-9_-], без вывода).
env_set() {
  local k="$1" v="$2"
  case "$v" in *[!A-Za-z0-9_-]*) echo "ERR: недопустимое значение для $k"; exit 1;; esac
  if grep -qE "^$k=" "$ENV"; then
    sed -i "s|^$k=.*|$k=$v|" "$ENV"
  else
    [ -n "$(tail -c1 "$ENV")" ] && printf '\n' >> "$ENV"
    printf '%s=%s\n' "$k" "$v" >> "$ENV"
  fi
}

# 1) бэкап
# Бэкап — ВНЕ веб-корня (в нём все секреты), права 600.
TS=$(date +%s)
BAKDIR="${HOME:-/var/www/veranda_my_usr/data}/.aibot_env_backups"
case "$BAKDIR" in "$APP"/*) echo "ERR: каталог бэкапа внутри веб-корня"; exit 1;; esac
mkdir -p "$BAKDIR" && chmod 700 "$BAKDIR"
install -m 600 "$ENV" "$BAKDIR/.env.bak.$TS"
echo "backup: $BAKDIR/.env.bak.$TS"

# 3) токен бота должен уже быть
TOKEN=$(env_get ai_tg_bot)
[ -n "$TOKEN" ] || { echo "ERR: ai_tg_bot не задан в .env — ничего не меняю"; exit 1; }

# 2) секрет вебхука (существующий не перегенерируем — повторный запуск безопасен)
SECRET=$(env_get AIBOT_WEBHOOK_SECRET)
if [ -z "$SECRET" ]; then
  SECRET=$(openssl rand -hex 32)
  env_set AIBOT_WEBHOOK_SECRET "$SECRET"
  echo "AIBOT_WEBHOOK_SECRET: создан"
else
  case "$SECRET" in *[!A-Za-z0-9_-]*) echo "ERR: существующий AIBOT_WEBHOOK_SECRET содержит недопустимые символы — исправьте вручную"; exit 1;; esac
  echo "AIBOT_WEBHOOK_SECRET: уже был, оставлен"
fi

# 4) владелец, режим любой группы, отсечка старых подтверждений.
#    Повторный запуск сдвигает отсечку: кнопки карточек, выданных раньше,
#    перестанут подтверждать (карточка обновится с просьбой подтвердить заново).
env_set AIBOT_FINANCE_ALLOWED_TG_IDS "$OWNER_ID"
env_set AIBOT_FINANCE_ANY_GROUP 1
env_set AIBOT_CONFIRM_NOT_BEFORE "$TS"
echo "env: AIBOT_FINANCE_ALLOWED_TG_IDS, AIBOT_FINANCE_ANY_GROUP=1, AIBOT_CONFIRM_NOT_BEFORE записаны"

# Вызов Bot API: токен и секрет идут через конфиг curl на stdin (-K -),
# а не через аргументы командной строки — не видны в ps и истории.
tg() { # $1 = метод, остальное — строки конфига curl
  local method="$1"; shift
  {
    printf 'url = "https://api.telegram.org/bot%s/%s"\n' "$TOKEN" "$method"
    for line in "$@"; do printf '%s\n' "$line"; done
  } | curl -sS --max-time 20 --doh-url "$DOH" -K -
}

# 5) setWebhook (очередь не сбрасываем)
RESP=$(tg setWebhook \
  "data-urlencode = \"url=$URL\"" \
  "data-urlencode = \"secret_token=$SECRET\"" \
  'data-urlencode = "allowed_updates=[\"message\",\"callback_query\"]"' \
  'data = "drop_pending_updates=false"' || true)
unset SECRET
if printf '%s' "$RESP" | grep -q '"ok":true'; then echo "setWebhook: ok"; else
  echo "setWebhook: err $(printf '%s' "$RESP" | grep -o '"description":"[^"]*"' | head -n1)"; fi

# 6) безопасная проверка
INFO=$(tg getWebhookInfo || true)
unset TOKEN
echo "webhook url: $(printf '%s' "$INFO" | grep -o '"url":"[^"]*"' | head -n1 | cut -d'"' -f4)"
echo "allowed_updates: $(printf '%s' "$INFO" | grep -o '"allowed_updates":\[[^]]*\]' | cut -d: -f2-)"
echo "pending_update_count: $(printf '%s' "$INFO" | grep -o '"pending_update_count":[0-9]*' | cut -d: -f2)"
LAST_ERR=$(printf '%s' "$INFO" | grep -o '"last_error_message":"[^"]*"' | cut -d'"' -f4 || true)
[ -n "$LAST_ERR" ] && echo "last_error_message: $LAST_ERR"
CODE=$(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' -d '{}' "$URL" || true)
echo "POST $URL без секрета → $CODE (ожидается 403)"
