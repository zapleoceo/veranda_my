#!/usr/bin/env bash
# Тесты owner_tools/aibot_activate.sh / aibot_deactivate.sh на заглушках — без сети и
# без настоящих секретов. Каждый внешний процесс, который запускают скрипты,
# идёт через «шим», записывающий свой argv в лог; затем проверяется, что ни
# токен, ни секрет не попали ни в один argv и ни в вывод.
set -uo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

TOKEN_VAL="123456:TESTTOKENxyz"
SECRET_VAL="aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"
fails=0
pass() { echo "ok   - $1"; }
bad()  { echo "FAIL - $1"; fails=$((fails + 1)); }

REAL_PATH="$PATH"
SHIMS="$WORK/shims"; STUBS="$WORK/stubs"; mkdir -p "$SHIMS" "$STUBS"
ARGV_LOG="$WORK/argv.log"

# Шимы для всех внешних команд, которые используют скрипты.
for c in mktemp chmod mv install mkdir date ls sort tail cat rm; do
  real=$(PATH="$REAL_PATH" command -v "$c")
  printf '#!/usr/bin/env bash\nprintf "%%s %%s\\n" "%s" "$*" >> "%s"\nexec "%s" "$@"\n' "$c" "$ARGV_LOG" "$real" > "$SHIMS/$c"
  chmod +x "$SHIMS/$c"
done
# mv: при MV_FAIL=1 отказывается заменять .env (имитация сбоя посреди записи).
real_mv=$(PATH="$REAL_PATH" command -v mv)
printf '#!/usr/bin/env bash\nprintf "%%s %%s\\n" mv "$*" >> "%s"\nif [ "${MV_FAIL:-0}" = 1 ] && [[ "${@: -1}" == */.env ]]; then exit 1; fi\nexec "%s" "$@"\n' "$ARGV_LOG" "$real_mv" > "$SHIMS/mv"
chmod +x "$SHIMS/mv"
LINUX=0; [ "$(uname -s)" = Linux ] && LINUX=1
# openssl: фиксированный «секрет».
printf '#!/usr/bin/env bash\nprintf "%%s %%s\\n" openssl "$*" >> "%s"\necho %s\n' "$ARGV_LOG" "$SECRET_VAL" > "$STUBS/openssl"
# curl: читает конфиг со stdin (если -K -), пишет argv в лог, stdin — в отдельный файл.
cat > "$STUBS/curl" <<EOF
#!/usr/bin/env bash
printf '%s %s\n' curl "\$*" >> "$ARGV_LOG"
cfg=""
for a in "\$@"; do [ "\$a" = "-K" ] && cfg=\$(cat); done
printf '%s\n---\n' "\$cfg" >> "$WORK/curl_stdin.log"
case "\$cfg" in
  *setWebhook*)    cat "$WORK/resp_set" ;;
  *getWebhookInfo*) cat "$WORK/resp_info" ;;
  *deleteWebhook*) echo '{"ok":true,"result":true}' ;;
  *) cat "$WORK/resp_post" ;;
esac
EOF
chmod +x "$STUBS/openssl" "$STUBS/curl"

GOOD_INFO='{"ok":true,"result":{"url":"https://veranda.my/aibot_webhook","has_custom_certificate":false,"pending_update_count":129,"last_error_message":"Wrong response from the webhook: 503 bot123456:TESTTOKENxyz","allowed_updates":["message","callback_query"]}}'

setup() { # $1 = содержимое .env (printf-формат), далее аргументы printf
  rm -rf "$WORK/app" "$WORK/home" "$ARGV_LOG" "$WORK/curl_stdin.log"
  mkdir -p "$WORK/app" "$WORK/home"
  # shellcheck disable=SC2059
  printf "$@" > "$WORK/app/.env"
  chmod 640 "$WORK/app/.env"
  cp "$WORK/app/.env" "$WORK/env.orig"
  echo '{"ok":true,"result":true,"description":"Webhook was set"}' > "$WORK/resp_set"
  echo "$GOOD_INFO" > "$WORK/resp_info"
  printf '403' > "$WORK/resp_post"
}
run() { # $1 = скрипт
  HOME="$WORK/home" AIBOT_APP_DIR="$WORK/app" PATH="$SHIMS:$STUBS:$REAL_PATH" \
    bash "$ROOT/owner_tools/$1" "${@:2}" > "$WORK/out.txt" 2>&1
  echo $?
}
no_leak() { # $1 = имя кейса
  grep -q '^curl ' "$ARGV_LOG" && grep -q '^mktemp \|^install ' "$ARGV_LOG" && pass "$1: логгер argv видел curl и файловые команды" || bad "$1: логгер argv пуст"
  if grep -qF "$TOKEN_VAL" "$ARGV_LOG" || grep -qF "$SECRET_VAL" "$ARGV_LOG"; then bad "$1: секрет/токен в argv"; else pass "$1: argv без секретов"; fi
  if grep -qF "$TOKEN_VAL" "$WORK/out.txt" || grep -qF "$SECRET_VAL" "$WORK/out.txt" || grep -qi "description\|Wrong response" "$WORK/out.txt"; then
    bad "$1: секрет/сырой ответ в выводе"; else pass "$1: вывод без секретов и сырых ответов"; fi
}

