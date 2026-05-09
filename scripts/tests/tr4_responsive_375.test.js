const assert = (cond, msg) => {
  if (!cond) {
    process.stderr.write(`FAIL: ${msg}\n`)
    process.exit(1)
  }
}

const fs = require('fs')
const css = fs.readFileSync('/workspace/tr3/assets/layout.css', 'utf8')
const js = fs.readFileSync('/workspace/tr3/assets/app.js', 'utf8')

assert(/@media\s*\(max-width:\s*640px\)\s*\{[\s\S]*?\.map-shell\s*\{[\s\S]*?padding:\s*0[\s\S]*?\}[\s\S]*?\}/m.test(css), 'mobile map-shell must have zero padding to allow fit at 375px')
assert(/zoom(Was)?Manual/.test(js), 'app.js must track whether zoom was changed manually')
assert(/addEventListener\('resize'[\s\S]*applyMapZoom\(\s*getInitialZoomPct\(\)/m.test(js), 'resize must re-fit map zoom when in auto-fit mode')

process.stdout.write('OK\n')

