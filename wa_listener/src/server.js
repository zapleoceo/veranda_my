'use strict';

const http = require('node:http');
const { BRIDGE_SECRET, HTTP_HOST, HTTP_PORT } = require('./config');
const { getSocket, isReady } = require('./socket');

function jsonReply(res, code, body) {
  const json = JSON.stringify(body);
  res.writeHead(code, { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(json) });
  res.end(json);
}

async function handleSend(req, res) {
  const header = req.headers['x-wa-bridge'] || '';
  if (!BRIDGE_SECRET || header !== BRIDGE_SECRET) {
    return jsonReply(res, 403, { ok: false, error: 'forbidden' });
  }

  let body = '';
  req.on('data', (c) => { body += c; });
  req.on('end', async () => {
    let data;
    try { data = JSON.parse(body); } catch {
      return jsonReply(res, 400, { ok: false, error: 'Invalid JSON' });
    }

    const phone = String(data.phone || '').replace(/\D/g, '');
    const text = String(data.text || '');
    const imageUrl = String(data.image_url || '');

    if (!phone || !text) {
      return jsonReply(res, 400, { ok: false, error: 'phone and text required' });
    }
    if (!isReady()) {
      return jsonReply(res, 503, { ok: false, error: 'WA not connected' });
    }

    const jid = `${phone}@s.whatsapp.net`;
    try {
      const sock = getSocket();
      if (imageUrl) {
        await sock.sendMessage(jid, { image: { url: imageUrl }, caption: text });
      } else {
        await sock.sendMessage(jid, { text });
      }
      console.log('[wa] sendMessage ok: ' + jid);
      jsonReply(res, 200, { ok: true, sent: true });
    } catch (e) {
      const msg = String(e && e.message || e);
      // Baileys' sendMessage resolves only after WhatsApp server-side ACK.
      // For valid numbers the message is already on the wire when ACK
      // arrives slow — we still get a "Timed Out" reject. Empirically the
      // message is delivered: DB rows with return_sent_at=NULL frequently
      // had used_at populated (= user clicked the link in WhatsApp).
      // Treat the timeout as best-effort sent so callers don't false-fail.
      if (/timed?\s*out/i.test(msg)) {
        console.warn('[wa] sendMessage timeout, treating as best-effort sent: ' + jid);
        jsonReply(res, 200, { ok: true, sent: true, ack: false, warning: 'ack_timeout' });
        return;
      }
      console.error('[wa] sendMessage error: ' + jid + ' — ' + msg);
      jsonReply(res, 500, { ok: false, error: msg });
    }
  });
}

function startServer() {
  const server = http.createServer((req, res) => {
    if (req.method === 'POST' && req.url === '/send') return handleSend(req, res);
    if (req.method === 'GET') return jsonReply(res, 200, { ok: true, connected: isReady(), version: '1.0.0' });
    jsonReply(res, 404, { ok: false, error: 'Not found' });
  });

  server.listen(HTTP_PORT, HTTP_HOST, () => {
    console.log('[wa-listener] HTTP on ' + HTTP_HOST + ':' + HTTP_PORT);
  });
}

module.exports = { startServer };
