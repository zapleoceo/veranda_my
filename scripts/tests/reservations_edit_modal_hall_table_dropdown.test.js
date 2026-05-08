const assert = (cond, msg) => {
  if (!cond) {
    process.stderr.write(`FAIL: ${msg}\n`)
    process.exit(1)
  }
}

const fs = require('fs')

const view = fs.readFileSync('/workspace/reservations/view.php', 'utf8')
assert(/id="editResHallId"/.test(view), 'editResModal must contain #editResHallId')
assert(/id="editResTableNum"/.test(view), 'editResModal must keep #editResTableNum element')
assert(/<select[^>]*(id="editResTableNum"[^>]*name="poster_table_id"|name="poster_table_id"[^>]*id="editResTableNum")/i.test(view), '#editResTableNum must post poster_table_id')

const index = fs.readFileSync('/workspace/reservations/index.php', 'utf8')
assert(/ajax === 'res_halls_list'/.test(index), 'reservations endpoint must implement ajax=res_halls_list')
assert(/ajax === 'res_hall_tables_list'/.test(index), 'reservations endpoint must implement ajax=res_hall_tables_list')
assert(/ajax === 'save_res'[\s\S]*updates\['poster_table_id'\]/m.test(index), 'save_res must persist poster_table_id when provided')
assert(/ajax === 'save_res'[\s\S]*updates\['hall_id'\]/m.test(index), 'save_res must persist hall_id when provided')
assert(/ajax === 'save_res'[\s\S]*updates\['table_num'\]\s*=\s*\$label/m.test(index), 'save_res must derive table_num label from table selection')

process.stdout.write('OK\n')
