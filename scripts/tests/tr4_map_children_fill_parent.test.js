const assert = (cond, msg) => {
  if (!cond) {
    process.stderr.write(`FAIL: ${msg}\n`)
    process.exit(1)
  }
}

const fs = require('fs')
const css = fs.readFileSync('/workspace/tr4/assets/layout.css', 'utf8')

const m = css.match(/#mapZoomInner\s*\{([\s\S]*?)\}/m)
assert(!!m, '#mapZoomInner rule must exist')
const body = String(m[1] || '')

assert(/inset:\s*0\s*;/.test(body), '#mapZoomInner must fill parent via inset: 0')
assert(!/left:\s*50%/.test(body) && !/top:\s*50%/.test(body), '#mapZoomInner must not use left/top 50% centering')
assert(!/width:\s*var\(--map-base-w/.test(body) && !/height:\s*var\(--map-base-h/.test(body), '#mapZoomInner must not be hard-sized to base dimensions')
assert(!/translate\(-50%/.test(body), '#mapZoomInner must not use translate(-50%,-50%)')

process.stdout.write('OK\n')

