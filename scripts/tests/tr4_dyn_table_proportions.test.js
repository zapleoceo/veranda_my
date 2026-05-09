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
const body = String(dyn[1] || '')

assert(body.includes('border-radius: calc(var(--tbl-min) * 0.135);'), 'dyn border-radius must match base ratio (10/74 ≈ 0.135)')
assert(body.includes('padding: calc(var(--tbl-min) * 0.081) calc(var(--tbl-min) * 0.054) calc(var(--tbl-min) * 0.108);'), 'dyn padding must match base ratios (6/74, 4/74, 8/74)')

process.stdout.write('OK\n')