# ── 1. Существующий ПУСТОЙ AIBOT_WEBHOOK_SECRET=, CRLF-строка, комментарий, без \n в конце
setup 'APP_ENV=prod\n# comment = keep\nai_tg_bot="%s"\r\nAIBOT_WEBHOOK_SECRET=\nOTHER=1\nAIBOT_FINANCE_ALLOWED_TG_IDS=5' "$TOKEN_VAL"
code=$(run aibot_activate.sh)
[ "$code" = 0 ] && pass "1: exit 0" || { bad "1: exit $code"; cat "$WORK/out.txt"; }
exp=$(printf 'APP_ENV=prod\n# comment = keep\nai_tg_bot="%s"\r\nAIBOT_WEBHOOK_SECRET=%s\nOTHER=1\nAIBOT_FINANCE_ALLOWED_TG_IDS=5\n' "$TOKEN_VAL" "$SECRET_VAL")
got=$(head -n 6 "$WORK/app/.env")
[ "$got" = "$exp" ] && pass "1: ключи, порядок и CRLF сохранены, пустой секрет заполнен на месте" || { bad "1: содержимое .env"; diff <(echo "$exp") <(echo "$got") | head; }
grep -qx 'AIBOT_FINANCE_ANY_GROUP=1' "$WORK/app/.env" && grep -qE '^AIBOT_CONFIRM_NOT_BEFORE=[0-9]+$' "$WORK/app/.env" && pass "1: новые ключи дописаны" || bad "1: новые ключи"
[ "$(grep -c '^AIBOT_WEBHOOK_SECRET=' "$WORK/app/.env")" = 1 ] && pass "1: секрет не продублирован" || bad "1: дубли секрета"
ls "$WORK/home/.aibot_env_backups/".env.bak.* >/dev/null 2>&1 && pass "1: бэкап вне app" || bad "1: нет бэкапа"
cmp -s "$WORK/home/.aibot_env_backups/.env.bak.orig" "$WORK/env.orig" && pass "1: .env.bak.orig = исходный" || bad "1: .env.bak.orig"
if [ "$LINUX" = 1 ]; then
  [ "$(stat -c %a "$WORK/home/.aibot_env_backups/.env.bak.orig")" = 600 ] && pass "1: бэкап 600" || bad "1: права бэкапа"
  [ "$(stat -c %a "$WORK/app/.env")" = 640 ] && pass "1: права .env сохранены" || bad "1: права .env"
fi
grep -q 'drop_pending_updates=false' "$WORK/curl_stdin.log" && pass "1: очередь не сбрасывается" || bad "1: drop_pending_updates"
grep -q 'secret_token=' "$WORK/curl_stdin.log" && pass "1: секрет передан в setWebhook через stdin" || bad "1: secret_token"
grep -q 'last_error: present' "$WORK/out.txt" && pass "1: last_error только категорией" || bad "1: last_error"
ls -a "$WORK/app" | grep -q 'env.aibot\|restore' && bad "1: временные файлы остались" || pass "1: временных файлов нет"
no_leak "1"

# ── 2. Сбой setWebhook → exit 2, .env восстановлен байт-в-байт, без сырого description
setup 'ai_tg_bot=%s\nAIBOT_WEBHOOK_SECRET=\n' "$TOKEN_VAL"
echo '{"ok":false,"error_code":401,"description":"Unauthorized bot123456:TESTTOKENxyz"}' > "$WORK/resp_set"
code=$(run aibot_activate.sh)
[ "$code" = 2 ] && pass "2: exit 2" || bad "2: exit $code"
cmp -s "$WORK/app/.env" "$WORK/env.orig" && pass "2: .env восстановлен" || bad "2: .env не восстановлен"
no_leak "2"

# ── 3. getWebhookInfo не подтверждает → exit 3
setup 'ai_tg_bot=%s\n' "$TOKEN_VAL"
echo '{"ok":true,"result":{"url":"","pending_update_count":0}}' > "$WORK/resp_info"
code=$(run aibot_activate.sh); [ "$code" = 3 ] && pass "3: exit 3" || bad "3: exit $code"
no_leak "3"

# ── 4. POST без секрета ≠ 403 → exit 4
setup 'ai_tg_bot=%s\n' "$TOKEN_VAL"; printf '503' > "$WORK/resp_post"
code=$(run aibot_activate.sh); [ "$code" = 4 ] && pass "4: exit 4" || bad "4: exit $code"
no_leak "4"

