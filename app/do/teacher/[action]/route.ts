import type { NextRequest } from 'next/server';
import { one, q } from '@/lib/db';
import { isAdmin } from '@/lib/auth';
import { requireTeacherProfile } from '@/lib/teacher';
import { formAction, fail, flash, str, int, optStr } from '@/lib/http';
import { safeUrl } from '@/lib/format';

type Ctx = Awaited<ReturnType<typeof requireTeacherProfile>>;

const handlers: Record<string, (f: FormData, t: Ctx) => Promise<string | void>> = {
  async 'course-text'(f, { user, tp }) {
    const id = int(f, 'course_id');
    const c = await one<{ teacher_id: number }>`SELECT teacher_id FROM courses WHERE id = ${id}`;
    if (!c || (c.teacher_id !== tp.id && !isAdmin(user))) fail('You can only edit your own courses.');
    await q`UPDATE courses SET short_desc = ${optStr(f, 'short_desc', 300)}, description_bn = ${optStr(f, 'description_bn', 20000)},
            what_you_learn = ${optStr(f, 'what_you_learn', 5000)}, requirements = ${optStr(f, 'requirements', 5000)},
            intro_video_url = ${safeUrl(str(f, 'intro_video_url', 500))}, fb_group_link = ${safeUrl(str(f, 'fb_group_link', 500))},
            updated_at = now() WHERE id = ${id}`;
    await flash('success', 'Saved.');
  },

  async profile(f, { user, tp }) {
    const username = str(f, 'username', 80).toLowerCase() || null;
    if (username && !/^[a-z0-9-]{3,80}$/.test(username)) fail('Username: 3+ lowercase letters, numbers or dashes.');
    if (username && await one`SELECT 1 FROM users WHERE username = ${username} AND id <> ${user.id}`) fail('That username is taken.');
    await q`UPDATE users SET username = ${username}, avatar = ${safeUrl(str(f, 'avatar', 500))}, updated_at = now() WHERE id = ${user.id}`;
    await q`UPDATE teacher_profiles SET designation = ${optStr(f, 'designation', 100)}, headline = ${optStr(f, 'headline', 255)},
            bio = ${optStr(f, 'bio', 3000)}, expertise = ${optStr(f, 'expertise', 500)}, qualifications = ${optStr(f, 'qualifications', 2000)},
            fb_url = ${safeUrl(str(f, 'fb_url', 300))}, yt_url = ${safeUrl(str(f, 'yt_url', 300))}, website = ${safeUrl(str(f, 'website', 300))},
            updated_at = now() WHERE id = ${tp.id}`;
    await flash('success', 'Profile saved.');
  },
};

export const POST = async (req: NextRequest, ctx: { params: Promise<{ action: string }> }) => {
  const h = handlers[(await ctx.params).action];
  if (!h) return new Response('Not found', { status: 404 });
  return formAction(async f => h(f, await requireTeacherProfile()))(req);
};
