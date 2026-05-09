const assert = (cond, msg) => {
  if (!cond) {
    process.stderr.write(`FAIL: ${msg}\n`)
    process.exit(1)
  }
}

const fs = require('fs')
const css = fs.readFileSync('/workspace/tr3/assets/layout.css', 'utf8')

assert(/\.table\.is-dyn\s+\.table-badge\s*\{[\s\S]*?gap:\s*0px;[\s\S]*?padding:\s*0\.06em\s+0\.06em;[\s\S]*?\}/m.test(css), 'table badge must use gap 0 and padding 0.06em 0.06em')
assert(/#mapTablesMain\s*>\s*button:nth-child\(12\)[\s\S]*padding:/m.test(css), 'special padding rule for mapTablesMain child 12 must exist')

process.stdout.write('OK\n')

