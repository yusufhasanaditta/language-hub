// Builds an upload-ready package for cPanel "Setup Node.js App" (EBNhost and similar hosts).
//
//   npm run build:cpanel   →   deploy/language-hub-cpanel.zip
//
// The zip holds a self-contained Next.js server (output: 'standalone'):
//   app.js            startup file for cPanel (Application startup file)
//   server.js         Next.js server
//   .next/            compiled site      public/  images, CSS, JS      content/  static page text
//   vendor_modules/   the libraries the server needs (no "npm install" on the host)
//   package.json      used only if you choose "Run NPM Install" instead
// CloudLinux's Node.js selector keeps its own node_modules link in the app folder, so the
// bundled libraries live in vendor_modules/ and app.js points Node at them.
import { execSync } from 'node:child_process';
import { cpSync, existsSync, mkdirSync, readFileSync, renameSync, rmSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';

const root = new URL('..', import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, '$1');
const out = join(root, 'deploy', 'language-hub');
const zip = join(root, 'deploy', 'language-hub-cpanel.zip');

console.log('1/4  Building Next.js (standalone)…');
execSync('npx next build', { cwd: root, stdio: 'inherit', env: { ...process.env, BUILD_STANDALONE: '1', NEXT_TELEMETRY_DISABLED: '1' } });

console.log('2/4  Assembling deploy/language-hub …');
rmSync(out, { recursive: true, force: true });
mkdirSync(out, { recursive: true });
cpSync(join(root, '.next', 'standalone'), out, { recursive: true });
cpSync(join(root, '.next', 'static'), join(out, '.next', 'static'), { recursive: true });
cpSync(join(root, 'public'), join(out, 'public'), { recursive: true });
if (existsSync(join(root, 'content'))) cpSync(join(root, 'content'), join(out, 'content'), { recursive: true });
// Never ship local secrets or the legacy PHP site.
for (const f of ['.env', '.env.local', '.env.production', '.env.development', 'legacy-php']) rmSync(join(out, f), { recursive: true, force: true });
if (existsSync(join(out, 'node_modules'))) renameSync(join(out, 'node_modules'), join(out, 'vendor_modules'));

const pkg = JSON.parse(readFileSync(join(root, 'package.json'), 'utf8'));
writeFileSync(join(out, 'package.json'), JSON.stringify({
  name: 'language-hub', version: pkg.version, private: true,
  description: 'Language Hub — production server (cPanel / Node.js)',
  main: 'app.js', scripts: { start: 'node app.js' }, engines: { node: '>=20' },
  dependencies: pkg.dependencies,
}, null, 2) + '\n');

writeFileSync(join(out, 'app.js'), `// cPanel "Application startup file". Starts the Next.js server on the port the host gives us.
const path = require('path');
const Module = require('module');
// Libraries are bundled in vendor_modules/ (cPanel reserves node_modules/ for its own link).
process.env.NODE_PATH = [path.join(__dirname, 'vendor_modules'), process.env.NODE_PATH].filter(Boolean).join(path.delimiter);
Module._initPaths();
process.env.NODE_ENV = process.env.NODE_ENV || 'production';
process.env.HOSTNAME = process.env.HOSTNAME || '0.0.0.0';
process.chdir(__dirname);
require('./server.js');
`);

writeFileSync(join(out, '.env.example'), `# Add these in cPanel → Setup Node.js App → your app → Environment variables.
# (Copy the values from deploy/ENV-VALUES-PRIVATE.txt on your computer — never share them.)
DATABASE_URL=postgresql://USER:PASSWORD@HOST/neondb?sslmode=require
SESSION_SECRET=a-long-random-string-at-least-32-characters
NODE_ENV=production
# Optional — email login codes:
# SMTP_HOST=  SMTP_PORT=587  SMTP_USER=  SMTP_PASS=  SMTP_FROM_NAME=Language Hub
# Optional — SSLCommerz online payments:
# SSLCZ_STORE_ID=  SSLCZ_STORE_PASS=  SSLCZ_LIVE=1
`);

console.log('3/4  Writing deploy/ENV-VALUES-PRIVATE.txt (stays on this computer)…');
const env = Object.fromEntries((existsSync(join(root, '.env.local')) ? readFileSync(join(root, '.env.local'), 'utf8') : '')
  .split(/\r?\n/).map(l => l.match(/^([A-Z0-9_]+)=(.*)$/)).filter(Boolean).map(m => [m[1], m[2].replace(/^"|"$/g, '')]));
const keep = ['DATABASE_URL', 'SESSION_SECRET', 'SMTP_HOST', 'SMTP_PORT', 'SMTP_USER', 'SMTP_PASS', 'SMTP_FROM_NAME', 'SSLCZ_STORE_ID', 'SSLCZ_STORE_PASS', 'SSLCZ_LIVE'];
writeFileSync(join(root, 'deploy', 'ENV-VALUES-PRIVATE.txt'),
  `PRIVATE — paste these into cPanel → Setup Node.js App → Environment variables. Do not upload or share this file.\n\n` +
  keep.filter(k => env[k]).map(k => `${k}=${env[k]}`).join('\n') + '\nNODE_ENV=production\n');

console.log('4/4  Zipping…');
rmSync(zip, { force: true });
if (process.platform === 'win32') {
  // Windows 10+ ships bsdtar, which writes .zip with -a (faster and safer with long paths than Compress-Archive).
  execSync(`tar -a -c -f "${zip}" -C "${out}" .`, { stdio: 'inherit' });
} else {
  execSync(`cd "${out}" && zip -qr "${zip}" .`, { stdio: 'inherit' });
}
console.log(`\nDone → ${zip}`);
