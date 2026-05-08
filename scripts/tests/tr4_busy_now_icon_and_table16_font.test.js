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

const iconRule = css.match(/\.table\.occupied-now::after\s*\{([\s\S]*?)\}/m)
assert(!!iconRule, 'occupied-now ::after rule must exist')
assert(/content:\s*["']🚫["']\s*;/.test(iconRule[1] || ''), 'occupied-now tables must render 🚫 icon via ::after')
assert(/top:\s*0\s*;/.test(iconRule[1] || '') && /right:\s*0\s*;/.test(iconRule[1] || ''), '🚫 must be pinned to top/right without offsets')
assert(/width:\s*1em\s*;/.test(iconRule[1] || '') && /height:\s*1em\s*;/.test(iconRule[1] || ''), '🚫 box must be tight (1em x 1em)')
assert(/transform:\s*translate\(/.test(iconRule[1] || ''), '🚫 must be visually pushed into the corner via translate()')
assert(!js.includes("t('busy_now'") && !js.includes('t("busy_now"'), 'app.js must not render Busy now label')

process.stdout.write('OK\n')
