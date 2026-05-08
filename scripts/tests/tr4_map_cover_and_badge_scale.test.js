const assert = (cond, msg) => {
  if (!cond) {
    process.stderr.write(`FAIL: ${msg}\n`)
    process.exit(1)
  }
}

const fs = require('fs')
const js = fs.readFileSync('/workspace/tr4/assets/app.js', 'utf8')
const css = fs.readFileSync('/workspace/tr4/assets/layout.css', 'utf8')

assert(!/syncTileLayerSize\s*=\s*\(\)\s*=>/.test(js), 'syncTileLayerSize helper must be removed (no JS sizing hacks)')
assert(/const\s+getInitialZoomPct\s*=\s*\(\)\s*=>\s*\{[\s\S]*return\s+100;[\s\S]*\}/m.test(js), 'getInitialZoomPct must always return 100 on adaptive (cover mode)')
assert(/style\.setProperty\(\s*['"]--tbl-min['"]/.test(js), 'renderHallTables must set --tbl-min for tables')

assert(!/#mapTablesMain\s*>\s*button:nth-child\(16\)/.test(css), 'nth-child badge font hacks must be removed')
assert(/\.table\.is-dyn\s*\{[\s\S]*font-size:\s*calc\(var\(--tbl-min\)/m.test(css), '.table.is-dyn must scale font-size from --tbl-min')

process.stdout.write('OK\n')

