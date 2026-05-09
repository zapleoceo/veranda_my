const assert = (cond, msg) => {
  if (!cond) {
    process.stderr.write(`FAIL: ${msg}\n`)
    process.exit(1)
  }
}

const fs = require('fs')
const css = fs.readFileSync('/workspace/tr3/assets/layout.css', 'utf8')

const dyn = css.match(/\.table\.is-dyn\s*\{([\s\S]*?)\}/m)
assert(!!dyn, '.table.is-dyn rule must exist')
assert(/box-sizing:\s*border-box\s*;/.test(dyn[1] || ''), 'dynamic tables must use box-sizing: border-box to prevent padding from changing outer size')

process.stdout.write('OK\n')

