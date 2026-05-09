const assert = (cond, msg) => {
  if (!cond) {
    process.stderr.write(`FAIL: ${msg}\n`)
    process.exit(1)
  }
}

const fs = require('fs')
const src = fs.readFileSync('/workspace/tr4/assets/app.js', 'utf8')

assert(/dataset\.posterTableId\s*=/.test(src), 'tables must keep dataset.posterTableId (Poster table_id)')
assert(/dataset\.tableLabel\s*=/.test(src), 'tables must store human label in dataset.tableLabel')
assert(/table\.dataset\.tableLabel/.test(src), 'click flow must read dataset.tableLabel')

process.stdout.write('OK\n')
