'use strict';

const http = require('node:http');
const { exec } = require('node:child_process');
require('./config'); // loads .env
const { HTTP_HOST, HTTP_PORT } = require('./config');

const CHECK_INTERVAL_MS = 30000;

let lastFailAt = 0;
let failCount = 0;

function checkAlive() {
  return new Promise((resolve) => {
    const req = http.get(
      { hostname: HTTP_HOST, port: HTTP_PORT, path: '/', timeout: 5000 },
      (res) => { res.resume(); resolve(res.statusCode >= 200 && res.statusCode < 500); }
    );
    req.on('error', () => resolve(false));
    req.on('timeout', () => { req.destroy(); resolve(false); });
  });
}

function restartListener() {
  exec('/usr/bin/pm2 restart veranda-wa-listener', (err) => {
    if (err) { console.error('[watchdog] restart failed:', err.message); }
    else { console.log('[watchdog] restarted veranda-wa-listener'); }
  });
}

async function tick() {
  const alive = await checkAlive();
  if (!alive) {
    failCount++;
    const now = Date.now();
    // avoid restart storm: only restart if last restart was >60s ago
    if (now - lastFailAt > 60000) {
      lastFailAt = now;
      console.log('[watchdog] listener unreachable (fail #' + failCount + '), restarting');
      restartListener();
    }
  } else {
    failCount = 0;
  }
  setTimeout(tick, CHECK_INTERVAL_MS);
}

// First check after 1 minute to let the listener start up
setTimeout(tick, 60000);
