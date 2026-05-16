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
      jsonReply(res, 200, { ok: true, sent: true });
    } catch (e) {
      console.error('[wa] sendMessage error:', e.message);
      jsonReply(res, 500, { ok: false, error: e.message });
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
