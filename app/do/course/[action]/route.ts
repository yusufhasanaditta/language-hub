// Curriculum + live class actions, used by admins and by the course's own teacher.
import type { NextRequest } from 'next/server';
import { one, q } from '@/lib/db';
import { requireTeacher, requireUser, isAdmin, type User } from '@/lib/auth';
import { formAction, fail, flash, back, str, int, bool } from '@/lib/http';
import { safeUrl, fromLocalInput } from '@/lib/format';
import { notify } from '@/lib/orders';
import { userTeachesCourse, isDetailKind } from '@/lib/team';

/** Admins may edit any course; teachers only the courses they teach. */
async function assertCanEdit(user: User, courseId: number) {
  if (isAdmin(user)) {
    if (!(await one`SELECT 1 FROM courses WHERE id = ${courseId}`)) fail('Course not found.');
    return;
  }
  // Any teacher on the course's team (lead or member).
  if (!(await userTeachesCourse(user.id, courseId))) fail('You can only edit your own courses.');
}

type H = (f: FormData, courseId: number) => Promise<string | void>;

const handlers: Record<string, [H, string]> = {
  'module-save': [async (f, courseId) => {
    const id = int(f, 'id'), title = str(f, 'title', 200);
    if (!title) fail('Module title is required.');
    if (id) await q`UPDATE modules SET title = ${title}, sort_order = ${int(f, 'sort_order')} WHERE id = ${id} AND course_id = ${courseId}`;
    else await q`INSERT INTO modules (course_id, title, sort_order) VALUES (${courseId}, ${title}, ${int(f, 'sort_order')})`;
    await flash('success', 'Module saved.');
  }, '#curriculum'],

  'module-delete': [async (f, courseId) => {
    await q`DELETE FROM modules WHERE id = ${int(f, 'id')} AND course_id = ${courseId}`;
    await flash('success', 'Module deleted.');
  }, '#curriculum'],

  'lesson-save': [async (f, courseId) => {
    const id = int(f, 'id'), moduleId = int(f, 'module_id');
    if (!(await one`SELECT 1 FROM modules WHERE id = ${moduleId} AND course_id = ${courseId}`)) fail('Module not found.');
    const title = str(f, 'title', 200);
    if (!title) fail('Lesson title is required.');
    const kind = ['video', 'pdf', 'text', 'live'].includes(str(f, 'kind')) ? str(f, 'kind') : 'video';
    const v = {
      video_url: safeUrl(str(f, 'video_url', 500)), thumbnail: safeUrl(str(f, 'thumbnail', 500)), pdf_url: safeUrl(str(f, 'pdf_url', 500)),
      content: str(f, 'content', 20000) || null, duration: Math.max(0, int(f, 'duration')),
      is_free: bool(f, 'is_free'), is_published: bool(f, 'is_published'), sort_order: int(f, 'sort_order'),
    };
    if (id) {
      await q`UPDATE lessons l SET title = ${title}, kind = ${kind}, video_url = ${v.video_url}, thumbnail = ${v.thumbnail}, pdf_url = ${v.pdf_url}, content = ${v.content},
              duration = ${v.duration}, is_free = ${v.is_free}, is_published = ${v.is_published}, module_id = ${moduleId}
              FROM modules m WHERE l.id = ${id} AND m.id = l.module_id AND m.course_id = ${courseId}`;
    } else {
      await q`INSERT INTO lessons (module_id, title, kind, video_url, thumbnail, pdf_url, content, duration, is_free, is_published, sort_order)
              VALUES (${moduleId}, ${title}, ${kind}, ${v.video_url}, ${v.thumbnail}, ${v.pdf_url}, ${v.content}, ${v.duration}, ${v.is_free}, ${v.is_published}, ${v.sort_order})`;
    }
    await flash('success', 'Lesson saved.');
  }, '#curriculum'],

  'lesson-delete': [async (f, courseId) => {
    await q`DELETE FROM lessons l USING modules m WHERE l.id = ${int(f, 'id')} AND m.id = l.module_id AND m.course_id = ${courseId}`;
    await flash('success', 'Lesson deleted.');
  }, '#curriculum'],

  'live-save': [async (f, courseId) => {
    const id = int(f, 'id'), title = str(f, 'title', 200);
    const at = fromLocalInput(str(f, 'scheduled_at', 20));
    if (!title || !at || isNaN(at.getTime())) fail('Title and start time are required.');
    const status = ['scheduled', 'live', 'ended', 'cancelled'].includes(str(f, 'status')) ? str(f, 'status') : 'scheduled';
    const v = {
      duration: Math.min(600, Math.max(15, int(f, 'duration_minutes') || 90)),
      meeting: safeUrl(str(f, 'meeting_url', 500)), recording: safeUrl(str(f, 'recording_url', 500)),
    };
    if (id) {
      await q`UPDATE live_sessions SET title = ${title}, scheduled_at = ${at}, duration_minutes = ${v.duration}, meeting_url = ${v.meeting},
              recording_url = ${v.recording}, status = ${status} WHERE id = ${id} AND course_id = ${courseId}`;
    } else {
      await q`INSERT INTO live_sessions (course_id, title, scheduled_at, duration_minutes, meeting_url, recording_url, status)
              VALUES (${courseId}, ${title}, ${at}, ${v.duration}, ${v.meeting}, ${v.recording}, ${status})`;
      const students = await q<{ user_id: number }>`SELECT user_id FROM enrollments WHERE course_id = ${courseId} AND status = 'active'`;
      for (const s of students) await notify(s.user_id, 'live', `New live class: ${title}`, null, `/learn?course=${courseId}`);
    }
    await flash('success', 'Live class saved.');
  }, '#live'],

  'detail-save': [async (f, courseId) => {
    const id = int(f, 'id'), title = str(f, 'title', 200);
    const kind = isDetailKind(str(f, 'kind')) ? str(f, 'kind') : 'section';
    if (!title) fail(kind === 'faq' ? 'Write the question.' : 'A title is required.');
    const iconRaw = str(f, 'icon', 40);
    const icon = /^[a-z_]{2,40}$/.test(iconRaw) ? iconRaw : null;
    const body = str(f, 'body', 5000) || null;
    if (id) {
      await q`UPDATE course_details SET kind = ${kind}, icon = ${icon}, title = ${title}, body = ${body}
              WHERE id = ${id} AND course_id = ${courseId}`;
    } else {
      await q`INSERT INTO course_details (course_id, kind, icon, title, body, sort_order)
              SELECT ${courseId}, ${kind}, ${icon}, ${title}, ${body}, COALESCE(max(sort_order), 0) + 1 FROM course_details WHERE course_id = ${courseId}`;
    }
    await flash('success', 'Detail saved.');
  }, '#details'],

  'detail-move': [async (f, courseId) => {
    // Swap places with the neighbour of the same kind (dir -1 = up, 1 = down), then renumber 1…n.
    const id = int(f, 'id'), step = int(f, 'dir') < 0 ? -1 : 1;
    const me = await one<{ kind: string }>`SELECT kind FROM course_details WHERE id = ${id} AND course_id = ${courseId}`;
    if (!me) return;
    const ids = (await q<{ id: number }>`SELECT id FROM course_details WHERE course_id = ${courseId} AND kind = ${me.kind}
                 ORDER BY sort_order, id`).map(r => r.id);
    const i = ids.indexOf(id), j = i + step;
    if (j < 0 || j >= ids.length) return;
    [ids[i], ids[j]] = [ids[j], ids[i]];
    await q`UPDATE course_details d SET sort_order = o.n FROM unnest(${ids}::int[]) WITH ORDINALITY AS o(id, n)
            WHERE d.id = o.id AND d.course_id = ${courseId}`;
  }, '#details'],

  'detail-delete': [async (f, courseId) => {
    await q`DELETE FROM course_details WHERE id = ${int(f, 'id')} AND course_id = ${courseId}`;
    await flash('success', 'Detail removed.');
  }, '#details'],

  'live-delete': [async (f, courseId) => {
    await q`DELETE FROM live_sessions WHERE id = ${int(f, 'id')} AND course_id = ${courseId}`;
    await flash('success', 'Live class deleted.');
  }, '#live'],
};

export const POST = async (req: NextRequest, ctx: { params: Promise<{ action: string }> }) => {
  const entry = handlers[(await ctx.params).action];
  if (!entry) return new Response('Not found', { status: 404 });
  const [h, anchor] = entry;
  return formAction(async f => {
    const user = await requireUser();
    if (!isAdmin(user)) await requireTeacher();
    const courseId = int(f, 'course_id');
    await assertCanEdit(user, courseId);
    await h(f, courseId);
    return back(req).split('#')[0] + anchor;
  })(req);
};
