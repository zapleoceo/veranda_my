const assert = (cond, msg) => {
  if (!cond) {
    process.stderr.write(`FAIL: ${msg}\n`)
    process.exit(1)
  }
}

const fs = require('fs')
const src = fs.readFileSync('/workspace/tr4/assets/app.js', 'utf8')

assert(/dataset\.posterTableId\s*=/.test(src), 'tables must keep dataset.posterTableId (Poster table_id)')
assert(/dataset\.tableNum\s*=/.test(src) || /dataset\.schemeNum\s*=/.test(src), 'tables must store human table number in dataset.tableNum')
assert(/openRequestForm\(\{\s*tableNum:\s*String\(table\.dataset\.tableNum/.test(src) || /table\.dataset\.tableNum/.test(src), 'openRequestForm must use dataset.tableNum (scheme/display num), not posterTableId')

process.stdout.write('OK\n')

