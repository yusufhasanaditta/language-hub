// Protected player: every video on the site is requested here (class, free class, trailer, recording).
// The video id is never written into a page — it is handed out only after the access check below,
// only to this site (same origin), and never cached.
//   paid class / recording: signed in + may watch the course (enrolled, admin, its teacher)
//   free class (free preview lesson, or any class of a free course): anyone, no sign-in needed
//   course trailer        : anyone (it is the course's advert), published courses only
// Guests see a "Language Hub" watermark; signed-in viewers see their name, email and ID.
import type { NextRequest } from 'next/server';
import { one } from '@/lib/db';
import { currentUser } from '@/lib/auth';
import { courseBy } from '@/lib/courses';
import { canWatchCourse } from '@/lib/access';
import { sameOrigin, rateLimit, UserError } from '@/lib/http';
import { youtubeId, embedUrl, isFreeCourse } from '@/lib/format';

const json = (body: unknown, status = 200) => Response.json(body, {
  status, headers: { 'Cache-Control': 'private, no-store, max-age=0', 'X-Robots-Tag': 'noindex' },
});

function answer(url: string | null | undefined) {
  const yt = youtubeId(String(url ?? '').trim());
  if (yt) return json({ type: 'yt', v: yt });
  const other = embedUrl(url);
  return other ? json({ type: 'frame', src: other }) : json({ error: 'not found' }, 404);
}

export async function POST(req: NextRequest) {
  if (!sameOrigin(req)) return json({ error: 'forbidden' }, 403);
  try { await rateLimit('player', 600, 600); } catch (e) { if (e instanceof UserError) return json({ error: 'slow down' }, 429); throw e; }
  const body = await req.json().catch(() => ({})) as { lesson?: unknown; recording?: unknown; intro?: unknown };
  const lessonId = Number(body.lesson) || 0, recId = Number(body.recording) || 0, introId = Number(body.intro) || 0;

  if (introId) {
    const c = await one<{ intro_video_url: string | null }>`SELECT intro_video_url FROM courses WHERE id = ${introId} AND status = 'published'`;
    return c?.intro_video_url ? answer(c.intro_video_url) : json({ error: 'not found' }, 404);
  }

  const src = lessonId
    ? await one<{ course_id: number; url: string | null; is_free: boolean }>`
        SELECT m.course_id, l.video_url AS url, l.is_free FROM lessons l JOIN modules m ON m.id = l.module_id
        WHERE l.id = ${lessonId} AND l.is_published AND l.kind = 'video'`
    : recId
      ? await one<{ course_id: number; url: string | null; is_free: boolean }>`
          SELECT course_id, recording_url AS url, false AS is_free FROM live_sessions WHERE id = ${recId}`
      : null;
  if (!src?.url) return json({ error: 'not found' }, 404);

  const course = await courseBy('id', src.course_id, false);
  if (!course) return json({ error: 'not found' }, 404);
  // Free to everyone: a free preview class, or any class of a free course (recordings stay members-only).
  const open = course.status === 'published' && lessonId > 0 && (src.is_free || isFreeCourse(course));
  if (!open) {
    const user = await currentUser();
    if (!user) return json({ error: 'login' }, 401);
    if (!(await canWatchCourse(user, course))) return json({ error: 'forbidden' }, 403);
  }
  return answer(src.url);
}
