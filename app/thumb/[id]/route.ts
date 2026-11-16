// Class thumbnail by lesson id. The server fetches the YouTube picture itself, so the video id
// of a paid lesson never appears in a public page (an i.ytimg.com/vi/<id>/ link would reveal it).
import { one } from '@/lib/db';
import { youtubeId } from '@/lib/format';

const CACHE = 'public, max-age=86400, s-maxage=604800, stale-while-revalidate=604800';

export async function GET(_req: Request, ctx: { params: Promise<{ id: string }> }) {
  const id = Number((await ctx.params).id);
  if (!Number.isInteger(id) || id <= 0) return new Response('Not found', { status: 404 });
  const l = await one<{ video_url: string | null; thumbnail: string | null }>`
    SELECT l.video_url, l.thumbnail FROM lessons l WHERE l.id = ${id}`;
  const yt = l ? youtubeId(String(l.video_url ?? '').trim()) : '';
  if (!yt) return new Response('Not found', { status: 404 });
  // Fixed host + an id that passed youtubeId()'s 11-character check: no user-controlled URL is fetched.
  for (const size of ['hqdefault', 'mqdefault']) {
    const r = await fetch(`https://i.ytimg.com/vi/${yt}/${size}.jpg`, { signal: AbortSignal.timeout(8000), redirect: 'error' }).catch(() => null);
    if (r?.ok) {
      return new Response(await r.arrayBuffer(), {
        headers: { 'Content-Type': 'image/jpeg', 'Cache-Control': CACHE, 'X-Content-Type-Options': 'nosniff' },
      });
    }
  }
  return new Response('Not found', { status: 404 });
}
