#!/usr/bin/env bash
# Активация @Veranda_aibot (финансовые черновики «Инвесторы»).
#
# ЗАПУСКАЕТ ТОЛЬКО ВЛАДЕЛЕЦ, на прод-сервере, пользователем veranda_my_usr.
# Деплой кладёт файл ВНЕ веб-корня: ~/veranda_owner_tools/aibot_activate.sh
#
# Гарантии:
#   - секреты (токен бота, секрет вебхука) не печатаются, не попадают в argv
#     внешних процессов, в переменные окружения и в историю shell: .env правится
#     встроенными командами bash (read/printf) во временный файл и атомарно
#     заменяется через mv; к Telegram — через `curl -K -` (конфиг на stdin);
#   - очередь Telegram НЕ очищается (drop_pending_updates=false);
#   - бэкап .env — вне веб-корня, права 600;
#   - вывод — только фиксированные статусы; ответы Telegram сырыми не печатаются;
#   - любая ошибка → ненулевой код выхода. Сбой setWebhook → .env возвращается
#     из бэкапа автоматически.
#
# Коды выхода: 0 ok; 1 подготовка (нет .env/ai_tg_bot/openssl, плохой секрет);
#   2 setWebhook не прошёл (.env восстановлен); 3 getWebhookInfo не подтвердил
#   настройки; 4 POST /aibot_webhook без секрета вернул не 403.
set -euo pipefail
umask 077

APP="${AIBOT_APP_DIR:-/var/www/veranda_my_usr/data/www/veranda.my}"
ENV_FILE="$APP/.env"
OWNER_ID=169510539
WEBHOOK_URL=https://veranda.my/aibot_webhook
DOH=https://1.1.1.1/dns-query
BAKDIR="${HOME:-/var/www/veranda_my_usr/data}/.aibot_env_backups"

fail() { echo "ERR $1"; exit "$2"; }

