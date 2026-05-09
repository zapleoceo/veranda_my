const assert = (cond, msg) => {
  if (!cond) {
    process.stderr.write(`FAIL: ${msg}\n`)
    process.exit(1)
  }
}

const fs = require('fs')
const js = fs.readFileSync('/workspace/tr3/assets/app.js', 'utf8')
const css = fs.readFileSync('/workspace/tr3/assets/layout.css', 'utf8')

assert(/visibilitychange/.test(js) && /document\.hidden/.test(js), 'spotlight must pause on tab hidden and resume on visible')
assert(/cancelAnimationFrame/.test(js), 'spotlight must cancel RAF when paused')
assert(/FRAME_MS/.test(js) || /lastPaintTs/.test(js), 'spotlight must throttle visual updates')

assert(/\.map-shell\.is-paused/.test(css), 'map-shell must support paused state class')
assert(/animation-play-state:\s*paused/.test(css), 'blobs must pause when map-shell is paused')

process.stdout.write('OK\n')

