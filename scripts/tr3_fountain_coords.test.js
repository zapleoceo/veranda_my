const assert = (cond, msg) => {
  if (!cond) {
    process.stderr.write(`FAIL: ${msg}\n`)
    process.exit(1)
  }
}

const fs = require('fs')
const css = fs.readFileSync('/workspace/tr3/assets/layout.css', 'utf8')
const php = fs.readFileSync('/workspace/tr3/index.php', 'utf8')

assert(/#fountainEl\s*\{[\s\S]*left:\s*570px\s*;[\s\S]*top:\s*331px\s*;[\s\S]*width:\s*60px\s*;[\s\S]*height:\s*60px\s*;[\s\S]*\}/m.test(css), 'layout.css must set #fountainEl left/top/width/height to 570/331/60/60')
assert(!/id="fountainEl"[^>]*style=/.test(php), 'index.php must not inline style on #fountainEl')
assert(!/!important/.test(css.match(/#fountainEl\s*\{[\s\S]*?\}/m)?.[0] || ''), '#fountainEl rule must not use !important')

process.stdout.write('OK\n')

