const assert = (cond, msg) => {
  if (!cond) {
    process.stderr.write(`FAIL: ${msg}\n`)
    process.exit(1)
  }
}

const fs = require('fs')
const src = fs.readFileSync('/workspace/tr3/assets/app.js', 'utf8')

assert(/forceGrassBottom/.test(src), 'TR3 must keep grass bottom override')
assert(/wh\s*=\s*0\.58/.test(src), 'grass height override must be increased by ~10% (expected wh ≈ 0.58)')

process.stdout.write('OK\n')

