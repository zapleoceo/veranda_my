const assert = (cond, msg) => {
  if (!cond) {
    process.stderr.write(`FAIL: ${msg}\n`)
    process.exit(1)
  }
}

const fs = require('fs')
const css = fs.readFileSync('/workspace/tr4/assets/layout.css', 'utf8')
const js = fs.readFileSync('/workspace/tr4/assets/app.js', 'utf8')

const m = css.match(/#mapDecorMain\s*>\s*#fountainEl\s*\{([\s\S]*?)\}/m)
assert(!!m, '#mapDecorMain > #fountainEl rule must exist')
const body = String(m[1] || '')
assert(!/!important/.test(body), 'fountain fixed rule must not use !important')
assert(/left:\s*570px\s*;/.test(body), 'fountain left must be 570px')
assert(/top:\s*331px\s*;/.test(body), 'fountain top must be 331px')
assert(/width:\s*60px\s*;/.test(body), 'fountain width must be 60px')
assert(/height:\s*60px\s*;/.test(body), 'fountain height must be 60px')
assert(/z-index:\s*2\s*;/.test(body), 'fountain z-index must be 2')

const b = js.match(/if\s*\(\s*hallId\s*===\s*2\s*&&\s*type\s*===\s*'fountain'[\s\S]*?\{([\s\S]*?)\n\s*\}/m)
assert(!!b, 'app.js must have a hallId===2 fountain placement branch')
assert(!/\.style\.(left|top|width|height)\s*=/.test(String(b[1] || '')), 'hallId===2 fountain branch must not write inline left/top/width/height')

process.stdout.write('OK\n')
