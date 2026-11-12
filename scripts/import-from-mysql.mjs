// One-time copy of the old MySQL data (XAMPP) into Postgres.
// Usage: npm run db:import   (MYSQL_URL defaults to mysql://root@localhost/language_hub)
import mysql from 'mysql2/promise';
import { Pool } from '@neondatabase/serverless';

const my = await mysql.createConnection({ uri: process.env.MYSQL_URL || 'mysql://root@localhost/language_hub', charset: 'utf8mb4', dateStrings: false });
const pg = new Pool({ connectionString: process.env.DATABASE_URL_UNPOOLED || process.env.DATABASE_URL });

const pgCols = async t => (await pg.query(
  `SELECT column_name, data_type FROM information_schema.columns WHERE table_name = $1`, [t])).rows;

// Copies the columns both databases share. `map` renames/derives columns, `where` filters rows.
async function copy(table, { from = table, map = {}, where = '' } = {}) {
  const cols = await pgCols(table);
  const [rows] = await my.query(`SELECT * FROM \`${from}\` ${where}`);
  let n = 0;
  for (const row of rows) {
    for (const [k, f] of Object.entries(map)) row[k] = f(row);
    const use = cols.filter(c => c.column_name in row);
    const vals = use.map(c => {
      let v = row[c.column_name];
      if (c.data_type === 'boolean' && v !== null) v = Boolean(Number(v));
      return v;
    });
    const sql = `INSERT INTO ${table} (${use.map(c => c.column_name).join(',')})
                 VALUES (${use.map((_, i) => '$' + (i + 1)).join(',')}) ON CONFLICT DO NOTHING`;
    n += (await pg.query(sql, vals)).rowCount;
  }
  // Keep serial counters ahead of imported ids.
  if (cols.some(c => c.column_name === 'id')) {
    await pg.query(`SELECT setval(pg_get_serial_sequence('${table}','id'), GREATEST((SELECT COALESCE(MAX(id),0) FROM ${table}),1))`);
  }
  console.log(`${table.padEnd(18)} ${n}/${rows.length}`);
}

await copy('users', { map: { password_hash: r => r.password_hash?.replace(/^\$2y\$/, '$2b$') ?? null } });
await copy('categories');
await copy('teacher_profiles');
await copy('courses', { map: { is_featured: r => r.is_featured, teacher_id: r => r.teacher_id } });
await copy('modules');
await copy('lessons');
await copy('coupons');
await copy('orders');
await copy('order_items', { map: { course_id: r => (r.item_type === 'course' ? r.item_id : null) },
                            where: 'WHERE order_id IN (SELECT id FROM orders)' });
await copy('enrollments');
await copy('lesson_progress');
await copy('reviews');
await copy('testimonials');
await copy('notices', { where: "WHERE status <> 'archived'" });
await copy('notifications', { map: { action_url: r => r.action_url?.replace(/^https?:\/\/[^/]+\/language-hub/, '') ?? null } });
await copy('contact_messages');
await copy('support_tickets');
await copy('settings', { where: "WHERE setting_key NOT LIKE '%pass%' AND setting_key NOT LIKE '%api_key%'" });

// Each course's lead teacher is the first member of its teaching team.
await pg.query(`INSERT INTO course_teachers (course_id, teacher_id, role)
  SELECT id, teacher_id, 'Lead instructor' FROM courses WHERE teacher_id IS NOT NULL ON CONFLICT DO NOTHING`);

await my.end(); await pg.end();
