const assert = (cond, msg) => {
  if (!cond) {
    process.stderr.write(`FAIL: ${msg}\n`)
    process.exit(1)
  }
}

const fs = require('fs')
const css = fs.readFileSync('/workspace/tr4/assets/layout.css', 'utf8')
const js = fs.readFileSync('/workspace/tr4/assets/app.js', 'utf8')

assert(!css.includes('#mapTablesMain > button:nth-child(16) > span > span.num'), 'table 16 font-size nth-child hack must be removed')
assert(!css.includes('#mapTablesMain > button:nth-child(16) > span > span.cap'), 'table 16 cap font-size nth-child hack must be removed')

const iconRule = css.match(/\.table\.occupied-now::after\s*\{([\s\S]*?)\}/m)
assert(!!iconRule, 'occupied-now ::after rule must exist')
assert(/content:\s*["']🚫["']\s*;/.test(iconRule[1] || ''), 'occupied-now tables must render 🚫 icon via ::after')
assert(/top:\s*2px\s*;/.test(iconRule[1] || '') && /right:\s*2px\s*;/.test(iconRule[1] || ''), '🚫 must have 2px top/right offsets')
assert(/font-size:\s*0\.76rem\s*;/.test(iconRule[1] || ''), '🚫 must be 20% smaller (0.76rem)')
assert(/width:\s*1em\s*;/.test(iconRule[1] || '') && /height:\s*1em\s*;/.test(iconRule[1] || ''), '🚫 box must be tight (1em x 1em)')
assert(!js.includes("t('busy_now'") && !js.includes('t("busy_now"'), 'app.js must not render Busy now label')

process.stdout.write('OK\n')
