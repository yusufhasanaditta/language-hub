// Protected player: the classroom asks here for a class (or live-class recording) to play.
// The video id is never written into the page HTML — it is handed out only to a signed-in
// learner who may watch that course, from this site (same origin), and is never cached.
import type { NextRequest } from 'next/server';
import { one } from '@/lib/db';
import { currentUser } from '@/lib/auth';
import { courseBy } from '@/lib/courses';
import { canWatchCourse } from '@/lib/access';
import { sameOrigin, rateLimit, UserError } from '@/lib/http';
import { youtubeId, embedUrl } from '@/lib/format';

const json = (body: unknown, status = 200) => Response.json(body, {
  status, headers: { 'Cache-Control': 'private, no-store, max-age=0', 'X-Robots-Tag': 'noindex' },
});

export async function POST(req: NextRequest) {
  if (!sameOrigin(req)) return json({ error: 'forbidden' }, 403);
  const user = await currentUser();
  if (!user) return json({ error: 'login' }, 401);
  try { await rateLimit('player', 600, 600); } catch (e) { if (e instanceof UserError) return json({ error: 'slow down' }, 429); throw e; }

  const body = await req.json().catch(() => ({})) as { lesson?: unknown; recording?: unknown };
  const lessonId = Number(body.lesson) || 0, recId = Number(body.recording) || 0;
  const src = lessonId
    ? await one<{ course_id: number; url: string | null }>`
        SELECT m.course_id, l.video_url AS url FROM lessons l JOIN modules m ON m.id = l.module_id
        WHERE l.id = ${lessonId} AND l.is_published AND l.kind = 'video'`
    : recId
      ? await one<{ course_id: number; url: string | null }>`SELECT course_id, recording_url AS url FROM live_sessions WHERE id = ${recId}`
      : null;
  if (!src?.url) return json({ error: 'not found' }, 404);

  const course = await courseBy('id', src.course_id, false);
  if (!course || !(await canWatchCourse(user, course))) return json({ error: 'forbidden' }, 403);

  const yt = youtubeId(src.url.trim());
  if (yt) return json({ type: 'yt', v: yt });
  const other = embedUrl(src.url);
  return other ? json({ type: 'frame', src: other }) : json({ error: 'not found' }, 404);
}
