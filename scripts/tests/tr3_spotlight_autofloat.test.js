const assert = (cond, msg) => {
  if (!cond) {
    process.stderr.write(`FAIL: ${msg}\n`)
    process.exit(1)
  }
}

const fs = require('fs')
const src = fs.readFileSync('/workspace/tr3/assets/app.js', 'utf8')

assert(/requestAnimationFrame\s*\(\s*tick\s*\)/.test(src), 'TR3 spotlight must have RAF tick loop')
assert(/idleDelay\s*=\s*900/.test(src), 'TR3 spotlight must auto-float after ~900ms idle like Links')
assert(/mapShell\.style\.setProperty\(\s*'--mx'/.test(src), 'TR3 spotlight must set --mx')
assert(/mapShell\.style\.setProperty\(\s*'--my'/.test(src), 'TR3 spotlight must set --my')

process.stdout.write('OK\n')

