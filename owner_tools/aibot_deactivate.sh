#!/usr/bin/env bash
# Откат активации @Veranda_aibot. ЗАПУСКАЕТ ТОЛЬКО ВЛАДЕЛЕЦ на прод-сервере
# (пользователь veranda_my_usr): bash ~/veranda_ops/aibot_deactivate.sh [orig|<ts>]
#   1) deleteWebhook БЕЗ сброса очереди (drop_pending_updates=false);
#   2) возвращает .env из бэкапа ~/.aibot_env_backups/:
#        без аргумента или «orig» — .env.bak.orig (состояние до первой активации);
#        <ts> — .env.bak.<ts> (снимок перед конкретным запуском).
# Секреты не печатаются и не попадают в argv; вывод — фиксированные статусы.
set -euo pipefail
umask 077

APP="${AIBOT_APP_DIR:-/var/www/veranda_my_usr/data/www/veranda.my}"
ENV_FILE="$APP/.env"
BAKDIR="${HOME:-/var/www/veranda_my_usr/data}/.aibot_env_backups"
DOH=https://1.1.1.1/dns-query

WHICH="${1:-orig}"
if [ "$WHICH" = orig ]; then
  BACKUP="$BAKDIR/.env.bak.orig"
elif [[ "$WHICH" =~ ^[0-9]+$ ]]; then
  BACKUP="$BAKDIR/.env.bak.$WHICH"
else
  echo "ERR bad_argument (orig или unix-ts)"; exit 1
fi
[ -f "$BACKUP" ] || { echo "ERR backup_not_found"; exit 1; }
[ -f "$ENV_FILE" ] || { echo "ERR env_missing"; exit 1; }

# Чтение .env встроенными командами bash (как в aibot_activate.sh).
env_get() {
  local key="$1" line val=""
  while IFS= read -r line || [ -n "$line" ]; do
    line="${line%$'\r'}"
    if [[ "$line" == "$key="* ]]; then
      val="${line#*=}"
      if [[ "$val" == \"*\" && ${#val} -ge 2 ]]; then val="${val:1:${#val}-2}";
      elif [[ "$val" == \'*\' && ${#val} -ge 2 ]]; then val="${val:1:${#val}-2}"; fi
    fi
  done < "$ENV_FILE"
  printf '%s' "$val"
}

TOKEN=$(env_get ai_tg_bot)
[[ "$TOKEN" =~ ^[0-9]+:[A-Za-z0-9_-]+$ ]] || { TOKEN=""; echo "ERR ai_tg_bot_missing_or_invalid"; exit 1; }

RESP=$(printf 'url = "https://api.telegram.org/bot%s/deleteWebhook"\ndata = "drop_pending_updates=false"\n' "$TOKEN" \
  | curl -sS --max-time 20 --doh-url "$DOH" -K - 2>/dev/null) || RESP=""
TOKEN=""
if [[ "$RESP" == *'"ok":true'* ]]; then
  echo "deleteWebhook: OK"
else
  echo "deleteWebhook: FAIL — .env не трогаю"; exit 2
fi

TMP=$(mktemp "$APP/.env.aibot.XXXXXX")
trap 'rm -f "$TMP"' EXIT
cat "$BACKUP" > "$TMP"
chmod --reference="$ENV_FILE" "$TMP" 2>/dev/null || chmod 600 "$TMP"
mv -f "$TMP" "$ENV_FILE"
echo "env: restored from $BACKUP"
echo "DONE"
