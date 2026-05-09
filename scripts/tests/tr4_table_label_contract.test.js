const assert = (cond, msg) => {
  if (!cond) {
    process.stderr.write(`FAIL: ${msg}\n`)
    process.exit(1)
  }
}

const fs = require('fs')

const db = fs.readFileSync('/workspace/src/classes/Database.php', 'utf8')
assert(/createReservationsTable\s*\(/.test(db), 'Database must define createReservationsTable')
assert(/table_label/.test(db), 'reservations table must include table_label column')

const tg = fs.readFileSync('/workspace/reservations/ReservationTelegram.php', 'utf8')
assert(/table_label/.test(tg), 'ReservationTelegram must read table_label')

const api = fs.readFileSync('/workspace/tr3/api_poster.php', 'utf8')
assert(/function\s+tr3_api_hall_tables/.test(api), 'TR4 must define hall_tables')
assert(/table_label/.test(api), 'hall_tables must return table_label')

process.stdout.write('OK\n')

