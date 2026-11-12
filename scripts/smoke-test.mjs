// End-to-end smoke test against a running site (local `npm run dev` or the live URL).
// Usage:
//   BASE_URL=http://localhost:3000 ADMIN_PASSWORD=… TEACHER_PASSWORD=… STUDENT_PASSWORD=… npm run test:smoke
// It creates a few test rows (order, coupon, draft course, live class) and removes them at the end.
const BASE = (process.env.BASE_URL || 'http://localhost:3000').replace(/\/$/, '');
const ACC = {
  admin: [process.env.ADMIN_EMAIL || 'admin@languagehub.local', process.env.ADMIN_PASSWORD],
  teacher: [process.env.TEACHER_EMAIL || 'tanvir.teacher@languagehub.local', process.env.TEACHER_PASSWORD],
  student: [process.env.STUDENT_EMAIL || 'student.test@languagehub.local', process.env.STUDENT_PASSWORD],
};
let failures = 0, passes = 0;
const ok = (cond, msg) => { if (cond) passes++; else { failures++; console.log('  ✗ ' + msg); } };

class Client {
  jar = {};
  async req(path, { method = 'GET', form, json, origin = BASE } = {}) {
    const headers = { cookie: Object.entries(this.jar).map(([k, v]) => `${k}=${v}`).join('; '), 'user-agent': `smoke-test/${this.ua ?? 'x'} Chrome Windows` };
    let body;
    if (form) { body = new URLSearchParams(form); headers['content-type'] = 'application/x-www-form-urlencoded'; headers.origin = origin; }
    if (json) { body = JSON.stringify(json); headers['content-type'] = 'application/json'; headers.origin = origin; }
    const r = await fetch(BASE + path, { method: form || json ? 'POST' : method, headers, body, redirect: 'manual' });
    for (const c of r.headers.getSetCookie()) {
      const [kv, ...attrs] = c.split(';'); const i = kv.indexOf('=');
      const k = kv.slice(0, i), v = kv.slice(i + 1);
      if (!v || attrs.some(a => /max-age=0/i.test(a) || /expires=thu, 01 jan 1970/i.test(a))) delete this.jar[k]; else this.jar[k] = v;
    }
    const text = await r.text();
    return { status: r.status, loc: r.headers.get('location')?.replace(BASE, '') ?? null, text };
  }
  flash() { try { return decodeURIComponent(this.jar.lh_flash ?? ''); } catch { return this.jar.lh_flash ?? ''; } }
}

const BROKEN = /Application error|Unhandled Runtime Error|Internal Server Error|NEXT_REDIRECT|digest:/;
async function page(c, path, expect = 200) {
  const r = await c.req(path);
  ok(r.status === expect, `${path} → ${r.status} (expected ${expect})${r.loc ? ' → ' + r.loc : ''}`);
  if (r.status === 200) ok(!BROKEN.test(r.text), `${path} shows an error page`);
  return r;
}

async function login(role) {
  const [email, pw] = ACC[role];
  if (!pw) throw new Error(`${role.toUpperCase()}_PASSWORD is not set`);
  const c = new Client(); c.ua = role;
  const r = await c.req('/do/login/password', { form: { email, password: pw, next: '/dashboard' } });
  ok(r.status === 303 && r.loc === '/dashboard', `${role} login → ${r.status} ${r.loc} ${c.flash()}`);
  return c;
}

console.log(`Smoke test → ${BASE}\n`);

// ── Guest ────────────────────────────────────────────────────────────────────
console.log('Guest pages');
const g = new Client();
for (const p of ['/', '/courses', '/courses?type=live', '/courses?free=1', '/courses?q=japanese', '/category/japanese',
  '/course/demo-japanese-n5-live', '/teachers', '/t/demo-tanvir', '/about', '/terms', '/privacy', '/refund', '/faq', '/careers', '/contact', '/login', '/login?mode=signup']) await page(g, p);
