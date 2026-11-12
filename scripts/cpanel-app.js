// cPanel "Application startup file". Starts the Next.js server on the port the host gives us.
// (Copied into the upload package by scripts/build-cpanel.mjs.)
const path = require('path');
const fs = require('fs');
const net = require('net');
const dns = require('dns');
const https = require('https');
const Module = require('module');

// Libraries are bundled in vendor_modules/ (cPanel reserves node_modules/ for its own link).
process.env.NODE_PATH = [path.join(__dirname, 'vendor_modules'), process.env.NODE_PATH].filter(Boolean).join(path.delimiter);
Module._initPaths();
process.env.NODE_ENV = process.env.NODE_ENV || 'production';
process.env.HOSTNAME = process.env.HOSTNAME || '0.0.0.0';
process.chdir(__dirname);

// Shared hosts often have no IPv6 route and a slow path to the cloud database. Node's default
// "happy eyeballs" gives each address only 250 ms, then fails with ETIMEDOUT ("fetch failed").
// Prefer IPv4 and allow each connection attempt several seconds.
dns.setDefaultResultOrder('ipv4first');
if (net.setDefaultAutoSelectFamilyAttemptTimeout) net.setDefaultAutoSelectFamilyAttemptTimeout(5000);

// Settings can also come from a .env.production file in this folder (Next.js loads it on start).
const fromFile = fs.existsSync('.env.production') ? fs.readFileSync('.env.production', 'utf8') : '';
const setting = k => process.env[k] || ((fromFile.match(new RegExp('^' + k + '=(.+)$', 'm')) || [])[1] || '').trim();
const missing = ['DATABASE_URL', 'SESSION_SECRET'].filter(k => !setting(k));
if (missing.length) console.error('[Language Hub] Missing settings: ' + missing.join(', ') + ' — add them in Setup Node.js App → Environment variables (then Save + Restart) or in .env.production.');

// One-time check on start: can this server reach the database? Result goes to stderr.log.
try {
  const host = new URL(setting('DATABASE_URL').replace(/^["']|["']$/g, '')).hostname;
  const t0 = Date.now();
  const req = https.get({ host, path: '/', timeout: 15000 }, res => {
    res.resume();
    console.error(`[Language Hub] Database host ${host} reachable (HTTP ${res.statusCode}, ${Date.now() - t0} ms).`);
  });
  req.on('timeout', () => req.destroy(Object.assign(new Error('no answer in 15 s'), { code: 'ETIMEDOUT' })));
  req.on('error', e => console.error(`[Language Hub] CANNOT reach database host ${host} on port 443 (${e.code || e.message}). ` +
    'Ask your host to allow outgoing HTTPS (port 443) connections to *.neon.tech.'));
} catch { /* no DATABASE_URL — reported above */ }

require('./server.js');
