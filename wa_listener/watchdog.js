'use strict';

const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
const { exec } = require('node:child_process');

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

const HTTP_HOST = process.env.WA_HTTP_HOST || '127.0.0.1';
const HTTP_PORT = parseInt(process.env.WA_HTTP_PORT || '3210', 10);
const CHECK_INTERVAL_MS = 30000;

let lastFailAt = 0;
let failCount = 0;

function checkAlive() {
  return new Promise((resolve) => {
    const req = http.get(
      { hostname: HTTP_HOST, port: HTTP_PORT, path: '/', timeout: 5000 },
      (res) => {
        res.resume();
        resolve(res.statusCode >= 200 && res.statusCode < 500);
      }
    );
    req.on('error', () => resolve(false));
    req.on('timeout', () => { req.destroy(); resolve(false); });
  });
}

function restartListener() {
  exec('/usr/bin/pm2 restart veranda-wa-listener', (err) => {
    if (err) {
      console.error('[watchdog] restart failed:', err.message);
    } else {
      console.log('[watchdog] restarted veranda-wa-listener');
    }
  });
}

async function tick() {
  const alive = await checkAlive();
  if (!alive) {
    failCount++;
    const now = Date.now();
    // Avoid restart storm: only restart if last restart was >60s ago
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
