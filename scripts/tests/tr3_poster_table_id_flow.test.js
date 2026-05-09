const assert = (cond, msg) => {
  if (!cond) {
    process.stderr.write(`FAIL: ${msg}\n`)
    process.exit(1)
  }
}

const fs = require('fs')

const db = fs.readFileSync('/workspace/src/classes/Database.php', 'utf8')
assert(/createReservationsTable\s*\(/.test(db), 'Database.php must define createReservationsTable()')
assert(/poster_table_id/.test(db), 'reservations table must include poster_table_id column')

const apiBooking = fs.readFileSync('/workspace/tr3/api_booking.php', 'utf8')
assert(/poster_table_id/.test(apiBooking), 'submit_booking must accept/store poster_table_id')

const helper = fs.readFileSync('/workspace/src/classes/PosterReservationHelper.php', 'utf8')
assert(/poster_table_id/.test(helper), 'PosterReservationHelper must use poster_table_id when present')
assert(/row\['poster_table_id'\]/.test(helper) || /row\["poster_table_id"\]/.test(helper), 'PosterReservationHelper must read row[poster_table_id]')

process.stdout.write('OK\n')

