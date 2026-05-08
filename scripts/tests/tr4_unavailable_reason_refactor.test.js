const assert = (cond, msg) => {
  if (!cond) {
    process.stderr.write(`FAIL: ${msg}\n`)
    process.exit(1)
  }
}

const fs = require('fs')
const src = fs.readFileSync('/workspace/tr4/assets/app.js', 'utf8')

assert(src.includes('const getUnavailableReason'), 'getUnavailableReason must exist')
assert(src.includes('code:'), 'getUnavailableReason must return structured code')
assert(!/===\s*String\(t\('reason_/m.test(src), 'must not compare localized reason strings')
assert(src.includes('occupiedNowIds') && src.includes('table_busy_no_booking'), 'occupiedNowIds must influence unavailability using table_busy_no_booking')

process.stdout.write('OK\n')
