const assert = (cond, msg) => {
  if (!cond) {
    process.stderr.write(`FAIL: ${msg}\n`)
    process.exit(1)
  }
}

const fs = require('fs')
const css = fs.readFileSync('/workspace/tr4/assets/layout.css', 'utf8')

assert(/#cinemaTabBtn[^{]*::before\s*\{[\s\S]*conic-gradient/m.test(css), '#cinemaTabBtn must have glowing border (::before with conic-gradient)')
assert(/@keyframes\s+cinemaGlow/m.test(css), 'cinemaGlow keyframes must exist')
const m = css.match(/@keyframes\s+cinemaGlow\s*\{([\s\S]*?)\n\s*\}/m)
assert(!!m, 'cinemaGlow keyframes block must be readable')
assert(!/rotate\(/.test(m[1] || ''), 'cinemaGlow must not rotate the border')

process.stdout.write('OK\n')