[ -f "$ENV_FILE" ] || fail "env_missing" 1
command -v openssl >/dev/null || fail "openssl_missing" 1
case "$BAKDIR" in "$APP"/*) fail "backup_dir_inside_webroot" 1;; esac

# ─── чтение .env без внешних процессов: последний KEY=… побеждает, кавычки снимаются
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

# ─── атомарная запись: KEY=VALUE заменяются на месте (все вхождения), отсутствующие
#     дописываются в конец; прочие строки, порядок и окончания строк сохраняются.
#     Значения передаются ТОЛЬКО через ассоциативный массив в памяти bash.
declare -A SET_VALUES=()
env_commit() {
  local tmp line key done_keys="" k
  tmp=$(mktemp "$APP/.env.aibot.XXXXXX")
  chmod --reference="$ENV_FILE" "$tmp" 2>/dev/null || chmod 600 "$tmp"
  {
    while IFS= read -r line || [ -n "$line" ]; do
      local cr=""
      [[ "$line" == *$'\r' ]] && cr=$'\r' && line="${line%$'\r'}"
      key="${line%%=*}"
      if [[ "$line" == *=* && -n "$key" && "$key" =~ ^[A-Za-z_][A-Za-z0-9_]*$ && -n "${SET_VALUES[$key]+x}" ]]; then
        printf '%s=%s%s\n' "$key" "${SET_VALUES[$key]}" "$cr"
        done_keys+=" $key "
      else
        printf '%s%s\n' "$line" "$cr"
      fi
    done < "$ENV_FILE"
    for k in "${!SET_VALUES[@]}"; do
      [[ "$done_keys" == *" $k "* ]] || printf '%s=%s\n' "$k" "${SET_VALUES[$k]}"
    done
  } > "$tmp"
  mv -f "$tmp" "$ENV_FILE"
}

valid_value() { [[ -n "$1" && "$1" =~ ^[A-Za-z0-9_-]+$ ]]; }

# 1) бэкап вне веб-корня
TS=$(date +%s)
mkdir -p "$BAKDIR"
chmod 700 "$BAKDIR"
BACKUP="$BAKDIR/.env.bak.$TS"
install -m 600 "$ENV_FILE" "$BACKUP"
echo "backup: $BACKUP"

restore_env() { install -m 600 "$BACKUP" "$ENV_FILE.restore.$TS" && mv -f "$ENV_FILE.restore.$TS" "$ENV_FILE"; }

# 2) токен бота должен уже быть
TOKEN=$(env_get ai_tg_bot)
[ -n "$TOKEN" ] || fail "ai_tg_bot_missing (.env не менялся)" 1

# 3) секрет вебхука: существующий оставляем (должен быть [A-Za-z0-9_-]), иначе генерируем
SECRET=$(env_get AIBOT_WEBHOOK_SECRET)
if [ -n "$SECRET" ]; then
  valid_value "$SECRET" || fail "existing_secret_invalid (.env не менялся)" 1
  echo "secret: kept"
else
  SECRET=$(openssl rand -hex 32)
  valid_value "$SECRET" || fail "secret_generation_failed" 1
  SET_VALUES[AIBOT_WEBHOOK_SECRET]="$SECRET"
  echo "secret: created"
fi

# 4) владелец, режим любой группы, отсечка старых подтверждений (повторный запуск её сдвигает)
SET_VALUES[AIBOT_FINANCE_ALLOWED_TG_IDS]="$OWNER_ID"
SET_VALUES[AIBOT_FINANCE_ANY_GROUP]=1
SET_VALUES[AIBOT_CONFIRM_NOT_BEFORE]="$TS"
env_commit
SET_VALUES=()
echo "env: updated"

# Bot API: URL с токеном и данные — только в конфиге curl на stdin.
tg() {
  local method="$1"; shift
  {
    printf 'url = "https://api.telegram.org/bot%s/%s"\n' "$TOKEN" "$method"
    local l; for l in "$@"; do printf '%s\n' "$l"; done
  } | curl -sS --max-time 20 --doh-url "$DOH" -K - 2>/dev/null
}

# 5) setWebhook (очередь не сбрасываем)
RESP=$(tg setWebhook \
  "data-urlencode = \"url=$WEBHOOK_URL\"" \
  "data-urlencode = \"secret_token=$SECRET\"" \
  'data-urlencode = "allowed_updates=[\"message\",\"callback_query\"]"' \
  'data = "drop_pending_updates=false"') || RESP=""
SECRET=""
if [[ "$RESP" == *'"ok":true'* ]]; then
  echo "setWebhook: OK"
else
  restore_env || true
  TOKEN=""
  echo "setWebhook: FAIL — .env восстановлен из $BACKUP, webhook не изменён"
  exit 2
fi

# 6) проверка (без сырых ответов)
INFO=$(tg getWebhookInfo) || INFO=""
TOKEN=""
CHECK_OK=1
[[ "$INFO" == *"\"url\":\"$WEBHOOK_URL\""* ]] && echo "webhook url: OK" || { echo "webhook url: MISMATCH"; CHECK_OK=0; }
[[ "$INFO" == *'"allowed_updates":["message","callback_query"]'* ]] && echo "allowed_updates: OK" || { echo "allowed_updates: MISMATCH"; CHECK_OK=0; }
PENDING=""
[[ "$INFO" =~ \"pending_update_count\":([0-9]+) ]] && PENDING="${BASH_REMATCH[1]}"
echo "pending_update_count: ${PENDING:-unknown}"
[[ "$INFO" == *'"last_error_message"'* ]] && echo "last_error: present (текст не выводится)" || echo "last_error: none"
[ "$CHECK_OK" = 1 ] || { echo "verify: FAIL — откат: см. owner_tools/aibot_deactivate.sh"; exit 3; }

CODE=$(curl -s -o /dev/null -w '%{http_code}' --max-time 15 -X POST -H 'Content-Type: application/json' -d '{}' "$WEBHOOK_URL" 2>/dev/null) || CODE=000
if [ "$CODE" = 403 ]; then
  echo "POST без секрета: 403 OK"
else
  echo "POST без секрета: $CODE (ожидалось 403) — откат: см. owner_tools/aibot_deactivate.sh"
  exit 4
fi
echo "DONE"
