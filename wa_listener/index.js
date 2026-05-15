'use strict';

// Webcrypto polyfill for Node 16
if (!globalThis.crypto) {
  globalThis.crypto = require('node:crypto').webcrypto;
}

const http = require('node:http');
const https = require('node:https');
const fs = require('node:fs');
const path = require('node:path');

// Load .env from parent directory
const envPath = path.join(__dirname, '..', '.env');
if (fs.existsSync(envPath)) {
  const lines = fs.readFileSync(envPath, 'utf8').split('\n');
  for (const line of lines) {
    const t = line.trim();
    if (!t || t.startsWith('#')) continue;
    const eq = t.indexOf('=');
    if (eq < 0) continue;
    const key = t.slice(0, eq).trim();
    let val = t.slice(eq + 1).trim();
    if ((val.startsWith('"') && val.endsWith('"')) || (val.startsWith("'") && val.endsWith("'"))) {
      val = val.slice(1, -1);
    }
    if (!process.env[key]) process.env[key] = val;
  }
}

const {
  default: makeWASocket,
  useMultiFileAuthState,
  DisconnectReason,
  fetchLatestBaileysVersion,
  makeCacheableSignalKeyStore,
  Browsers,
} = require('@whiskeysockets/baileys');

const pino = require('pino');
const QRCode = require('qrcode');

const BRIDGE_SECRET = process.env.WA_BRIDGE_SECRET || process.env.WA_NODE_SECRET || '';
const HTTP_HOST = process.env.WA_HTTP_HOST || '127.0.0.1';
const HTTP_PORT = parseInt(process.env.WA_HTTP_PORT || '3210', 10);
const TG_BOT_TOKEN = process.env.TELEGRAM_BOT_TOKEN || '';
const QR_TG_CHAT_ID = process.env.WA_QR_TG_CHAT_ID || '';
const AUTH_DIR = path.join(__dirname, 'auth');
const QR_STATE_FILE = path.join(__dirname, 'qr_state.json');

// Silent logger - baileys produces very verbose output
const logger = pino({ level: 'silent' });

let sock = null;
let isConnected = false;
let connectingInProgress = false;

// --- QR state persistence ---
function loadQrState() {
  try {
    return JSON.parse(fs.readFileSync(QR_STATE_FILE, 'utf8'));
  } catch {
    return {};
  }
}

function saveQrState(state) {
  try {
    fs.writeFileSync(QR_STATE_FILE, JSON.stringify(state, null, 2));
  } catch {}
}

// --- Telegram API helpers ---
function tgPost(method, data) {
  return new Promise((resolve) => {
    const body = JSON.stringify(data);
    const req = https.request(
      {
        hostname: 'api.telegram.org',
        path: `/bot${TG_BOT_TOKEN}/${method}`,
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Content-Length': Buffer.byteLength(body),
        },
      },
      (res) => {
        let raw = '';
        res.on('data', (c) => { raw += c; });
        res.on('end', () => {
          try { resolve(JSON.parse(raw)); } catch { resolve({}); }
        });
      }
    );
    req.on('error', () => resolve({}));
    req.setTimeout(10000, () => { req.destroy(); resolve({}); });
    req.write(body);
    req.end();
  });
}

function tgSendPhoto(chatId, pngBuf, caption) {
  return new Promise((resolve) => {
    const boundary = 'WaBoundary' + Date.now().toString(16);
    const partHead = [
      `--${boundary}\r\nContent-Disposition: form-data; name="chat_id"\r\n\r\n${chatId}`,
      `--${boundary}\r\nContent-Disposition: form-data; name="caption"\r\n\r\n${caption}`,
      `--${boundary}\r\nContent-Disposition: form-data; name="photo"; filename="qr.png"\r\nContent-Type: image/png\r\n`,
    ].join('\r\n') + '\r\n';

    const body = Buffer.concat([
      Buffer.from(partHead),
      pngBuf,
      Buffer.from(`\r\n--${boundary}--\r\n`),
    ]);

    const req = https.request(
      {
        hostname: 'api.telegram.org',
        path: `/bot${TG_BOT_TOKEN}/sendPhoto`,
        method: 'POST',
        headers: {
          'Content-Type': `multipart/form-data; boundary=${boundary}`,
          'Content-Length': body.length,
        },
      },
      (res) => {
        let raw = '';
        res.on('data', (c) => { raw += c; });
        res.on('end', () => {
          try { resolve(JSON.parse(raw)); } catch { resolve({}); }
        });
      }
    );
    req.on('error', () => resolve({}));
    req.setTimeout(15000, () => { req.destroy(); resolve({}); });
    req.write(body);
    req.end();
  });
}

