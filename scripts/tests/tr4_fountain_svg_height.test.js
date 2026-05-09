const assert = (cond, msg) => {
  if (!cond) {
    process.stderr.write(`FAIL: ${msg}\n`)
    process.exit(1)
  }
}

const fs = require('fs')
const css = fs.readFileSync('/workspace/tr3/assets/layout.css', 'utf8')

const m = css.match(/\.fountain svg\s*\{([\s\S]*?)\}/m)
assert(!!m, '.fountain svg rule must exist')
const body = String(m[1] || '')

assert(/height:\s*58%\s*;/.test(body), 'fountain svg height must be 58%')
assert(!/inset:\s*0\s*;/.test(body), 'fountain svg must not use inset: 0 (conflicts with explicit height)')
assert(/top:\s*0\s*;/.test(body) && /left:\s*0\s*;/.test(body), 'fountain svg must be anchored at top-left')

process.stdout.write('OK\n')

