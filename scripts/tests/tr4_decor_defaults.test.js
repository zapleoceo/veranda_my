const assert = (cond, msg) => {
  if (!cond) {
    process.stderr.write(`FAIL: ${msg}\n`)
    process.exit(1)
  }
}

const fs = require('fs')
const src = fs.readFileSync('/workspace/tr4/assets/app.js', 'utf8')

assert(src.includes("hallId === 2 && type === 'fountain'") && src.includes("el.id = 'fountainEl'"), 'fountain in hall 2 must use fixed element id (no anchor-table math)')
assert(src.includes("forceGrassBottom") && src.includes("'canvas_bottom'"), 'grass must use canvas_bottom mode')
assert(/wh\s*=\s*0\.528/.test(src) || /0\.528/.test(src), 'grass height must be increased (0.528) to sit higher')

process.stdout.write('OK\n')