async function sendQrToTelegram(qr) {
  if (!TG_BOT_TOKEN || !QR_TG_CHAT_ID) return;
  try {
    const state = loadQrState();
    if (state.message_id) {
      await tgPost('deleteMessage', { chat_id: QR_TG_CHAT_ID, message_id: state.message_id });
    }
    const pngBuf = await QRCode.toBuffer(qr, { type: 'png', scale: 8 });
    const res = await tgSendPhoto(QR_TG_CHAT_ID, pngBuf, 'WhatsApp QR — отсканируй телефоном');
    if (res.ok && res.result && res.result.message_id) {
      saveQrState({ message_id: res.result.message_id });
    }
  } catch (e) {
    console.error('[wa] QR send error:', e.message);
  }
}

async function clearQrMessage() {
  if (!TG_BOT_TOKEN || !QR_TG_CHAT_ID) return;
  try {
    const state = loadQrState();
    if (state.message_id) {
      await tgPost('deleteMessage', { chat_id: QR_TG_CHAT_ID, message_id: state.message_id });
      saveQrState({});
    }
  } catch {}
}

// --- WhatsApp socket ---
async function startSock() {
  if (connectingInProgress) return;
  connectingInProgress = true;

  try {
    if (!fs.existsSync(AUTH_DIR)) fs.mkdirSync(AUTH_DIR, { recursive: true });

    const { state, saveCreds } = await useMultiFileAuthState(AUTH_DIR);

    let version;
    try {
      const fetched = await fetchLatestBaileysVersion();
      version = fetched.version;
    } catch {
      version = [2, 3000, 1015901307];
    }

    sock = makeWASocket({
      version,
      auth: {
        creds: state.creds,
        keys: makeCacheableSignalKeyStore(state.keys, logger),
      },
      browser: Browsers.ubuntu('Chrome'),
      printQRInTerminal: false,
      logger,
      generateHighQualityLinkPreview: false,
      syncFullHistory: false,
      markOnlineOnConnect: false,
      connectTimeoutMs: 60000,
      retryRequestDelayMs: 2000,
    });

    sock.ev.on('creds.update', saveCreds);

    sock.ev.on('connection.update', async (update) => {
      const { connection, lastDisconnect, qr } = update;

      if (qr) {
        console.log('[wa] QR received, sending to Telegram');
        await sendQrToTelegram(qr);
      }

      if (connection === 'close') {
        isConnected = false;
        connectingInProgress = false;
        const code = lastDisconnect && lastDisconnect.error && lastDisconnect.error.output
          ? lastDisconnect.error.output.statusCode
          : 0;
        const loggedOut = code === DisconnectReason.loggedOut;
        console.log('[wa] Connection closed, code=' + code + ', loggedOut=' + loggedOut);
        if (loggedOut) {
          try { fs.rmSync(AUTH_DIR, { recursive: true, force: true }); } catch {}
          fs.mkdirSync(AUTH_DIR, { recursive: true });
        }
        setTimeout(startSock, 3000);
      } else if (connection === 'open') {
        isConnected = true;
        connectingInProgress = false;
        console.log('[wa] Connected');
        await clearQrMessage();
      }
    });

  } catch (e) {
    connectingInProgress = false;
    console.error('[wa] startSock error:', e.message);
    setTimeout(startSock, 5000);
  }
}

// --- HTTP server ---
function jsonReply(res, code, body) {
  const json = JSON.stringify(body);
  res.writeHead(code, { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(json) });
  res.end(json);
}

async function handleSend(req, res) {
  const header = req.headers['x-wa-bridge'] || '';
  if (!BRIDGE_SECRET || header !== BRIDGE_SECRET) {
    return jsonReply(res, 403, { ok: false, error: 'Forbidden' });
  }

  let body = '';
  req.on('data', (c) => { body += c; });
  req.on('end', async () => {
    let data;
    try {
      data = JSON.parse(body);
    } catch {
      return jsonReply(res, 400, { ok: false, error: 'Invalid JSON' });
    }

    const phone = String(data.phone || '').replace(/\D/g, '');
    const text = String(data.text || '');
    const imageUrl = String(data.image_url || '');

    if (!phone || !text) {
      return jsonReply(res, 400, { ok: false, error: 'phone and text required' });
    }

    if (!isConnected || !sock) {
      return jsonReply(res, 503, { ok: false, error: 'WA not connected' });
    }

    const jid = `${phone}@s.whatsapp.net`;
    try {
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

const server = http.createServer((req, res) => {
  if (req.method === 'POST' && req.url === '/send') {
    return handleSend(req, res);
  }
  if (req.method === 'GET') {
    return jsonReply(res, 200, { ok: true, connected: isConnected, version: '1.0.0' });
  }
  jsonReply(res, 404, { ok: false, error: 'Not found' });
});

server.listen(HTTP_PORT, HTTP_HOST, () => {
  console.log('[wa-listener] HTTP on ' + HTTP_HOST + ':' + HTTP_PORT);
});

startSock().catch((e) => {
  console.error('[wa] Fatal:', e.message);
  process.exit(1);
});
