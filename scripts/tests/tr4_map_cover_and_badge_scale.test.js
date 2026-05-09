const assert = (cond, msg) => {
  if (!cond) {
    process.stderr.write(`FAIL: ${msg}\n`)
    process.exit(1)
  }
}

const fs = require('fs')
const js = fs.readFileSync('/workspace/tr3/assets/app.js', 'utf8')
const css = fs.readFileSync('/workspace/tr3/assets/layout.css', 'utf8')

assert(!/syncTileLayerSize\s*=\s*\(\)\s*=>/.test(js), 'syncTileLayerSize helper must be removed (no JS sizing hacks)')
const m = js.match(/const\s+getInitialZoomPct\s*=\s*\(\)\s*=>\s*\{([\s\S]*?)\n\s*\};/m)
assert(!!m, 'getInitialZoomPct must exist')
assert(String(m[1] || '').includes('Math.max('), 'getInitialZoomPct must compute cover zoom using Math.max(w/baseW, h/baseH)')
assert(/style\.setProperty\(\s*['"]--tbl-min['"]/.test(js), 'renderHallTables must set --tbl-min for tables')
assert(/setProperty\(\s*['"]--badge-size['"]/.test(js), 'badge size must be set from JS based on canvas size')

assert(!/#mapTablesMain\s*>\s*button:nth-child\(16\)/.test(css), 'nth-child badge font hacks must be removed')
assert(!/\.table\.is-dyn\s*\{[\s\S]*font-size:\s*calc\(var\(--tbl-min\)/m.test(css), '.table.is-dyn must not scale font-size from --tbl-min')
assert(/--badge-size:\s*25px/.test(css), 'default badge size must be 25px')

process.stdout.write('OK\n')
