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

// ─── Подавление шума от кратковременных разрывов ────────────────────────────
//
// Baileys рвёт и поднимает соединение по несколько раз в сутки (code=428/503),
// восстанавливаясь за 3-5 секунд. Раньше каждый такой чих давал ДВА сообщения
// в Telegram — «disconnected» и «reconnected» — и оператор привыкал их
// пролистывать, а значит пропустил бы настоящую аварию.
//
// Логика теперь такая:
//   • разрыв   → молчим и заводим таймер;
//   • вернулось за OUTAGE_ALERT_AFTER_MS → таймер снимаем, не пишем ничего;
//   • не вернулось → пишем «лежит N секунд» — вот это реальная проблема;
//   • после реального алерта пишем и о восстановлении (иначе непонятно, чем
//     кончилось);
//   • если рвётся часто, но каждый раз поднимается — одно сообщение о
//     нестабильности, не чаще раза в час.
const OUTAGE_ALERT_AFTER_MS  = Number(process.env.WA_OUTAGE_ALERT_AFTER_MS || 90_000);
const FLAP_WINDOW_MS         = Number(process.env.WA_FLAP_WINDOW_MS || 3_600_000);
const FLAP_THRESHOLD         = Number(process.env.WA_FLAP_THRESHOLD || 5);
const FLAP_ALERT_COOLDOWN_MS = Number(process.env.WA_FLAP_COOLDOWN_MS || 3_600_000);

let outageTimer     = null;  // таймер отложенного алерта
let outageStartedAt = 0;     // когда упало
let outageAlerted   = false; // сообщили ли уже об аварии
let disconnectTimes = [];    // отметки разрывов для детекта «дёргается»
let lastFlapAlertAt = 0;

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
          // НЕ пишем сразу. Сообщение уйдёт только если связь не вернётся
          // за OUTAGE_ALERT_AFTER_MS — тогда это уже не «чих», а авария.
          const now = Date.now();

          if (outageTimer === null) {
            outageStartedAt = now;
            outageTimer = setTimeout(async () => {
              outageAlerted = true;
              outageTimer = null;
              const downSec = Math.round((Date.now() - outageStartedAt) / 1000);
              await sendErrorToTelegram(
                `WA не поднимается уже ${downSec}с (code=${code}${reason ? ', ' + reason : ''})`
              );
            }, OUTAGE_ALERT_AFTER_MS);
            if (typeof outageTimer.unref === 'function') outageTimer.unref();
          }

          // Отдельный сигнал: соединение возвращается, но рвётся подозрительно
          // часто. Само по себе каждое падение безобидно, а вот их частота —
          // уже симптом (сеть, лимиты WhatsApp, память).
          disconnectTimes.push(now);
          disconnectTimes = disconnectTimes.filter((t) => now - t <= FLAP_WINDOW_MS);
          if (
            disconnectTimes.length >= FLAP_THRESHOLD &&
            now - lastFlapAlertAt >= FLAP_ALERT_COOLDOWN_MS
          ) {
            lastFlapAlertAt = now;
            const mins = Math.round(FLAP_WINDOW_MS / 60000);
            await sendErrorToTelegram(
              `WA нестабилен: ${disconnectTimes.length} разрывов за ${mins} мин ` +
              `(каждый раз поднимался, последний code=${code})`
            );
          }
        }
        setTimeout(startSock, 3000);
      } else if (connection === 'open') {
        const wasReconnect = everConnected;
        isConnected = true;
        everConnected = true;
        connectingInProgress = false;
        console.log('[wa] Connected');
        await clearQrMessage();

        // Снимаем отложенный алерт: связь вернулась раньше, чем он сработал.
        if (outageTimer !== null) {
          clearTimeout(outageTimer);
          outageTimer = null;
        }

        if (!wasReconnect) {
          // Первый коннект после старта процесса — полезно видеть (деплой,
          // перезапуск, ребут сервера).
          await sendStatusToTelegram('connected ✅');
        } else if (outageAlerted) {
          // Об аварии сообщали — обязаны сообщить и чем кончилось.
          const downSec = Math.round((Date.now() - outageStartedAt) / 1000);
          outageAlerted = false;
          await sendStatusToTelegram(`reconnected ✅ после ${downSec}с простоя`);
        }
        // Иначе — обычный короткий разрыв, о котором никто не узнал. Молчим.
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
