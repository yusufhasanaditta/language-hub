// Applies db/schema.sql to the database in DATABASE_URL.  Usage: npm run db:schema
import { readFileSync } from 'node:fs';
import { Pool } from '@neondatabase/serverless';

const pool = new Pool({ connectionString: process.env.DATABASE_URL_UNPOOLED || process.env.DATABASE_URL });
await pool.query(readFileSync(new URL('../db/schema.sql', import.meta.url), 'utf8'));
const { rows } = await pool.query(`SELECT count(*)::int AS n FROM information_schema.tables WHERE table_schema = 'public'`);
console.log(`Schema applied — ${rows[0].n} tables.`);
await pool.end();
