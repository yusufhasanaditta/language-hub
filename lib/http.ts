// Classic form handling: <form method="post" action="/do/..."> → route handler → 303 redirect.
// Full page loads keep the site's plain JS (menus, filters, countdowns) working.
import { cookies, headers } from 'next/headers';
import { NextResponse, type NextRequest } from 'next/server';
import { one } from './db';
import { clientIp } from './auth';

/** An error the user should see (shown as a flash message, then redirected back). */
export class UserError extends Error {
  constructor(public bn: string, public en: string = bn) { super(en); }
}
export const fail = (bn: string, en?: string): never => { throw new UserError(bn, en); };

export type FlashType = 'success' | 'error' | 'info' | 'warning';

export async function flash(type: FlashType, bn: string, en: string = bn) {
  (await cookies()).set('lh_flash', JSON.stringify({ type, bn, en }), { path: '/', maxAge: 60, sameSite: 'lax' });
}

export async function readFlash(): Promise<{ type: FlashType; bn: string; en: string } | null> {
  const v = (await cookies()).get('lh_flash')?.value;
  if (!v) return null;
  try { return JSON.parse(v); } catch { return null; }
}

/** Reject cross-site form posts (CSRF). Cookies are SameSite=Lax as a second layer. */
export function sameOrigin(req: NextRequest) {
  const host = req.headers.get('x-forwarded-host') ?? req.headers.get('host');
  const origin = req.headers.get('origin');
  if (origin) {
    try { return new URL(origin).host === host; } catch { return false; }
  }
  const site = req.headers.get('sec-fetch-site');
  return site === 'same-origin' || site === 'none';
}

/** Only allow redirects to paths on this site. */
export function safePath(p: unknown, fallback = '/') {
  const s = typeof p === 'string' ? p : '';
  return s.startsWith('/') && !s.startsWith('//') && !s.startsWith('/\\') ? s : fallback;
}

export function back(req: NextRequest, fallback = '/') {
  const ref = req.headers.get('referer');
  if (!ref) return fallback;
  try {
    const u = new URL(ref);
    return u.host === (req.headers.get('x-forwarded-host') ?? req.headers.get('host')) ? u.pathname + u.search + u.hash : fallback;
  } catch { return fallback; }
}

/**
 * 303 redirect with a relative Location (absolute only for off-site targets like a payment gateway).
 * Never built from req.url: behind cPanel/Passenger or a proxy that is the internal address
 * (e.g. http://0.0.0.0:3000), which would send visitors to a dead host.
 */
export const seeOther = (_req: NextRequest, path: string) =>
  new NextResponse(null, { status: 303, headers: { Location: path } });

/**
 * The public site address, e.g. https://languagehub.com.bd — from SITE_URL when set (recommended
 * on any host), otherwise from the request's Host header (validated; forwarding headers can be spoofed).
 */
export async function siteUrl() {
  const fixed = process.env.SITE_URL?.trim().replace(/\/+$/, '');
  if (fixed && /^https?:\/\/[a-z0-9.-]+(:\d+)?$/i.test(fixed)) return fixed;
  const h = await headers();
  const host = (h.get('host') ?? '').trim();
  const safeHost = /^[a-z0-9.-]+(:\d{1,5})?$/i.test(host) ? host : 'localhost:3000';
  const local = /^(localhost|127\.0\.0\.1|0\.0\.0\.0)(:|$)/.test(safeHost);
  const fwd = (h.get('x-forwarded-proto') ?? '').split(',')[0].trim();
  return `${local ? 'http' : fwd === 'http' || fwd === 'https' ? fwd : 'https'}://${safeHost}`;
}

type Handler = (form: FormData, req: NextRequest) => Promise<string | Response | void>;

/**
 * Wraps a POST handler: checks origin, parses the form, turns UserError into a
 * flash message, and redirects (303) to the returned path or back to the form.
 */
export function formAction(handler: Handler) {
  return async (req: NextRequest) => {
    if (!sameOrigin(req)) return new NextResponse('Request blocked (cross-site form).', { status: 403 });
    const form = await req.formData();
    try {
      const out = await handler(form, req);
      if (out instanceof Response) return out;
      return seeOther(req, safePath(out, back(req)));
    } catch (e) {
      if (e instanceof UserError) {
        await flash('error', e.bn, e.en);
        return seeOther(req, back(req));
      }
      // next/navigation redirect() thrown from a helper (e.g. requireUser)
      if (e && typeof e === 'object' && 'digest' in e && String((e as { digest: unknown }).digest).startsWith('NEXT_REDIRECT')) {
        const url = String((e as { digest: string }).digest).split(';')[2];
        return seeOther(req, safePath(url, '/login'));
      }
      throw e;
    }
  };
}

// ── Form field helpers ───────────────────────────────────────────────────────

export const str = (f: FormData, k: string, max = 255) => String(f.get(k) ?? '').trim().slice(0, max);
export const int = (f: FormData, k: string) => { const n = parseInt(String(f.get(k) ?? ''), 10); return Number.isFinite(n) ? n : 0; };
export const num = (f: FormData, k: string) => { const n = parseFloat(String(f.get(k) ?? '')); return Number.isFinite(n) ? n : 0; };
export const bool = (f: FormData, k: string) => f.get(k) === '1' || f.get(k) === 'on';
export const optStr = (f: FormData, k: string, max = 255) => str(f, k, max) || null;
export const isEmail = (s: string) => /^[^\s@]{1,64}@[^\s@]{1,190}\.[a-z]{2,}$/i.test(s);

// ── Rate limiting (per IP, fixed window, stored in Postgres) ────────────────

export async function rateLimit(bucket: string, limit: number, windowSeconds = 60) {
  const key = `${bucket}:${await clientIp()}`;
  const r = await one<{ hits: number }>`
    INSERT INTO rate_limits (key, hits, window_end) VALUES (${key}, 1, now() + make_interval(secs => ${windowSeconds}))
    ON CONFLICT (key) DO UPDATE SET
      hits       = CASE WHEN rate_limits.window_end < now() THEN 1 ELSE rate_limits.hits + 1 END,
      window_end = CASE WHEN rate_limits.window_end < now() THEN EXCLUDED.window_end ELSE rate_limits.window_end END
    RETURNING hits`;
  if (Math.random() < 0.02) await one`DELETE FROM rate_limits WHERE window_end < now() - interval '1 hour'`;
  if (r && r.hits > limit) fail('অনেকবার চেষ্টা হয়েছে। একটু পরে আবার চেষ্টা করুন।', 'Too many attempts. Please wait a minute and try again.');
}
