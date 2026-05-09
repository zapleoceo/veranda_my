const assert = (cond, msg) => {
  if (!cond) {
    process.stderr.write(`FAIL: ${msg}\n`)
    process.exit(1)
  }
}

const fs = require('fs')
const src = fs.readFileSync('/workspace/tr3/api_booking.php', 'utf8')

assert(/table_label/.test(src), 'submit_booking must attempt to store table_label')
assert(/Unknown column 'table_label'/.test(src) || /Unknown column \"table_label\"/.test(src), 'submit_booking must fallback if table_label column is missing')
const inserts = src.match(/INSERT INTO\s+\{\$resTable\}\s*\([\s\S]*?\)\s*VALUES\s*\([\s\S]*?\)/g) || []
assert(inserts.length >= 2, 'submit_booking must contain at least two INSERT statements')
assert(inserts.some((s) => /table_label/.test(s)), 'one INSERT must include table_label')
assert(inserts.some((s) => !/table_label/.test(s)), 'one INSERT must be without table_label (fallback)')

process.stdout.write('OK\n')
