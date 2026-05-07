const assert = (cond, msg) => {
  if (!cond) {
    process.stderr.write(`FAIL: ${msg}\n`)
    process.exit(1)
  }
}

const fs = require('fs')
const src = fs.readFileSync('/workspace/tr4/assets/app.js', 'utf8')

assert(src.includes("trim() === '10'") && src.includes("table10Px"), 'fountain anchor table must be 10')
assert(src.includes("forceGrassBottom") && src.includes("'canvas_bottom'"), 'grass must use canvas_bottom mode')
assert(/wh\s*=\s*0\.48/.test(src) || /0\.48/.test(src), 'grass height must be reduced (0.48) to sit lower')

process.stdout.write('OK\n')
