const assert = (cond, msg) => {
  if (!cond) {
    process.stderr.write(`FAIL: ${msg}\n`)
    process.exit(1)
  }
}

const fs = require('fs')
const css = fs.readFileSync('/workspace/tr4/assets/layout.css', 'utf8')

assert(/\.table\.is-dyn\s+\.table-badge\s*\{[\s\S]*?top:\s*2px;[\s\S]*?left:\s*2px;[\s\S]*?padding:\s*2px\s+2px;[\s\S]*?border-radius:\s*5px;[\s\S]*?\}/m.test(css), 'table badge must have top/left 2px, padding 2px, radius 5px')
const m = css.match(/\.table\.is-dyn\s+\.table-badge\s*\{([\s\S]*?)\}/m)
assert(!!m, 'table badge rule must exist')
assert(!String(m[1] || '').includes('box-shadow'), 'table badge must not have box-shadow')

process.stdout.write('OK\n')
