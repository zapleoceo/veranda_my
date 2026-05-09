const assert = (cond, msg) => {
  if (!cond) {
    process.stderr.write(`FAIL: ${msg}\n`)
    process.exit(1)
  }
}

const fs = require('fs')
const src = fs.readFileSync('/workspace/tr4/api_booking.php', 'utf8')

assert(/poster_table_id/.test(src), 'submit_booking must accept poster_table_id')
assert(/tableSettingsByHall/.test(src), 'submit_booking must resolve scheme table number via tableSettingsByHall')
assert(/\$tableNum\s*=/.test(src), 'submit_booking must assign $tableNum')

process.stdout.write('OK\n')

