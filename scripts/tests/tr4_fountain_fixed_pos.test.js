const assert = (cond, msg) => {
  if (!cond) {
    process.stderr.write(`FAIL: ${msg}\n`)
    process.exit(1)
  }
}

const fs = require('fs')
const css = fs.readFileSync('/workspace/tr4/assets/layout.css', 'utf8')

assert(/#mapDecorMain\s*>\s*div\.fountain\s*\{[\s\S]*width:\s*61px\s*!important\s*;[\s\S]*height:\s*61px\s*!important\s*;[\s\S]*left:\s*611px\s*!important\s*;[\s\S]*top:\s*350px\s*!important\s*;/m.test(css), 'fountain in #mapDecorMain must be 61x61 at left 611 top 350')

process.stdout.write('OK\n')

