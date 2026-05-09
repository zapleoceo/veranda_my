const assert = (cond, msg) => {
  if (!cond) {
    process.stderr.write(`FAIL: ${msg}\n`)
    process.exit(1)
  }
}

const fs = require('fs')
const app = fs.readFileSync('/workspace/tr3/assets/app.js', 'utf8')
const index = fs.readFileSync('/workspace/tr3/index.php', 'utf8')

assert(!app.includes('Cinema tables') && !app.includes('Veranda tables'), 'app.js must not contain hardcoded hall toggle labels')
assert(!app.includes('Сейчас недоступны настройки столов'), 'app.js must not contain hardcoded RU system modal text')
assert(!index.includes('Cinema tables'), 'index.php must not contain hardcoded Cinema tables')
assert(!index.includes('Prev month') && !index.includes('Next month'), 'index.php must not contain hardcoded month nav aria labels')
assert(!index.includes('Схема столов ресторана'), 'index.php must not contain hardcoded RU map aria-label')

process.stdout.write('OK\n')

