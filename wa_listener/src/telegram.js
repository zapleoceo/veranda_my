'use strict';

const https = require('node:https');
const fs = require('node:fs');
const os = require('node:os');
const QRCode = require('qrcode');
const { TG_BOT_TOKEN, QR_TG_CHAT_ID, QR_STATE_FILE } = require('./config');

// Hostname + first non-internal IPv4. Cached once per process — interfaces
// don't change during the listener's lifetime, and the operator wants to
// instantly tell which host the notification came from when several
// veranda-like deployments coexist.
const _serverTag = (() => {
  const host = os.hostname();
  const ifaces = os.networkInterfaces();
  const isPublic = (addr) =>
    !addr.startsWith('10.') &&
    !addr.startsWith('192.168.') &&
    !addr.startsWith('127.') &&
    !addr.startsWith('169.254.') &&
    !addr.startsWith('100.') &&
    !/^172\.(1[6-9]|2\d|3[01])\./.test(addr);

  const all = [];
  for (const name of Object.keys(ifaces)) {
    for (const iface of ifaces[name] || []) {
      if (iface.family !== 'IPv4' || iface.internal) continue;
      all.push(iface.address);
    }
  }
  const pub = all.find(isPublic);
  const ip  = pub || all[0] || 'unknown';
  return `${ip} (${host})`;
})();

function loadQrState() {
  try { return JSON.parse(fs.readFileSync(QR_STATE_FILE, 'utf8')); } catch { return {}; }
}

function saveQrState(state) {
  try { fs.writeFileSync(QR_STATE_FILE, JSON.stringify(state, null, 2)); } catch {}
}

function tgPost(method, data) {
  return new Promise((resolve) => {
    const body = JSON.stringify(data);
    const req = https.request(
      {
        hostname: 'api.telegram.org',
        path: `/bot${TG_BOT_TOKEN}/${method}`,
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(body) },
      },
      (res) => {
        let raw = '';
        res.on('data', (c) => { raw += c; });
        res.on('end', () => { try { resolve(JSON.parse(raw)); } catch { resolve({}); } });
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

    const body = Buffer.concat([Buffer.from(partHead), pngBuf, Buffer.from(`\r\n--${boundary}--\r\n`)]);
    const req = https.request(
      {
        hostname: 'api.telegram.org',
        path: `/bot${TG_BOT_TOKEN}/sendPhoto`,
        method: 'POST',
        headers: { 'Content-Type': `multipart/form-data; boundary=${boundary}`, 'Content-Length': body.length },
      },
      (res) => {
        let raw = '';
        res.on('data', (c) => { raw += c; });
        res.on('end', () => { try { resolve(JSON.parse(raw)); } catch { resolve({}); } });
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
    const res = await tgSendPhoto(
      QR_TG_CHAT_ID,
      pngBuf,
      `WhatsApp QR — отсканируй телефоном\n📡 ${_serverTag}`
    );
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

// Deduplicate spammy status messages — only the first transition per minute is
// forwarded. The full sequence still shows up in pm2 logs.
const _statusDedup = new Map();
const STATUS_DEDUP_MS = 60_000;

async function sendStatusToTelegram(text) {
  if (!TG_BOT_TOKEN || !QR_TG_CHAT_ID || !text) return;
  const now = Date.now();
  const last = _statusDedup.get(text) || 0;
  if (now - last < STATUS_DEDUP_MS) return;
  _statusDedup.set(text, now);
  try {
    await tgPost('sendMessage', {
      chat_id: QR_TG_CHAT_ID,
      text: `🟢 WA: ${text}\n📡 ${_serverTag}`,
      disable_notification: true,
    });
  } catch (e) {
    console.error('[wa] status send error:', e.message);
  }
}

async function sendErrorToTelegram(text) {
  if (!TG_BOT_TOKEN || !QR_TG_CHAT_ID || !text) return;
  try {
    await tgPost('sendMessage', {
      chat_id: QR_TG_CHAT_ID,
      text: `🔴 WA error: ${text}\n📡 ${_serverTag}`.slice(0, 4000),
    });
  } catch (e) {
    console.error('[wa] error send error:', e.message);
  }
}

module.exports = { sendQrToTelegram, clearQrMessage, sendStatusToTelegram, sendErrorToTelegram };
