const assert = (cond, msg) => {
  if (!cond) {
    process.stderr.write(`FAIL: ${msg}\n`)
    process.exit(1)
  }
}

const fs = require('fs')
const js = fs.readFileSync('/workspace/tr4/assets/app.js', 'utf8')
const css = fs.readFileSync('/workspace/tr4/assets/layout.css', 'utf8')

assert(/<circle cx="32" cy="44" r="20"/.test(js), 'fountain water circle radius must be increased to 20')
assert(/koiOrbit1[\s\S]*translate\(18px\)/m.test(css), 'koi orbit radius 1 must be 18px (inside water)')
assert(/koiOrbit2[\s\S]*translate\(16px\)/m.test(css), 'koi orbit radius 2 must be 16px (inside water)')

process.stdout.write('OK\n')

