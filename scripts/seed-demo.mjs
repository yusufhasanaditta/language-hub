// Gives the demo admin / teacher / student accounts a password so every panel can be tried.
// Passwords are random unless given as env vars; they are printed once and never written to disk.
// Usage: npm run db:seed      (or ADMIN_PASSWORD=... TEACHER_PASSWORD=... STUDENT_PASSWORD=... npm run db:seed)
import { randomBytes } from 'node:crypto';
import bcrypt from 'bcryptjs';
import { Pool } from '@neondatabase/serverless';

const pool = new Pool({ connectionString: process.env.DATABASE_URL_UNPOOLED || process.env.DATABASE_URL });
const gen = () => randomBytes(9).toString('base64url');

const accounts = [
  { role: 'Admin', role_id: 6, email: 'admin@languagehub.local', name: 'Admin', pw: process.env.ADMIN_PASSWORD || gen(), panel: '/admin' },
  { role: 'Teacher', role_id: 2, email: 'tanvir.teacher@languagehub.local', name: 'Tanvir Hasan', pw: process.env.TEACHER_PASSWORD || gen(), panel: '/teacher' },
  { role: 'Student', role_id: 1, email: 'student.test@languagehub.local', name: 'Test Student', pw: process.env.STUDENT_PASSWORD || gen(), panel: '/dashboard' },
];

for (const a of accounts) {
  const hash = await bcrypt.hash(a.pw, 12);
  const { rows } = await pool.query(
    `INSERT INTO users (name, email, password_hash, role_id, is_active, is_verified) VALUES ($1, $2, $3, $4, true, true)
     ON CONFLICT (email) DO UPDATE SET password_hash = EXCLUDED.password_hash, role_id = EXCLUDED.role_id, is_active = true
     RETURNING id`, [a.name, a.email, hash, a.role_id]);
  await pool.query('DELETE FROM user_sessions WHERE user_id = $1', [rows[0].id]);
  if (a.role_id === 2) await pool.query('INSERT INTO teacher_profiles (user_id) VALUES ($1) ON CONFLICT (user_id) DO NOTHING', [rows[0].id]);
}

console.log('\nDemo logins (shown once — store them somewhere safe):\n');
for (const a of accounts) console.log(`${a.role.padEnd(8)} ${a.email.padEnd(36)} ${a.pw.padEnd(14)} → ${a.panel}`);
console.log('');
await pool.end();
