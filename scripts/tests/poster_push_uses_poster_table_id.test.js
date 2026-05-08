const assert = (cond, msg) => {
  if (!cond) {
    process.stderr.write(`FAIL: ${msg}\n`)
    process.exit(1)
  }
}

const fs = require('fs')
const php = fs.readFileSync('/workspace/src/classes/PosterReservationHelper.php', 'utf8')
assert(php.includes("$rowPosterTableId = (int)($row['poster_table_id'] ?? 0);"), 'PosterReservationHelper must read poster_table_id from DB row')
assert(php.includes('if ($rowPosterTableId > 0) {') && php.includes('$tableId = $rowPosterTableId;'), 'PosterReservationHelper must prefer poster_table_id when present')
process.stdout.write('OK\\n')
