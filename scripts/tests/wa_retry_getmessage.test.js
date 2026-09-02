// Регрессия: WhatsApp-мост обязан обслуживать retry-запросы.
//
// Если получатель не смог расшифровать сообщение, его телефон показывает
// «Ожидание сообщения» и просит переслать. Baileys обслуживает это через
// колбэк getMessage; без него подставляется дефолт `async () => undefined`,
// и сообщение зависает у получателя навсегда. Тест держит проводку на месте.

process.env.WA_MSG_STORE_TTL_MS = '60'
process.env.WA_MSG_STORE_MAX = '3'

const fs = require('fs')
const path = require('path')

const assert = (cond, msg) => {
  if (!cond) {
    process.stderr.write(`FAIL: ${msg}\n`)
    process.exit(1)
  }
}

const WA = path.join(__dirname, '..', '..', 'wa_listener', 'src')
const { remember, rememberSent, lookup, size } = require(path.join(WA, 'msgStore.js'))

// ─── хранилище ──────────────────────────────────────────────────────────────
remember('ID1', { extendedTextMessage: { text: 'hello' } })
assert(lookup({ id: 'ID1' }).extendedTextMessage.text === 'hello', 'remember -> lookup returns content')
assert(lookup({ id: 'MISSING' }) === undefined, 'unknown id -> undefined')
assert(lookup(null) === undefined, 'lookup(null) must not throw')
assert(lookup({}) === undefined, 'lookup without id -> undefined')

assert(rememberSent({ key: { id: 'ID2' }, message: { conversation: 'x' } }) === true, 'rememberSent stores')
assert(lookup({ id: 'ID2' }).conversation === 'x', 'rememberSent -> lookup')
assert(rememberSent(null) === false, 'rememberSent(null) -> false')
assert(rememberSent({ key: { id: 'ID3' } }) === false, 'sendMessage result without message -> false')

// авторитетный ответ библиотеки должен перекрывать предзапись
remember('ID4', { extendedTextMessage: { text: 'pre' } })
rememberSent({ key: { id: 'ID4' }, message: { extendedTextMessage: { text: 'real' } } })
assert(lookup({ id: 'ID4' }).extendedTextMessage.text === 'real', 'authoritative content overwrites pre-store')

// лимит записей
for (let i = 1; i <= 6; i++) remember('K' + i, { conversation: String(i) })
assert(size() <= 3, 'store respects WA_MSG_STORE_MAX')
assert(lookup({ id: 'K6' }) !== undefined, 'newest entry kept')

// ─── проводка в мосте ───────────────────────────────────────────────────────
const socketSrc = fs.readFileSync(path.join(WA, 'socket.js'), 'utf8')
assert(socketSrc.includes('getMessage: async (key) =>'), 'socket.js must pass getMessage into makeWASocket')
assert(socketSrc.includes('lookup(key)'), 'getMessage must resolve through msgStore.lookup')
assert(/require\('\.\/msgStore'\)/.test(socketSrc), 'socket.js must import msgStore')

const serverSrc = fs.readFileSync(path.join(WA, 'server.js'), 'utf8')
assert(/generateMessageID\(\)/.test(serverSrc), 'server.js must pre-generate a messageId')
assert(/\{\s*messageId\s*\}/.test(serverSrc), 'server.js must pass messageId to sendMessage')
assert(/rememberSent\(sent\)/.test(serverSrc), 'server.js must store the sent message')
assert(/remember\(messageId,\s*\{\s*extendedTextMessage/.test(serverSrc), 'server.js must pre-store text content for the ACK-timeout path')

// TTL проверяем последней — она асинхронная
setTimeout(() => {
  assert(lookup({ id: 'K6' }) === undefined, 'entries expire after TTL')
  process.stdout.write('OK\n')
}, 120)
