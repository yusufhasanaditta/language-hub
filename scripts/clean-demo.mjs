// Removes all demo/test data before launch. KEEPS: the admin and ops staff accounts, the language
// categories and the site settings. Prints a new admin password once.
//
//   npm run db:clean-demo            → shows what would be deleted (dry run)
//   npm run db:clean-demo -- --yes   → deletes it
import { randomBytes } from 'node:crypto';
import bcrypt from 'bcryptjs';
import { Pool } from '@neondatabase/serverless';

const yes = process.argv.includes('--yes');
const pool = new Pool({ connectionString: process.env.DATABASE_URL_UNPOOLED || process.env.DATABASE_URL });
const c = await pool.connect();
const count = async t => (await c.query(`SELECT count(*)::int AS n FROM ${t}`)).rows[0].n;

// Everything below is deleted in this order (children first).
const WIPE = [
  'lesson_progress', 'reviews', 'testimonials', 'notices', 'support_tickets', 'notifications', 'contact_messages',
  'order_items', 'orders', 'coupons', 'enrollments', 'live_sessions', 'lessons', 'modules',
  'course_details', 'course_teachers', 'courses',
  'password_resets', 'password_requests', 'email_codes', 'rate_limits', 'user_sessions',
];
const existing = new Set((await c.query(`SELECT table_name FROM information_schema.tables WHERE table_schema = 'public'`)).rows.map(r => r.table_name));
const tables = WIPE.filter(t => existing.has(t));
const admins = (await c.query(`SELECT id, name, email FROM users WHERE role_id IN (5, 6) ORDER BY id`)).rows;
const others = (await c.query(`SELECT id, name, email, role_id FROM users WHERE role_id NOT IN (5, 6) ORDER BY id`)).rows;

console.log('Keeping admin account(s):', admins.map(a => `${a.name} <${a.email}>`).join(', ') || 'NONE');
console.log('Keeping:', 'categories', await count('categories'), '· settings', await count('settings'));
console.log('\nWill delete users:', others.map(u => `${u.name} <${u.email ?? '-'}>`).join(', ') || 'none');
for (const t of tables) console.log(`  ${t.padEnd(18)} ${await count(t)} rows`);
if (!admins.length) { console.error('\nNo admin account found — refusing to run (you would be locked out).'); process.exit(1); }
if (!yes) { console.log('\nDry run only. Run again with --yes to delete.'); await c.release(); await pool.end(); process.exit(0); }

const pw = randomBytes(9).toString('base64url');
try {
  await c.query('BEGIN');
  for (const t of tables) await c.query(`DELETE FROM ${t}`);
  await c.query(`DELETE FROM teacher_profiles WHERE user_id IN (SELECT id FROM users WHERE role_id NOT IN (5, 6))`);
  await c.query(`DELETE FROM users WHERE role_id NOT IN (5, 6)`);
  await c.query(`UPDATE users SET password_hash = $1, updated_at = now() WHERE role_id = 6`, [await bcrypt.hash(pw, 12)]);
  await c.query('COMMIT');
} catch (e) { await c.query('ROLLBACK'); throw e; }

console.log('\nDemo data removed. Everyone was signed out.');
console.log(`New admin password (shown once): ${pw}`);
console.log('Log in, then change it and the admin email in Admin → Users.');
c.release(); await pool.end();
