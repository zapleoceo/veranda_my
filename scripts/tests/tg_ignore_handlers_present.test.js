const assert = (cond, msg) => {
  if (!cond) {
    process.stderr.write(`FAIL: ${msg}\n`)
    process.exit(1)
  }
}

const fs = require('fs')

const dispatcher = fs.readFileSync('/workspace/webhook_actions/telegram_webhook.php', 'utf8')
assert(/ignore_item/.test(dispatcher) && /ignore_tx/.test(dispatcher), 'dispatcher must accept ignore_item and ignore_tx actions')
assert(/__DIR__\s*\.\s*'\/'\s*\.\s*\$action\s*\.\s*'\.php'/.test(dispatcher), 'dispatcher must include action file routing')

const ignoreItemPath = '/workspace/webhook_actions/ignore_item.php'
const ignoreTxPath = '/workspace/webhook_actions/ignore_tx.php'
assert(fs.existsSync(ignoreItemPath), 'ignore_item.php must exist')
assert(fs.existsSync(ignoreTxPath), 'ignore_tx.php must exist')

const ignoreItem = fs.readFileSync(ignoreItemPath, 'utf8')
const ignoreTx = fs.readFileSync(ignoreTxPath, 'utf8')
assert(/exclude_from_dashboard/.test(ignoreItem), 'ignore_item.php must update exclude_from_dashboard')
assert(/transaction_id/.test(ignoreTx) && /exclude_from_dashboard/.test(ignoreTx), 'ignore_tx.php must update exclude_from_dashboard by transaction_id')

process.stdout.write('OK\n')

