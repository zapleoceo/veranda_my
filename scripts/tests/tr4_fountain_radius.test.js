const assert = (cond, msg) => {
  if (!cond) {
    process.stderr.write(`FAIL: ${msg}\n`)
    process.exit(1)
  }
}

const fs = require('fs')
const js = fs.readFileSync('/workspace/tr4/assets/app.js', 'utf8')

assert(js.includes('<circle cx="32" cy="44" r="35"'), 'fountain blue circle radius must be 35')
process.stdout.write('OK\n')
