const assert = (cond, msg) => {
  if (!cond) {
    process.stderr.write(`FAIL: ${msg}\n`)
    process.exit(1)
  }
}

const fs = require('fs')
const css = fs.readFileSync('/workspace/tr4/assets/layout.css', 'utf8')

const dyn = css.match(/\.table\.is-dyn\s*\{([\s\S]*?)\}/m)
assert(!!dyn, '.table.is-dyn rule must exist')
assert(/border-radius:\s*calc\(var\(--tbl-min\)\s*\*/.test(dyn[1] || ''), 'dynamic tables must scale border-radius from --tbl-min')
assert(/padding:\s*calc\(var\(--tbl-min\)\s*\*/.test(dyn[1] || ''), 'dynamic tables must scale padding from --tbl-min')

const base = css.match(/\.table\s*\{([\s\S]*?)\}/m)
assert(!!base, '.table base rule must exist')
assert(/border-radius:\s*10px\s*;/.test(base[1] || ''), 'base table rule must keep border-radius 10px for non-dyn tables')

process.stdout.write('OK\n')

