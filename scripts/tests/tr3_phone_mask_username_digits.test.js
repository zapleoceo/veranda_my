const assert = (cond, msg) => {
  if (!cond) {
    process.stderr.write(`FAIL: ${msg}\n`)
    process.exit(1)
  }
}

const fs = require('fs')
const js = fs.readFileSync('/workspace/tr3/assets/app.js', 'utf8')

assert(/const phoneDigits = \(raw\) =>[^;]*split\(['"]\|['"]\)\[0\]/m.test(js), 'phoneDigits must ignore suffix after "|" (telegram username)')
assert(/const getPhoneE164 = \(\) =>[\s\S]*phoneDigits\(String\(reqPhone\.value \|\| ''\)\.split\(['"]\|['"]\)\[0\]/m.test(js), 'getPhoneE164 must extract digits only from phone part before "|"')

process.stdout.write('OK\n')

