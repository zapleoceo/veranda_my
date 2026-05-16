'use strict';

const fs = require('node:fs');
const pino = require('pino');
const {
  default: makeWASocket,
  useMultiFileAuthState,
  DisconnectReason,
  fetchLatestBaileysVersion,
  makeCacheableSignalKeyStore,
  Browsers,
} = require('@whiskeysockets/baileys');

const { AUTH_DIR } = require('./config');
const { sendQrToTelegram, clearQrMessage } = require('./telegram');

const logger = pino({ level: 'silent' });

let sock = null;
let isConnected = false;
let connectingInProgress = false;

async function startSock() {
  if (connectingInProgress) return;
  connectingInProgress = true;

  try {
    if (!fs.existsSync(AUTH_DIR)) fs.mkdirSync(AUTH_DIR, { recursive: true });

    const { state, saveCreds } = await useMultiFileAuthState(AUTH_DIR);

    let version;
    try {
      ({ version } = await fetchLatestBaileysVersion());
    } catch {
      version = [2, 3000, 1015901307];
    }

    sock = makeWASocket({
      version,
      auth: { creds: state.creds, keys: makeCacheableSignalKeyStore(state.keys, logger) },
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
        console.log('[wa] sending QR to Telegram');
        await sendQrToTelegram(qr);
      }

      if (connection === 'close') {
        isConnected = false;
        connectingInProgress = false;
        const code = lastDisconnect?.error?.output?.statusCode ?? 0;
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

function getSocket() { return sock; }
function isReady() { return isConnected && sock !== null; }

module.exports = { startSock, getSocket, isReady };
