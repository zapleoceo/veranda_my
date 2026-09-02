'use strict';

// Хранилище отправленных сообщений — обслуживает retry-запросы WhatsApp.
//
// Когда получатель не смог расшифровать сообщение (рассинхрон Signal-сессии),
// его телефон показывает «Ожидание сообщения» и шлёт нам retry receipt.
// Baileys обрабатывает его в sendMessagesAgain() (lib/Socket/messages-recv.js):
// вызывает getMessage(key), поднимает новую сессию (assertSessions(..., true))
// и пересылает исходный контент.
//
// Если getMessage не передан в makeWASocket, библиотека берёт свой дефолт
// `async () => undefined` (lib/Defaults/index.js), пишет в лог
// 'recv retry request, but message not available' и НЕ пересылает ничего —
// у получателя сообщение навсегда остаётся как «Ожидание сообщения».
// Именно это и происходило: мост отправлял и забывал.
//
// Держим ровно то, что нужно для ретрая: id -> proto.IMessage. Ретраи
// приходят в пределах минут, так что суток TTL хватает с большим запасом.
// Хранилище in-memory: переживать рестарт незачем — после него у WhatsApp
// уже не будет незакрытых ретраев к нам.

const TTL_MS      = Number(process.env.WA_MSG_STORE_TTL_MS || 24 * 60 * 60 * 1000);
const MAX_ENTRIES = Number(process.env.WA_MSG_STORE_MAX || 2000);

const store = new Map(); // id -> { message, at }

function prune() {
  const now = Date.now();
  for (const [id, rec] of store) {
    if (now - rec.at > TTL_MS) store.delete(id);
  }
  // Map держит порядок вставки — при переполнении снимаем самые старые.
  while (store.size > MAX_ENTRIES) {
    const oldest = store.keys().next();
    if (oldest.done) break;
    store.delete(oldest.value);
  }
}

/** Запомнить контент под конкретным message id. */
function remember(id, message) {
  const key = String(id || '');
  if (!key || !message) return false;
  store.set(key, { message, at: Date.now() });
  prune();
  return true;
}

/** Запомнить результат sock.sendMessage (proto.WebMessageInfo). */
function rememberSent(sent) {
  if (!sent || !sent.key || !sent.message) return false;
  return remember(sent.key.id, sent.message);
}

/** getMessage-колбэк для Baileys: proto.IMessageKey -> proto.IMessage | undefined. */
function lookup(key) {
  const id = key && key.id ? String(key.id) : '';
  if (!id) return undefined;
  const rec = store.get(id);
  if (!rec) return undefined;
  if (Date.now() - rec.at > TTL_MS) {
    store.delete(id);
    return undefined;
  }
  return rec.message;
}

function size() { return store.size; }

module.exports = { remember, rememberSent, lookup, size };
