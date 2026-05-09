const assert = (cond, msg) => {
  if (!cond) {
    process.stderr.write(`FAIL: ${msg}\n`)
    process.exit(1)
  }
}

const fs = require('fs')
const css = fs.readFileSync('/workspace/tr3/assets/layout.css', 'utf8')

assert(/#mapZoomBox\s*\{[\s\S]*position:\s*absolute\s*;[\s\S]*inset:\s*0\s*;[\s\S]*width:\s*100%\s*;[\s\S]*height:\s*100%\s*;/m.test(css), '#mapZoomBox must fill map-shell (absolute inset 0, 100% x 100%)')
assert(/#mapZoomInner\s*\{[\s\S]*position:\s*absolute\s*;[\s\S]*inset:\s*0\s*;/m.test(css), '#mapZoomInner must fill #mapZoomBox (absolute inset 0)')
assert(!/#mapZoomInner\s*\{[\s\S]*transform:\s*scale\(var\(--map-scale\)\)/m.test(css), '#mapZoomInner must not scale (avoid double-scaling with dynamic layout)')
assert(/\.map\s*\{[\s\S]*position:\s*absolute\s*;[\s\S]*left:\s*50%\s*;[\s\S]*top:\s*50%\s*;[\s\S]*width:\s*var\(--map-base-w,\s*820px\)\s*;[\s\S]*height:\s*var\(--map-base-h,\s*620px\)\s*;/m.test(css), '.map must be centered and have base dimensions 820x620')
assert(/\.map\s*\{[\s\S]*transform:\s*translate\(-50%,\s*-50%\)\s*scale\(var\(--map-scale\)\)/m.test(css), '.map must translate(-50%,-50%) and scale(--map-scale)')

process.stdout.write('OK\n')
