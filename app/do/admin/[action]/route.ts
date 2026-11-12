// Admin form actions. Every handler re-checks admin access (never rely on the page alone).
import type { NextRequest } from 'next/server';
import { one, q } from '@/lib/db';
import { requireAdmin, isTeacher, ROLE, ROLE_NAMES, type User } from '@/lib/auth';
import { formAction, fail, flash, str, int, num, bool, optStr, isEmail } from '@/lib/http';
import { hashPassword } from '@/lib/login';
import { enroll, markOrderPaid, notify } from '@/lib/orders';
import { safeUrl, slugify } from '@/lib/format';
import { EDITABLE_SETTINGS } from '@/lib/settings';
import { setLead } from '@/lib/team';

type H = (f: FormData, me: User) => Promise<string | void>;

const handlers: Record<string, H> = {
  // ── Users ──────────────────────────────────────────────────────────────────
  async 'user-save'(f, me) {
    const id = int(f, 'id');
    const name = str(f, 'name', 100), email = str(f, 'email', 150).toLowerCase();
    const phone = str(f, 'phone', 15).replace(/[^\d+]/g, '') || null;
    const username = str(f, 'username', 80).toLowerCase() || null;
    const pw = String(f.get('password') ?? '');
    if (name.length < 2) fail('Name is required.');
    if (!isEmail(email)) fail('Enter a valid email address.');
    if (username && !/^[a-z0-9-]{3,80}$/.test(username)) fail('Username: 3+ lowercase letters, numbers or dashes.');
    if (pw && pw.length < 8) fail('Password must be at least 8 characters.');
    if (!id && !pw) fail('Set a password for the new user.');
    const clash = await one`SELECT id FROM users WHERE id <> ${id} AND (lower(email) = ${email}
      OR (${phone}::text IS NOT NULL AND phone = ${phone}) OR (${username}::text IS NOT NULL AND username = ${username}))`;
    if (clash) fail('Another user already has this email, phone or username.');
    // Admins cannot demote or disable themselves (would lock them out).
    const role = id === me.id ? me.role_id : int(f, 'role_id');
    const active = id === me.id ? true : bool(f, 'is_active');
    if (!ROLE_NAMES[role]) fail('Choose a valid role.');

    let userId = id;
    if (id) {
      await q`UPDATE users SET name = ${name}, email = ${email}, phone = ${phone}, username = ${username},
              role_id = ${role}, is_active = ${active}, updated_at = now() WHERE id = ${id}`;
    } else {
      const u = await one<{ id: number }>`INSERT INTO users (name, email, phone, username, role_id, is_active, is_verified)
        VALUES (${name}, ${email}, ${phone}, ${username}, ${role}, ${active}, true) RETURNING id`;
      userId = u!.id;
    }
    if (pw) {
      await q`UPDATE users SET password_hash = ${await hashPassword(pw)} WHERE id = ${userId}`;
      if (id && id !== me.id) await q`DELETE FROM user_sessions WHERE user_id = ${id}`;
    }
    if (!active) await q`DELETE FROM user_sessions WHERE user_id = ${userId}`;
    if (role === ROLE.TEACHER) await q`INSERT INTO teacher_profiles (user_id) VALUES (${userId}) ON CONFLICT (user_id) DO NOTHING`;
    await flash('success', id ? 'User saved.' : 'User created.');
    return `/admin/users/${userId}`;
  },

  async 'teacher-profile'(f) {
    const uid = int(f, 'user_id');
    await q`INSERT INTO teacher_profiles (user_id, designation, headline, bio, featured_order, updated_at)
            VALUES (${uid}, ${optStr(f, 'designation', 100)}, ${optStr(f, 'headline', 255)}, ${optStr(f, 'bio', 3000)}, ${Math.min(255, Math.max(0, int(f, 'featured_order')))}, now())
            ON CONFLICT (user_id) DO UPDATE SET designation = EXCLUDED.designation, headline = EXCLUDED.headline,
              bio = EXCLUDED.bio, featured_order = EXCLUDED.featured_order, updated_at = now()`;
    await flash('success', 'Teacher profile saved.');
  },

  async 'user-signout'(f, me) {
    const id = int(f, 'id');
    if (id !== me.id) await q`DELETE FROM user_sessions WHERE user_id = ${id}`;
    await flash('success', 'Signed out of all devices.');
  },

  // ── Enrollments ────────────────────────────────────────────────────────────
  async enroll(f) {
    const email = str(f, 'email', 150).toLowerCase();
    const courseId = int(f, 'course_id');
    const u = await one<{ id: number }>`SELECT id FROM users WHERE lower(email) = ${email}`;
    if (!u) fail('No user with that email. They need to sign up first (or add them under Users).');
    const c = await one<{ id: number; title_bn: string }>`SELECT id, title_bn FROM courses WHERE id = ${courseId}`;
    if (!c) fail('Choose a course.');
    await enroll(u!.id, c!.id);
    await notify(u!.id, 'enrollment', `Enrolled in ${c!.title_bn}`, 'You now have access to this course.', `/learn?course=${c!.id}`);
    await flash('success', 'Student enrolled.');
  },

  async 'enrollment-status'(f) {
    const status = str(f, 'status', 12);
    if (!['active', 'suspended'].includes(status)) fail('Invalid status.');
    await q`UPDATE enrollments SET status = ${status} WHERE id = ${int(f, 'id')}`;
    await flash('success', `Enrollment ${status}.`);
  },

  // ── Courses ────────────────────────────────────────────────────────────────
  async 'course-save'(f) {
    const id = int(f, 'id');
    const title_bn = str(f, 'title_bn', 200), title_en = str(f, 'title_en', 200);
    if (!title_bn) fail('Bangla title is required.');
    const categoryId = int(f, 'category_id');
    if (!(await one`SELECT 1 FROM categories WHERE id = ${categoryId}`)) fail('Choose a language.');
    let slug = slugify(str(f, 'slug', 200) || title_en || '') || `course-${Date.now().toString(36)}`;
    if (await one`SELECT 1 FROM courses WHERE slug = ${slug} AND id <> ${id}`) slug = `${slug}-${Date.now().toString(36).slice(-4)}`;
    // The lead teacher is picked here only for a new course; afterwards the Teaching team card manages it.
    const teacherId = id ? null : int(f, 'teacher_id') || null;
    const price = Math.max(0, num(f, 'price'));
    const saleRaw = str(f, 'sale_price', 20);
    const sale = saleRaw === '' ? null : Math.max(0, Number(saleRaw) || 0);
    const type = ['recorded', 'live', 'hybrid'].includes(str(f, 'type')) ? str(f, 'type') : 'recorded';
    const level = ['beginner', 'intermediate', 'advanced'].includes(str(f, 'level')) ? str(f, 'level') : 'beginner';
    const status = ['draft', 'published', 'archived'].includes(str(f, 'status')) ? str(f, 'status') : 'draft';
    const batch = /^\d{4}-\d{2}-\d{2}$/.test(str(f, 'batch_start')) ? str(f, 'batch_start') : null;
    const vals = {
      title_bn, title_en: title_en || null, slug, category_id: categoryId, teacher_id: teacherId, price, sale_price: sale,
      type, level, status, batch_start: batch, max_students: str(f, 'max_students') === '' ? 20 : Math.min(10000, Math.max(0, int(f, 'max_students'))), total_lessons: Math.max(0, int(f, 'total_lessons')),
      thumbnail: safeUrl(str(f, 'thumbnail', 500)), intro_video_url: safeUrl(str(f, 'intro_video_url', 500)), fb_group_link: safeUrl(str(f, 'fb_group_link', 500)),
      short_desc: optStr(f, 'short_desc', 300), description_bn: optStr(f, 'description_bn', 20000),
      what_you_learn: optStr(f, 'what_you_learn', 5000), requirements: optStr(f, 'requirements', 5000),
      is_free: bool(f, 'is_free'), is_featured: bool(f, 'is_featured'),
    };
    let courseId = id;
    if (id) {
      await q`UPDATE courses SET title_bn = ${vals.title_bn}, title_en = ${vals.title_en}, slug = ${vals.slug}, category_id = ${vals.category_id},
        price = ${vals.price}, sale_price = ${vals.sale_price}, type = ${vals.type}, level = ${vals.level},
        status = ${vals.status}, batch_start = ${vals.batch_start}, max_students = ${vals.max_students}, total_lessons = ${vals.total_lessons},
        thumbnail = ${vals.thumbnail}, intro_video_url = ${vals.intro_video_url}, fb_group_link = ${vals.fb_group_link},
        short_desc = ${vals.short_desc}, description_bn = ${vals.description_bn}, what_you_learn = ${vals.what_you_learn},
        requirements = ${vals.requirements}, is_free = ${vals.is_free}, is_featured = ${vals.is_featured}, updated_at = now()
        WHERE id = ${id}`;
    } else {
      const c = await one<{ id: number }>`INSERT INTO courses (title_bn, title_en, slug, category_id, teacher_id, price, sale_price, type, level, status,
        batch_start, max_students, total_lessons, thumbnail, intro_video_url, fb_group_link, short_desc, description_bn, what_you_learn, requirements, is_free, is_featured)
        VALUES (${vals.title_bn}, ${vals.title_en}, ${vals.slug}, ${vals.category_id}, ${vals.teacher_id}, ${vals.price}, ${vals.sale_price}, ${vals.type},
        ${vals.level}, ${vals.status}, ${vals.batch_start}, ${vals.max_students}, ${vals.total_lessons}, ${vals.thumbnail}, ${vals.intro_video_url},
        ${vals.fb_group_link}, ${vals.short_desc}, ${vals.description_bn}, ${vals.what_you_learn}, ${vals.requirements}, ${vals.is_free}, ${vals.is_featured})
        RETURNING id`;
      courseId = c!.id;
      // The lead picked on the create form is the first member of the teaching team.
      if (vals.teacher_id) await q`INSERT INTO course_teachers (course_id, teacher_id, role) VALUES (${courseId}, ${vals.teacher_id}, 'Lead instructor')`;
    }
    await flash('success', id ? 'Course saved.' : 'Course created — now add teachers, details and lessons below.');
    return `/admin/courses/${courseId}`;
  },

  // ── Teaching team (any number of teachers per course) ─────────────────────
  async 'team-add'(f) {
    const courseId = int(f, 'course_id'), userId = int(f, 'user_id');
    const [course, u] = await Promise.all([
      one<{ teacher_id: number | null }>`SELECT teacher_id FROM courses WHERE id = ${courseId}`,
      one<{ role_id: number }>`SELECT role_id FROM users WHERE id = ${userId} AND is_active`,
    ]);
    if (!course) fail('Course not found.');
    if (!u || !isTeacher(u)) fail('Choose a teacher (Users → set the role to teacher first).');
    const tp = await one<{ id: number }>`INSERT INTO teacher_profiles (user_id) VALUES (${userId})
      ON CONFLICT (user_id) DO UPDATE SET user_id = EXCLUDED.user_id RETURNING id`;
    await q`INSERT INTO course_teachers (course_id, teacher_id, role, sort_order)
            SELECT ${courseId}, ${tp!.id}, ${str(f, 'role', 60) || 'Instructor'}, COALESCE(max(sort_order), 0) + 1
            FROM course_teachers WHERE course_id = ${courseId}
            ON CONFLICT (course_id, teacher_id) DO UPDATE SET role = EXCLUDED.role`;
    // The first teacher on a course (or one ticked as lead) becomes its lead teacher.
    if (course!.teacher_id === null || bool(f, 'lead')) await setLead(courseId, tp!.id);
    await flash('success', 'Teacher added to the course.');
    return str(f, 'from') === 'user' ? `/admin/users/${userId}#teaching` : `/admin/courses/${courseId}#team`;
  },

  async 'team-update'(f) {
    const courseId = int(f, 'course_id'), tpId = int(f, 'tp_id');
    await q`UPDATE course_teachers SET role = ${str(f, 'role', 60) || 'Instructor'}, sort_order = ${int(f, 'sort_order')}
            WHERE course_id = ${courseId} AND teacher_id = ${tpId}`;
    await flash('success', 'Saved.');
    return `/admin/courses/${courseId}#team`;
  },

  async 'team-lead'(f) {
    const courseId = int(f, 'course_id'), tpId = int(f, 'tp_id');
    if (!(await one`SELECT 1 FROM course_teachers WHERE course_id = ${courseId} AND teacher_id = ${tpId}`)) fail('That teacher is not on this course.');
    await setLead(courseId, tpId);
    await flash('success', 'Lead teacher changed.');
    return `/admin/courses/${courseId}#team`;
  },

  async 'team-remove'(f) {
    const courseId = int(f, 'course_id'), tpId = int(f, 'tp_id');
    await q`DELETE FROM course_teachers WHERE course_id = ${courseId} AND teacher_id = ${tpId}`;
    // Removing the lead hands the lead role to the next teacher on the team (or none).
    if (await one`SELECT 1 FROM courses WHERE id = ${courseId} AND teacher_id = ${tpId}`) await setLead(courseId, null);
    await flash('success', 'Teacher removed from the course.');
    return `/admin/courses/${courseId}#team`;
  },

  async 'course-delete'(f) {
    await q`DELETE FROM courses WHERE id = ${int(f, 'id')}`;
    await flash('success', 'Course deleted.');
    return '/admin/courses';
  },

  async 'category-save'(f) {
    const id = int(f, 'id');
    const slug = slugify(str(f, 'slug', 100));
    const nameBn = str(f, 'name_bn', 100), nameEn = str(f, 'name_en', 100);
    if (!nameBn || !nameEn || !slug) fail('Fill in both names and the slug.');
    if (await one`SELECT 1 FROM categories WHERE slug = ${slug} AND id <> ${id}`) fail('That slug is already used.');
    const color = /^#[0-9a-f]{6}$/i.test(str(f, 'color')) ? str(f, 'color') : '#5B21B6';
    const order = int(f, 'sort_order');
    if (id) await q`UPDATE categories SET name_bn = ${nameBn}, name_en = ${nameEn}, slug = ${slug}, color = ${color}, sort_order = ${order} WHERE id = ${id}`;
    else await q`INSERT INTO categories (name_bn, name_en, slug, color, sort_order, icon) VALUES (${nameBn}, ${nameEn}, ${slug}, ${color}, ${order}, 'translate')`;
    await flash('success', 'Saved.');
  },

  async 'category-delete'(f) {
    const id = int(f, 'id');
    if (await one`SELECT 1 FROM courses WHERE category_id = ${id}`) fail('Move or delete its courses first.');
    await q`DELETE FROM categories WHERE id = ${id}`;
    await flash('success', 'Deleted.');
  },

  // ── Orders ─────────────────────────────────────────────────────────────────
  async 'order-approve'(f) {
    const ok = await markOrderPaid(int(f, 'id'), 'manual', null);
    await flash(ok ? 'success' : 'info', ok ? 'Payment approved — the student is enrolled.' : 'This order was already paid.');
  },

  async 'order-status'(f) {
    const id = int(f, 'id'), status = str(f, 'status', 12);
    if (!['failed', 'refunded', 'cancelled'].includes(status)) fail('Invalid status.');
    const o = await one<{ user_id: number; status: string }>`UPDATE orders SET status = ${status} WHERE id = ${id} RETURNING user_id, status`;
    if (!o) fail('Order not found.');
    if (status === 'refunded') await q`UPDATE enrollments SET status = 'refunded' WHERE order_id = ${id}`;
    await notify(o!.user_id, 'order', status === 'failed' ? 'Payment could not be verified' : `Order ${status}`,
      status === 'failed' ? 'Please check the TrxID and submit again, or contact us.' : null, '/orders');
    await flash('success', `Order marked ${status}.`);
  },

  // ── Coupons ────────────────────────────────────────────────────────────────
  async 'coupon-save'(f) {
    const code = str(f, 'code', 50).toUpperCase();
    if (!/^[A-Z0-9_-]{2,50}$/.test(code)) fail('Code: letters, numbers, - or _.');
    const type = str(f, 'type') === 'flat' ? 'flat' : 'percent';
    const value = num(f, 'value');
    if (value <= 0 || (type === 'percent' && value > 100)) fail('Enter a valid discount.');
    if (await one`SELECT 1 FROM coupons WHERE upper(code) = ${code}`) fail('That code already exists.');
    const exp = /^\d{4}-\d{2}-\d{2}$/.test(str(f, 'expires_at')) ? str(f, 'expires_at') : null;
    await q`INSERT INTO coupons (code, type, value, min_order, max_uses, expires_at)
            VALUES (${code}, ${type}, ${value}, ${Math.max(0, num(f, 'min_order'))}, ${Math.max(0, int(f, 'max_uses'))}, ${exp})`;
    await flash('success', `Coupon ${code} created.`);
  },
  async 'coupon-toggle'(f) { await q`UPDATE coupons SET is_active = NOT is_active WHERE id = ${int(f, 'id')}`; },
  async 'coupon-delete'(f) { await q`DELETE FROM coupons WHERE id = ${int(f, 'id')}`; await flash('success', 'Coupon deleted.'); },

  // ── Messages & settings ────────────────────────────────────────────────────
  async 'message-read'(f) { await q`UPDATE contact_messages SET is_read = true WHERE id = ${int(f, 'id')}`; },
  async 'message-delete'(f) { await q`DELETE FROM contact_messages WHERE id = ${int(f, 'id')}`; },

  async settings(f) {
    for (const [key, , type] of EDITABLE_SETTINGS) {
      let v = str(f, key, 2000);
      if (type === 'url' && v && !safeUrl(v)) fail(`${key}: enter a full https:// link.`);
      if (key === 'registration_open') v = v === '0' ? '0' : '1';
      await q`INSERT INTO settings (setting_key, setting_value) VALUES (${key}, ${v})
              ON CONFLICT (setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value, updated_at = now()`;
    }
    await flash('success', 'Settings saved.');
  },
};

export const POST = async (req: NextRequest, ctx: { params: Promise<{ action: string }> }) => {
  const h = handlers[(await ctx.params).action];
  if (!h) return new Response('Not found', { status: 404 });
  return formAction(async f => h(f, await requireAdmin()))(req);
};
