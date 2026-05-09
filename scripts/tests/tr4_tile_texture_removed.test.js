const assert = (cond, msg) => {
  if (!cond) {
    process.stderr.write(`FAIL: ${msg}\n`)
    process.exit(1)
  }
}

const fs = require('fs')
const css = fs.readFileSync('/workspace/tr3/assets/layout.css', 'utf8')
assert(!/gray-tiles-texture\.jpg/.test(css), 'tile-layer must not use gray-tiles-texture.jpg')
process.stdout.write('OK\n')

