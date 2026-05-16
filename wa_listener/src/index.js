'use strict';

require('./config'); // loads .env and webcrypto polyfill
const { startSock } = require('./socket');
const { startServer } = require('./server');

startServer();

startSock().catch((e) => {
  console.error('[wa] Fatal:', e.message);
  process.exit(1);
});
