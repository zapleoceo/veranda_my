const assert = (cond, msg) => {
  if (!cond) {
    process.stderr.write(`FAIL: ${msg}\n`)
    process.exit(1)
  }
}

const fs = require('fs')
const src = fs.readFileSync('/workspace/tr4/api_booking.php', 'utf8')

assert(/\$waText[\s\S]*tg_table'\)[\s\S]*\?\s*\$tableLabel/.test(src), 'WA guest message must use $tableLabel (or fallback)')
assert(/tg_table[\s\S]*tableLabelOut/.test(src), 'TG guest message must use $tableLabelOut')

process.stdout.write('OK\n')
