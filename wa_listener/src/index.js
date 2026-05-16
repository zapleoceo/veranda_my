'use strict';

require('./config'); // loads .env and webcrypto polyfill
const { startSock } = require('./socket');
const { startServer } = require('./server');
const { sendErrorToTelegram } = require('./telegram');

startServer();

startSock().catch(async (e) => {
  console.error('[wa] Fatal:', e.message);
  try { await sendErrorToTelegram('FATAL: ' + e.message + ' — process exiting; pm2 will restart'); } catch {}
  process.exit(1);
});

// Last-chance handlers — without these, an unhandled promise rejection
// would crash silently. Telegram gets one line so the operator notices.
process.on('uncaughtException', async (e) => {
  console.error('[wa] uncaughtException:', e.stack || e.message);
  try { await sendErrorToTelegram('uncaughtException: ' + (e.message || String(e))); } catch {}
  process.exit(1);
});

process.on('unhandledRejection', async (reason) => {
  const msg = reason instanceof Error ? (reason.message || String(reason)) : String(reason);
  console.error('[wa] unhandledRejection:', msg);
  try { await sendErrorToTelegram('unhandledRejection: ' + msg); } catch {}
});