await page(g, '/course/does-not-exist', 404);
for (const p of ['/dashboard', '/my-courses', '/orders', '/admin', '/teacher', '/learn?course=5', '/checkout?course=4']) {
  const r = await g.req(p); ok(r.status === 307 && r.loc?.startsWith('/login'), `guest ${p} should redirect to login (got ${r.status} ${r.loc})`);
}
{ const r = await g.req('/courses.php?type=live'); ok(r.status === 308 && r.loc === '/courses?type=live', `legacy URL redirect (${r.status} ${r.loc})`); }
{ const r = await g.req('/course.php?slug=demo-korean-hangul'); ok(r.status === 308 && r.loc?.startsWith('/course/demo-korean-hangul'), `legacy course URL (${r.status} ${r.loc})`); }
{ const r = await g.req('/do/login/password', { form: { email: ACC.admin[0], password: 'wrong-password' } }); ok(r.status === 303 && g.flash().includes('Wrong'), 'wrong password is rejected'); }
{ const r = await g.req('/do/login/password', { form: { email: ACC.admin[0], password: ACC.admin[1] }, origin: 'https://evil.example' }); ok(r.status === 403, `cross-site login POST blocked (${r.status})`); }
{ const r = await g.req('/do/contact', { form: { name: 'Smoke Test', email: 'smoke@example.com', subject: 'smoke-test', message: 'Automated smoke test message' } }); ok(r.status === 303 && g.flash().includes('Thank'), 'contact form'); }
// Forgot password: request page, request (unknown address gets the same answer), dead reset link
await page(g, '/forgot');
{ const r = await g.req('/do/forgot', { form: { contact: 'smoke-forgot@example.com', name: 'Smoke Forgot', note: 'smoke-test' } }); ok(r.status === 303 && r.loc === '/forgot?sent=1', `forgot request (${r.status} ${r.loc})`); }
{ const r = await page(g, '/reset?token=not-a-real-token-xxxxxxxxxxxx'); ok(/no longer works|কাজ করছে না/.test(r.text), 'invalid reset link is refused'); }
{ const r = await g.req('/do/reset', { form: { token: 'not-a-real-token-xxxxxxxxxxxx', password: 'whatever123', confirm: 'whatever123' } }); ok(r.status === 303 && g.flash().includes('no longer works'), 'reset with a bad token is refused'); }

// ── Student ──────────────────────────────────────────────────────────────────
console.log('Student');
const s = await login('student');
for (const p of ['/dashboard', '/my-courses', '/my-courses?tab=done', '/orders', '/notifications', '/profile', '/course/demo-japanese-n5-live', '/learn?course=5', '/checkout?course=4', '/checkout?course=4&coupon=NOPE']) await page(s, p);
{ const r = await s.req('/admin'); ok(r.status === 307 && r.loc === '/denied', `student blocked from /admin (${r.status} ${r.loc})`); }
{ const r = await s.req('/teacher'); ok(r.status === 307 && r.loc === '/denied', `student blocked from /teacher (${r.status} ${r.loc})`); }
{ const r = await s.req('/do/admin/order-approve', { form: { id: 1 } }); ok(r.status === 303 && r.loc === '/denied', `student cannot call admin actions (${r.status} ${r.loc})`); }
// Free enrolment (IELTS free workshop)
{ const r = await s.req('/do/enroll', { form: { course_id: 11 } }); ok(r.status === 303 && r.loc === '/learn?course=11&welcome=1', `free enrol (${r.status} ${r.loc})`); }
await page(s, '/learn?course=11&welcome=1');
// Progress API on an enrolled course's lesson
{ const r = await s.req('/api/progress.php', { json: { lesson_id: 1, completed: true } }); ok(r.status === 200 && JSON.parse(r.text).success, `progress API (${r.status} ${r.text.slice(0, 80)})`); }
{ const r = await s.req('/api/notifications.php?action=unread_count'); ok(r.status === 200 && JSON.parse(r.text).success, 'notifications API'); }
// Manual bKash payment for a paid course (Chinese HSK1 live, id 4)
const trx = 'SMOKE' + Date.now().toString(36).toUpperCase();
{ const r = await s.req('/do/checkout', { form: { course_id: 4, method: 'manual', pay_ref: trx, pay_from: '01712345678', coupon: '' } }); ok(r.status === 303 && r.loc === '/orders', `manual payment submitted (${r.status} ${r.loc} ${s.flash()})`); }
{ const r = await s.req('/do/checkout', { form: { course_id: 4, method: 'manual', pay_ref: trx, pay_from: '01712345678', coupon: '' } }); ok(s.flash().includes('already'), 'same TrxID cannot be reused'); }
{ const r = await s.req('/learn?course=4'); ok(r.status === 307, 'not enrolled before approval'); }
// Profile save
{ const r = await s.req('/do/profile', { form: { name: 'Test Student', phone: '', address: '', avatar: '', bio: 'smoke' } }); ok(r.status === 303 && s.flash().includes('Saved'), `profile save ${s.flash()}`); }

