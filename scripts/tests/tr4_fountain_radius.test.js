const assert = (cond, msg) => {
  if (!cond) {
    process.stderr.write(`FAIL: ${msg}\n`)
    process.exit(1)
  }
}

const fs = require('fs')
const js = fs.readFileSync('/workspace/tr4/assets/app.js', 'utf8')

assert(js.includes('<circle cx="32" cy="44" r="25"'), 'fountain blue circle radius must be 25')
process.stdout.write('OK\n')

