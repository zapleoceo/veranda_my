const assert = (cond, msg) => {
  if (!cond) {
    process.stderr.write(`FAIL: ${msg}\n`)
    process.exit(1)
  }
}

const fs = require('fs')
const css = fs.readFileSync('/workspace/tr3/assets/layout.css', 'utf8')
const js = fs.readFileSync('/workspace/tr3/assets/app.js', 'utf8')

assert(/--badge-size:\s*25px/.test(css), 'default badge size must be 25px')
assert(/table-badge[\s\S]*var\(--badge-size\)/.test(css), 'badge styles must depend on --badge-size')
assert(!/table-badge[\s\S]*--tbl-min/.test(css), 'badge styles must not depend on --tbl-min')
assert(/setProperty\('--badge-size'/.test(js), 'JS must set --badge-size based on canvas size')

process.stdout.write('OK\n')

