// Serves an uploaded photo stored by lib/media.ts. Ids are never reused, so the response is cached for good.
import { one } from '@/lib/db';

export async function GET(_req: Request, ctx: { params: Promise<{ id: string }> }) {
  const id = Number((await ctx.params).id);
  if (!Number.isInteger(id) || id <= 0) return new Response('Not found', { status: 404 });
  const m = await one<{ mime: string; data: string }>`SELECT mime, data FROM media WHERE id = ${id}`;
  if (!m) return new Response('Not found', { status: 404 });
  return new Response(Buffer.from(m.data, 'base64'), {
    headers: {
      'Content-Type': m.mime,
      'Cache-Control': 'public, max-age=31536000, immutable',
      'X-Content-Type-Options': 'nosniff',
      'Content-Security-Policy': "default-src 'none'; style-src 'unsafe-inline'; sandbox",
    },
  });
}