// ── Admin ────────────────────────────────────────────────────────────────────
console.log('Admin');
const a = await login('admin');
for (const p of ['/admin', '/admin/courses', '/admin/courses?status=draft', '/admin/courses/new', '/admin/courses/5', '/admin/categories', '/admin/users', '/admin/users?role=2', '/admin/users/new', '/admin/users/11',
  '/admin/enrollments', '/admin/orders', '/admin/orders?status=pending', '/admin/coupons', '/admin/messages', '/admin/settings', '/admin/password-help', '/teacher', '/learn?course=4']) await page(a, p);
{ // the smoke request shows up for the admin; close it
  const id = (await a.req('/admin/password-help')).text.match(/Smoke Forgot[^]*?help-status[^]*?name="id" value="(\d+)"/)?.[1];
  ok(!!id, 'forgot request listed in Password help');
  if (id) { await a.req('/do/admin/help-status', { form: { id, status: 'dismissed' } }); ok(a.flash().includes('closed'), 'close a password-help request'); }
}
// Approve the student's payment
const ordersHtml = (await a.req('/admin/orders?status=pending')).text;
const m = ordersHtml.match(new RegExp(`name="id" value="(\\d+)"[^]*?${trx}`)) || ordersHtml.match(new RegExp(`${trx}[^]*?order-approve[^]*?name="id" value="(\\d+)"`));
const orderId = m?.[1];
ok(!!orderId, 'pending order visible to admin');
if (orderId) {
  const r = await a.req('/do/admin/order-approve', { form: { id: orderId } }); ok(r.status === 303 && a.flash().includes('approved'), `approve (${a.flash()})`);
  await page(s, '/learn?course=4');
  await page(s, `/invoice/${orderId}`);
  { const r = await g.req(`/invoice/${orderId}`); ok(r.status === 307, 'guest cannot open invoice'); }
}
// Coupon
{ const r = await a.req('/do/admin/coupon-save', { form: { code: 'SMOKE50', type: 'percent', value: 50, min_order: 0, max_uses: 0, expires_at: '' } }); ok(a.flash().includes('created'), `coupon create ${a.flash()}`); }
{ const r = await s.req('/checkout?course=6&coupon=SMOKE50'); ok(r.text.includes('SMOKE50') && /Coupon applied|কুপন প্রয়োগ/.test(r.text), 'coupon applies at checkout'); }
// Course create → module → lesson → delete
{
  const r = await a.req('/do/admin/course-save', { form: { id: '', title_bn: 'স্মোক টেস্ট কোর্স', title_en: 'Smoke Test Course', slug: '', category_id: 4, teacher_id: '', status: 'draft', type: 'recorded', level: 'beginner', price: 100, sale_price: '', batch_start: '', max_students: 0, total_lessons: 0 } });
  const cid = r.loc?.match(/\/admin\/courses\/(\d+)/)?.[1];
  ok(!!cid, `create course (${r.loc} ${a.flash()})`);
  if (cid) {
    await a.req('/do/course/module-save', { form: { course_id: cid, title: 'Module 1', sort_order: 1 } });
    const ed = await page(a, `/admin/courses/${cid}`);
    const mid = ed.text.match(/name="module_id" value="(\d+)"/)?.[1];
    ok(!!mid, 'module created');
    if (mid) { await a.req('/do/course/lesson-save', { form: { course_id: cid, module_id: mid, title: 'Lesson 1', kind: 'video', video_url: 'https://youtu.be/dQw4w9WgXcQ', duration: 5, is_published: '1', sort_order: 1 } }); ok(a.flash().includes('Lesson saved'), 'lesson created'); }
    { const r2 = await a.req('/do/course/lesson-save', { form: { course_id: cid, module_id: mid, title: 'x', kind: 'video', video_url: 'javascript:alert(1)' } }); }
    ok(!(await a.req(`/admin/courses/${cid}`)).text.includes('javascript:alert'), 'javascript: URLs are dropped');
    await a.req('/do/admin/course-delete', { form: { id: cid } }); ok(a.flash().includes('deleted'), 'course deleted');
  }
}
{
  // Re-save the settings exactly as they are (the live site's real phone/links must survive the test).
  const html = (await a.req('/admin/settings')).text;
  const unesc = s => s.replace(/&quot;/g, '"').replace(/&#x27;|&#39;/g, "'").replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&');
  const form = {};
  for (const m of html.matchAll(/<input[^>]*\bname="([a-z_]+)"[^>]*>/g)) form[m[1]] = unesc(m[0].match(/\bvalue="([^"]*)"/)?.[1] ?? '');
  for (const m of html.matchAll(/<textarea[^>]*\bname="([a-z_]+)"[^>]*>([^<]*)<\/textarea>/g)) form[m[1]] = unesc(m[2]);
  ok('contact_phone' in form && 'registration_open' in form, 'settings form fields found');
  await a.req('/do/admin/settings', { form });
  ok(a.flash().includes('saved'), 'settings save');
}

// ── Teacher ──────────────────────────────────────────────────────────────────
console.log('Teacher');
const t = await login('teacher');
for (const p of ['/teacher', '/teacher/courses', '/teacher/courses/5', '/teacher/live', '/teacher/students', '/teacher/profile', '/learn?course=5']) await page(t, p);
{ const r = await t.req('/admin'); ok(r.status === 307 && r.loc === '/denied', 'teacher blocked from admin'); }
{ const r = await t.req('/teacher/courses/4'); ok(r.status === 404, `teacher cannot open another teacher's course (${r.status})`); }
{ const r = await t.req('/learn?course=4'); ok(r.status === 307, `teacher cannot enter another teacher's classroom (${r.status})`); }
{ await t.req('/do/course/module-save', { form: { course_id: 4, title: 'hack' } }); ok(t.flash().includes('own courses'), `teacher cannot edit another course (${t.flash()})`); }
{ await t.req('/do/course/live-save', { form: { course_id: 5, title: 'Smoke live class', scheduled_at: '2030-01-01T19:00', duration_minutes: 60, meeting_url: 'https://meet.google.com/abc-defg-hij', status: 'scheduled' } }); ok(t.flash().includes('Live class saved'), 'teacher schedules live class'); }

// ── Cleanup ──────────────────────────────────────────────────────────────────
console.log('Cleanup');
{
  const liveId = (await t.req('/teacher/courses/5')).text.match(/Smoke live class<\/b>[^]*?name="id" value="(\d+)"/)?.[1];
  if (liveId) { await t.req('/do/course/live-delete', { form: { course_id: 5, id: liveId } }); ok(t.flash().includes('deleted'), 'live class removed'); }
  const msgId = (await a.req('/admin/messages')).text.match(/smoke-test[^]*?message-delete[^]*?name="id" value="(\d+)"/)?.[1];
  if (msgId) await a.req('/do/admin/message-delete', { form: { id: msgId } });
}
const cpn = (await a.req('/admin/coupons')).text.match(/SMOKE50[^]*?coupon-delete[^]*?name="id" value="(\d+)"/)?.[1];
if (cpn) await a.req('/do/admin/coupon-delete', { form: { id: cpn } });
if (orderId) await a.req('/do/admin/order-status', { form: { id: orderId, status: 'refunded' } });
for (const c of [s, a, t]) await c.req('/logout');

console.log(`\n${passes} passed, ${failures} failed`);
process.exit(failures ? 1 : 0);
