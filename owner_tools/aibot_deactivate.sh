#!/usr/bin/env bash
# Откат активации @Veranda_aibot. ЗАПУСКАЕТ ТОЛЬКО ВЛАДЕЛЕЦ на прод-сервере
# (пользователь veranda_my_usr): bash ~/veranda_owner_tools/aibot_deactivate.sh [<ts бэкапа>]
#   1) deleteWebhook БЕЗ сброса очереди (drop_pending_updates=false);
#   2) возвращает .env из бэкапа ~/.aibot_env_backups/.env.bak.<ts>
#      (без аргумента — самый свежий бэкап).
# Секреты не печатаются и не попадают в argv; вывод — фиксированные статусы.
set -euo pipefail
umask 077

APP="${AIBOT_APP_DIR:-/var/www/veranda_my_usr/data/www/veranda.my}"
ENV_FILE="$APP/.env"
BAKDIR="${HOME:-/var/www/veranda_my_usr/data}/.aibot_env_backups"
DOH=https://1.1.1.1/dns-query

if [ $# -ge 1 ]; then
  [[ "$1" =~ ^[0-9]+$ ]] || { echo "ERR bad_ts"; exit 1; }
  BACKUP="$BAKDIR/.env.bak.$1"
else
  BACKUP=$(ls -1 "$BAKDIR"/.env.bak.* 2>/dev/null | sort -t. -k3 -n | tail -n1 || true)
fi
[ -n "$BACKUP" ] && [ -f "$BACKUP" ] || { echo "ERR backup_not_found"; exit 1; }

TOKEN=""
while IFS= read -r line || [ -n "$line" ]; do
  line="${line%$'\r'}"
  if [[ "$line" == ai_tg_bot=* ]]; then
    TOKEN="${line#*=}"; TOKEN="${TOKEN#\"}"; TOKEN="${TOKEN%\"}"
  fi
done < "$ENV_FILE"
[ -n "$TOKEN" ] || { echo "ERR ai_tg_bot_missing"; exit 1; }

RESP=$(printf 'url = "https://api.telegram.org/bot%s/deleteWebhook"\ndata = "drop_pending_updates=false"\n' "$TOKEN" \
  | curl -sS --max-time 20 --doh-url "$DOH" -K - 2>/dev/null) || RESP=""
TOKEN=""
if [[ "$RESP" == *'"ok":true'* ]]; then echo "deleteWebhook: OK"; else echo "deleteWebhook: FAIL — .env не трогаю"; exit 2; fi

install -m 600 "$BACKUP" "$ENV_FILE.restore.$$"
mv -f "$ENV_FILE.restore.$$" "$ENV_FILE"
echo "env: restored from $BACKUP"
echo "DONE"
