const assert = (cond, msg) => {
  if (!cond) {
    process.stderr.write(`FAIL: ${msg}\n`)
    process.exit(1)
  }
}

const fs = require('fs')
const src = fs.readFileSync('/workspace/tr4/api_booking.php', 'utf8')

assert(/poster_table_id/.test(src), 'submit_booking must accept poster_table_id')
assert(/api_resolve_table_label/.test(src), 'submit_booking must resolve table_label via api_resolve_table_label')
assert(/table_label/.test(src), 'submit_booking must store table_label')

process.stdout.write('OK\n')
