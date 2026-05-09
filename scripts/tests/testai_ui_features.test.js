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

assert(/btnDaily/.test(html), 'index must have daily summary button')
assert(/ajax=summary/.test(html) || /mkUrl\('summary'/.test(html), 'index must call api summary endpoint')
assert(/ajax=stats/.test(html) || /mkUrl\('stats'/.test(html), 'index must call api stats endpoint')
assert(/btnSavePrompt/.test(html) && /get_prompt/.test(html) && /set_prompt/.test(html), 'index must support bot prompt editing')

assert(/if\s*\(\$ajax\s*===\s*'stats'\)/.test(api), 'api must support stats endpoint')
assert(/if\s*\(\$ajax\s*===\s*'summary'\)/.test(api), 'api must support summary endpoint')
assert(/if\s*\(\$ajax\s*===\s*'get_prompt'\)/.test(api), 'api must support get_prompt endpoint')
assert(/if\s*\(\$ajax\s*===\s*'set_prompt'\)/.test(api), 'api must support set_prompt endpoint')
assert(/testai_tg_send_message/.test(webhook), 'webhook must send telegram replies')

process.stdout.write('OK\n')
