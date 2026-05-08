const assert = (cond, msg) => {
  if (!cond) {
    process.stderr.write(`FAIL: ${msg}\n`)
    process.exit(1)
  }
}

const fs = require('fs')
const js = fs.readFileSync('/workspace/tr4/assets/app.js', 'utf8')

const m = js.match(/const checkModalAvailability\s*=\s*\(\)\s*=>\s*\{([\s\S]*?)\n\s*\};/m)
assert(!!m, 'checkModalAvailability must exist')
const body = m[1] || ''

assert(!/getUnavailableReason\(\s*tableNum\s*,/m.test(body), 'checkModalAvailability must not call getUnavailableReason(tableNum, ...)')
assert(/posterTableId/.test(body) && /getUnavailableReason\(\s*posterTableId\b/m.test(body), 'checkModalAvailability must use posterTableId as getUnavailableReason target')

process.stdout.write('OK\n')
