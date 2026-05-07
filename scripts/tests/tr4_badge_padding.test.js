const assert = (cond, msg) => {
  if (!cond) {
    process.stderr.write(`FAIL: ${msg}\n`)
    process.exit(1)
  }
}

const fs = require('fs')
const css = fs.readFileSync('/workspace/tr4/assets/layout.css', 'utf8')

assert(/\.table\.is-dyn\s+\.table-badge\s*\{[\s\S]*?padding:\s*2px\s+2px;[\s\S]*?\}/m.test(css), 'table badge padding must be 2px 2px')

process.stdout.write('OK\n')

