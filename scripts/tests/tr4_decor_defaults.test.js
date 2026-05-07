const assert = (cond, msg) => {
  if (!cond) {
    process.stderr.write(`FAIL: ${msg}\n`)
    process.exit(1)
  }
}

const fs = require('fs')
const src = fs.readFileSync('/workspace/tr4/assets/app.js', 'utf8')

assert(src.includes("String(it.schemeNum || '').trim() === '10'") || src.includes("String(it.label || '').trim() === '10'"), 'fountain anchor table must be 10')
assert(src.includes("forceGrassBottom") && src.includes("'canvas_bottom'"), 'grass must use canvas_bottom mode')
assert(/wh\s*=\s*0\.55/.test(src) || /0\.55/.test(src), 'grass height must be reduced (0.55) to sit lower')

process.stdout.write('OK\n')

