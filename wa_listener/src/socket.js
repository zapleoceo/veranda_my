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
const {
  sendQrToTelegram,
  clearQrMessage,
  sendStatusToTelegram,
  sendErrorToTelegram,
} = require('./telegram');

// Track whether we've ever been connected this process lifetime. The very
// first "Connected" right after boot is noisy and useless; we only notify
// Telegram on real reconnects (state transition from down → up).
let everConnected = false;

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
        // Tell the operator a fresh QR is up — common case: WA logged the
        // session out and a phone re-scan is needed.
        await sendStatusToTelegram('требуется пересканировать QR (см. сообщение выше)');
      }

      if (connection === 'close') {
        const wasUp = isConnected;
        isConnected = false;
        connectingInProgress = false;
        const code = lastDisconnect?.error?.output?.statusCode ?? 0;
        const reason = lastDisconnect?.error?.message || lastDisconnect?.error?.output?.payload?.message || '';
        const loggedOut = code === DisconnectReason.loggedOut;
        console.log('[wa] Connection closed, code=' + code + ', loggedOut=' + loggedOut);

        if (loggedOut) {
          try { fs.rmSync(AUTH_DIR, { recursive: true, force: true }); } catch {}
          fs.mkdirSync(AUTH_DIR, { recursive: true });
          await sendErrorToTelegram(
            'session logged out by WhatsApp — auth wiped, ждём новый QR'
          );
        } else if (wasUp) {
          // Only notify on real disconnects, not the boot-time "close" that
          // happens during the first connection attempt.
          await sendStatusToTelegram(
            `disconnected (code=${code}${reason ? ', ' + reason : ''}), реконнект через 3с`
          );
        }
        setTimeout(startSock, 3000);
      } else if (connection === 'open') {
        const wasReconnect = everConnected;
        isConnected = true;
        everConnected = true;
        connectingInProgress = false;
        console.log('[wa] Connected');
        await clearQrMessage();
        // Ping operator on every connection. Sends `connected ✅` on first
        // boot (so a restart after deploy shows up), `reconnected ✅` after
        // a real reconnect. 60s dedup in sendStatusToTelegram swallows the
        // case where Baileys flaps multiple times in quick succession.
        await sendStatusToTelegram(wasReconnect ? 'reconnected ✅' : 'connected ✅');
      }
    });
  } catch (e) {
    connectingInProgress = false;
    console.error('[wa] startSock error:', e.message);
    await sendErrorToTelegram('startSock failed: ' + e.message);
    setTimeout(startSock, 5000);
  }
}

function getSocket() { return sock; }
function isReady() { return isConnected && sock !== null; }

module.exports = { startSock, getSocket, isReady };
