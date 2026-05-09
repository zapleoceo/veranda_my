const assert = (cond, msg) => {
  if (!cond) {
    process.stderr.write(`FAIL: ${msg}\n`)
    process.exit(1)
  }
}

const fs = require('fs')
const css = fs.readFileSync('/workspace/tr3/assets/layout.css', 'utf8')

assert(/\.table\.is-dyn\s+\.table-badge\s*\{[\s\S]*?top:\s*2px;[\s\S]*?left:\s*2px;[\s\S]*?min-width:\s*var\(--badge-size\);[\s\S]*?border-radius:\s*calc\(var\(--badge-size\)\s*\*\s*0\.34\);[\s\S]*?font-size:\s*calc\(var\(--badge-size\)\s*\*\s*0\.56\);[\s\S]*?\}/m.test(css), 'table badge must be based on --badge-size (min-width, radius, font-size)')
const m = css.match(/\.table\.is-dyn\s+\.table-badge\s*\{([\s\S]*?)\}/m)
assert(!!m, 'table badge rule must exist')
assert(!String(m[1] || '').includes('box-shadow'), 'table badge must not have box-shadow')

const tableRule = css.match(/\.table\s*\{([\s\S]*?)\}/m)
assert(!!tableRule, 'table base rule must exist')
assert(String(tableRule[1] || '').includes('border-radius: 10px'), 'square tables must have border-radius 10px')
assert(/\.table\.is-circle\s*\{[\s\S]*?border-radius:\s*999px/m.test(css), 'circle tables must keep full rounding')

process.stdout.write('OK\n')