# ── 5. Нет ai_tg_bot → exit 1, .env не тронут
setup 'APP_ENV=prod\n'
code=$(run aibot_activate.sh); [ "$code" = 1 ] && pass "5: exit 1" || bad "5: exit $code"
cmp -s "$WORK/app/.env" "$WORK/env.orig" && pass "5: .env не тронут" || bad "5: .env изменён"

# ── 6. Существующий секрет с недопустимыми символами → exit 1, .env не тронут
setup 'ai_tg_bot=%s\nAIBOT_WEBHOOK_SECRET="a b"\n' "$TOKEN_VAL"
code=$(run aibot_activate.sh); [ "$code" = 1 ] && pass "6: exit 1" || bad "6: exit $code"
cmp -s "$WORK/app/.env" "$WORK/env.orig" && pass "6: .env не тронут" || bad "6: .env изменён"
grep -qF 'a b' "$WORK/out.txt" && bad "6: секрет в выводе" || pass "6: вывод без секрета"

# ── 7. Повторный запуск: секрет сохраняется
setup 'ai_tg_bot=%s\nAIBOT_WEBHOOK_SECRET=keep_me-123\n' "$TOKEN_VAL"
code=$(run aibot_activate.sh); [ "$code" = 0 ] && pass "7: exit 0" || bad "7: exit $code"
grep -qx 'AIBOT_WEBHOOK_SECRET=keep_me-123' "$WORK/app/.env" && pass "7: секрет сохранён" || bad "7: секрет перезаписан"
grep -q 'secret: kept' "$WORK/out.txt" && pass "7: статус kept" || bad "7: статус"
grep -qF 'keep_me-123' "$ARGV_LOG" "$WORK/out.txt" && bad "7: существующий секрет утёк" || pass "7: существующий секрет не утёк"
no_leak "7"

# ── 8. Откат: deleteWebhook без сброса очереди + .env из бэкапа
setup 'ai_tg_bot=%s\nAIBOT_WEBHOOK_SECRET=\n' "$TOKEN_VAL"
run aibot_activate.sh >/dev/null
code=$(run aibot_deactivate.sh); [ "$code" = 0 ] && pass "8: exit 0" || { bad "8: exit $code"; cat "$WORK/out.txt"; }
cmp -s "$WORK/app/.env" "$WORK/env.orig" && pass "8: .env восстановлен" || bad "8: .env"
grep -q 'deleteWebhook' "$WORK/curl_stdin.log" && grep -q 'drop_pending_updates=false' "$WORK/curl_stdin.log" && pass "8: deleteWebhook без сброса" || bad "8: deleteWebhook"
no_leak "8"

# ── 9. Два запуска, затем откат по умолчанию → состояние ДО первой активации
setup 'ai_tg_bot=%s\nAIBOT_WEBHOOK_SECRET=\nKEEP=x\n' "$TOKEN_VAL"
run aibot_activate.sh >/dev/null; sleep 1; run aibot_activate.sh >/dev/null
code=$(run aibot_deactivate.sh); [ "$code" = 0 ] && pass "9: exit 0" || bad "9: exit $code"
cmp -s "$WORK/app/.env" "$WORK/env.orig" && pass "9: возвращено исходное (.env.bak.orig), а не снимок после 1-го запуска" || bad "9: откат вернул не исходное"

# ── 10. Сбой посреди записи .env (mv) → ненулевой код, временных файлов в app нет, .env цел
setup 'ai_tg_bot=%s\nAIBOT_WEBHOOK_SECRET=\n' "$TOKEN_VAL"
code=$(MV_FAIL=1 run aibot_activate.sh); [ "$code" != 0 ] && pass "10: ненулевой код ($code)" || bad "10: exit 0"
ls -a "$WORK/app" | grep -q 'env.aibot' && bad "10: временный файл с секретами остался в app" || pass "10: временный файл удалён"
cmp -s "$WORK/app/.env" "$WORK/env.orig" && pass "10: .env не изменён" || bad "10: .env изменён"

# ── 11. setWebhook без ответа (таймаут) → exit 2, статус UNKNOWN, .env из снимка
setup 'ai_tg_bot=%s\nAIBOT_WEBHOOK_SECRET=\n' "$TOKEN_VAL"
: > "$WORK/resp_set"
code=$(run aibot_activate.sh); [ "$code" = 2 ] && pass "11: exit 2" || bad "11: exit $code"
grep -q 'setWebhook: UNKNOWN' "$WORK/out.txt" && grep -q 'env: restored' "$WORK/out.txt" && pass "11: UNKNOWN + restored" || bad "11: статусы"
cmp -s "$WORK/app/.env" "$WORK/env.orig" && pass "11: .env восстановлен" || bad "11: .env"
no_leak "11"

echo
[ "$fails" = 0 ] && { echo "ALL PASSED"; exit 0; } || { echo "$fails FAILED"; exit 1; }
