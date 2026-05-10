const assert = (cond, msg) => {
  if (!cond) {
    process.stderr.write(`FAIL: ${msg}\n`)
    process.exit(1)
  }
}

const fs = require('fs')
const html = fs.readFileSync('/workspace/testai/index.php', 'utf8')
const api = fs.readFileSync('/workspace/testai/api.php', 'utf8')
const webhook = fs.readFileSync('/workspace/testai/webhook.php', 'utf8')
const sanitize = fs.readFileSync('/workspace/testai/html_sanitize.php', 'utf8')

assert(/header\('Location:\s*\/admin\/\?tab=aibot/.test(html), 'index should redirect to /admin/?tab=aibot')

assert(/'stats'\s*=>\s*__DIR__\s*\.\s*'\/api\/stats\.php'/.test(api), 'api must route stats')
assert(/'summary'\s*=>\s*__DIR__\s*\.\s*'\/api\/summary\.php'/.test(api), 'api must route summary')
assert(/'get_prompt'\s*=>\s*__DIR__\s*\.\s*'\/api\/get_prompt\.php'/.test(api), 'api must route get_prompt')
assert(/'set_prompt'\s*=>\s*__DIR__\s*\.\s*'\/api\/set_prompt\.php'/.test(api), 'api must route set_prompt')
assert(/echo\s+'ok'/.test(webhook) && /handleUpdate\(\$update\)/.test(webhook), 'webhook must accept update and pass to handler')
assert(/function\s+testai_sanitize_telegram_html\s*\(/m.test(sanitize), 'telegram sanitizer must exist')

process.stdout.write('OK\n')
