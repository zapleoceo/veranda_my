const assert = (cond, msg) => {
  if (!cond) {
    process.stderr.write(`FAIL: ${msg}\n`)
    process.exit(1)
  }
}

const fs = require('fs')
const html = fs.readFileSync('/workspace/testai/index.php', 'utf8')
const api = fs.readFileSync('/workspace/testai/api.php', 'utf8')

assert(/btnDaily/.test(html), 'index must have daily summary button')
assert(/ajax=summary/.test(html) || /mkUrl\('summary'/.test(html), 'index must call api summary endpoint')
assert(/ajax=stats/.test(html) || /mkUrl\('stats'/.test(html), 'index must call api stats endpoint')

assert(/if\s*\(\$ajax\s*===\s*'stats'\)/.test(api), 'api must support stats endpoint')
assert(/if\s*\(\$ajax\s*===\s*'summary'\)/.test(api), 'api must support summary endpoint')

process.stdout.write('OK\n')

