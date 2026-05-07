const assert = (cond, msg) => {
  if (!cond) {
    process.stderr.write(`FAIL: ${msg}\n`)
    process.exit(1)
  }
}

const fs = require('fs')
const css = fs.readFileSync('/workspace/tr4/assets/layout.css', 'utf8')

const rule = css.match(/\.grass-corner-1-7\s*\{[\s\S]*?\}/m)
assert(rule && rule[0], 'grass-corner-1-7 rule must exist')
assert(/repeat-x/.test(rule[0]), 'grass must repeat-x to fill full canvas width')
assert(/left\s+bottom/.test(rule[0]), 'grass must be anchored left bottom')
assert(/auto\s+100%/.test(rule[0]) || /100%\s+100%/.test(rule[0]), 'grass background size must fill height')

process.stdout.write('OK\n')

