const assert = (cond, msg) => {
  if (!cond) {
    process.stderr.write(`FAIL: ${msg}\n`)
    process.exit(1)
  }
}

const fs = require('fs')
const css = fs.readFileSync('/workspace/tr4/assets/layout.css', 'utf8')

assert(/#mapZoomBox\s*\{[\s\S]*position:\s*absolute\s*;[\s\S]*inset:\s*0\s*;[\s\S]*width:\s*100%\s*;[\s\S]*height:\s*100%\s*;/m.test(css), '#mapZoomBox must fill map-shell (absolute inset 0, 100% x 100%)')
assert(/#mapZoomInner\s*\{[\s\S]*position:\s*absolute\s*;[\s\S]*left:\s*50%\s*;[\s\S]*top:\s*50%\s*;/m.test(css), '#mapZoomInner must be centered (absolute, left/top 50%)')
assert(/#mapZoomInner\s*\{[\s\S]*transform:\s*translate\(-50%,\s*-50%\)\s*scale\(var\(--map-scale\)\)/m.test(css), '#mapZoomInner must translate(-50%,-50%) and scale(--map-scale)')
assert(/\.map\s*\{[\s\S]*width:\s*100%\s*;[\s\S]*height:\s*100%\s*;/m.test(css), '.map must be 100% x 100%')

process.stdout.write('OK\n')
