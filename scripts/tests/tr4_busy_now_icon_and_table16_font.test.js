const assert = (cond, msg) => {
  if (!cond) {
    process.stderr.write(`FAIL: ${msg}\n`)
    process.exit(1)
  }
}

const fs = require('fs')
const css = fs.readFileSync('/workspace/tr4/assets/layout.css', 'utf8')
const js = fs.readFileSync('/workspace/tr4/assets/app.js', 'utf8')

assert(css.includes('#mapTablesMain > button:nth-child(16) > span > span.num') && /font-size:\s*0\.7rem;/.test(css), 'table 16 num must be 0.7rem')
assert(css.includes('#mapTablesMain > button:nth-child(16) > span > span.cap') && /font-size:\s*0\.6rem;/.test(css), 'table 16 cap must be 0.6rem')

assert(/\.table\.occupied-now::after\s*\{[\s\S]*content:\s*['"]🚫['"];/m.test(css), 'occupied-now tables must render 🚫 icon via ::after')
assert(!js.includes("t('busy_now'") && !js.includes('t("busy_now"'), 'app.js must not render Busy now label')

process.stdout.write('OK\n')

