// Postgres access (Neon, over HTTP — fast cold starts on Vercel).
// Every query is parameterised: values in ${} become $1, $2 … never string-joined SQL.
import { neon, type NeonQueryFunction } from '@neondatabase/serverless';

// eslint-disable-next-line @typescript-eslint/no-explicit-any
export type Row = Record<string, any>;

let client: NeonQueryFunction<false, false> | null = null;
function conn() {
  if (!client) {
    const url = process.env.DATABASE_URL;
    if (!url) throw new Error('DATABASE_URL is not set. Run `vercel env pull .env.local`.');
    client = neon(url);
  }
  return client;
}

/** All rows. */
export async function q<T = Row>(strings: TemplateStringsArray, ...values: unknown[]): Promise<T[]> {
  return (await conn()(strings, ...values)) as T[];
}

/** First row or null. */
export async function one<T = Row>(strings: TemplateStringsArray, ...values: unknown[]): Promise<T | null> {
  return ((await conn()(strings, ...values)) as T[])[0] ?? null;
}

/** First column of the first row. */
export async function val<T = unknown>(strings: TemplateStringsArray, ...values: unknown[]): Promise<T | null> {
  const row = ((await conn()(strings, ...values)) as Row[])[0];
  return row ? (Object.values(row)[0] as T) : null;
}

/** Query built at runtime (e.g. dynamic filters). Use $1, $2 placeholders. */
export async function raw<T = Row>(text: string, params: unknown[] = []): Promise<T[]> {
  return (await conn().query(text, params)) as T[];
}

/** Several statements in one transaction. */
export async function tx(build: (sql: NeonQueryFunction<false, false>) => ReturnType<NeonQueryFunction<false, false>>[]) {
  const sql = conn();
  return sql.transaction(build(sql));
}
