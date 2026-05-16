'use strict';

const fs = require('node:fs');
const path = require('node:path');

// Webcrypto polyfill for Node 16
if (!globalThis.crypto) {
  globalThis.crypto = require('node:crypto').webcrypto;
}

const envPath = path.join(__dirname, '..', '..', '.env');
if (fs.existsSync(envPath)) {
  for (const line of fs.readFileSync(envPath, 'utf8').split('\n')) {
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

module.exports = {
  BRIDGE_SECRET: process.env.WA_BRIDGE_SECRET || process.env.WA_NODE_SECRET || '',
  HTTP_HOST:     process.env.WA_HTTP_HOST || '127.0.0.1',
  HTTP_PORT:     parseInt(process.env.WA_HTTP_PORT || '3210', 10),
  TG_BOT_TOKEN:  process.env.TELEGRAM_BOT_TOKEN || '',
  QR_TG_CHAT_ID: process.env.WA_QR_TG_CHAT_ID || '',
  AUTH_DIR:      require('node:path').join(__dirname, '..', 'auth'),
  QR_STATE_FILE: require('node:path').join(__dirname, '..', 'qr_state.json'),
};
