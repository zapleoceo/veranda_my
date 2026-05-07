const assert = (cond, msg) => {
  if (!cond) {
    process.stderr.write(`FAIL: ${msg}\n`)
    process.exit(1)
  }
}

const fs = require('fs')
const css = fs.readFileSync('/workspace/tr4/assets/layout.css', 'utf8')

const rule = css.match(/\.table\.is-dyn\s+\.table-badge\s*\{[\s\S]*?\}/m)
assert(rule && rule[0], 'table-badge rule must exist')
assert(/padding:\s*2px\s+2px;/.test(rule[0]), 'table badge padding must be 2px 2px')
assert(/top:\s*2px;/.test(rule[0]) && /left:\s*2px;/.test(rule[0]), 'table badge outer offsets must be 2px')
assert(!/box-shadow:/.test(rule[0]), 'table badge must not have box-shadow')
assert(/width:\s*fit-content;/.test(rule[0]), 'table badge width must fit content')

process.stdout.write('OK\n')
