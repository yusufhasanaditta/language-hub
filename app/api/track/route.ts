// Counts one page visit for the admin analytics page (sent by lh.js after a public page loads).
// Privacy: no IP address is stored. `visitor` is a one-way hash of (secret, day, IP, browser) that
// changes every day — enough to count "unique visitors today", not to follow anyone. Old rows expire.
import type { NextRequest } from 'next/server';
import { createHash } from 'node:crypto';
import { one } from '@/lib/db';
import { clientIp, currentUser } from '@/lib/auth';
import { sameOrigin, rateLimit } from '@/lib/http';

const BOT = /bot|crawl|spider|slurp|preview|facebookexternalhit|headless|lighthouse|pingdom|curl|wget|python|axios|node-fetch/i;
const SKIP = /^\/(admin|teacher|api|do|media|thumb|_next|assets)(\/|$)/;
const done = () => new Response(null, { status: 204, headers: { 'Cache-Control': 'no-store' } });

export async function POST(req: NextRequest) {
  const ua = req.headers.get('user-agent') ?? '';
  if (!sameOrigin(req) || !ua || BOT.test(ua)) return done();
  try { await rateLimit('track', 120, 600); } catch { return done(); }
  const body = await req.json().catch(() => ({})) as { p?: unknown; r?: unknown };
  const path = typeof body.p === 'string' ? body.p.split(/[?#]/)[0].slice(0, 300) : '';
  // Must be a plain path on this site ("//other.site" or backslashes would become an outside link in the admin list).
  if (!path.startsWith('/') || path.startsWith('//') || /[\\\s<>"']/.test(path) || SKIP.test(path)) return done();

  let referrer: string | null = null;
  try {
    const host = new URL(String(body.r ?? '')).hostname.replace(/^www\./, '').slice(0, 120);
    const self = (req.headers.get('x-forwarded-host') ?? req.headers.get('host') ?? '').replace(/:\d+$/, '').replace(/^www\./, '');
    if (host && host !== self) referrer = host;
  } catch { /* no or bad referrer */ }

  const day = new Date(Date.now() + 6 * 3600e3).toISOString().slice(0, 10); // Bangladesh date
  const visitor = createHash('sha256').update(`${process.env.SESSION_SECRET || 'dev'}|${day}|${await clientIp()}|${ua}`).digest('base64url').slice(0, 24);
  const device = /iPad|Tablet/i.test(ua) ? 'tablet' : /Mobi|Android|iPhone/i.test(ua) ? 'mobile' : 'desktop';
  const slug = /^\/course\/([^/]+)/.exec(path)?.[1];
  let courseSlug = '';
  try { courseSlug = slug ? decodeURIComponent(slug) : ''; } catch { /* malformed */ }
  const user = await currentUser().catch(() => null);

  await one`INSERT INTO page_views (day, path, course_id, visitor, user_id, referrer, device)
            VALUES (${day}, ${path}, (SELECT id FROM courses WHERE slug = ${courseSlug} AND ${courseSlug} <> ''), ${visitor}, ${user?.id ?? null}, ${referrer}, ${device})`;
  if (Math.random() < 0.01) await one`DELETE FROM page_views WHERE day < (now() AT TIME ZONE 'Asia/Dhaka')::date - 400`;
  return done();
}
