const assert = (cond, msg) => {
  if (!cond) {
    process.stderr.write(`FAIL: ${msg}\n`)
    process.exit(1)
  }
}

const fs = require('fs')
const js = fs.readFileSync('/workspace/tr4/assets/app.js', 'utf8')

assert(/hallId === 2 && type === 'fountain' && table10Px/.test(js), 'app.js must keep special-case placement for fountain near table 10')
assert(/table10Px\.left\s*-\s*5\s*-\s*sizePx/.test(js), 'fountain must be placed 5px to the left of table 10')
assert(!/table10Px\.left\s*\+\s*table10Px\.w/.test(js), 'fountain must not be placed to the right of table 10')

process.stdout.write('OK\n')

