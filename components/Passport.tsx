// The student's profile as a "Language Passport": a data page (photo, ID, machine-readable strip)
// and a visa page with one stamp per course — the language colour, glyph, entry date and progress.
import { one, q, type Row } from '@/lib/db';
import type { User } from '@/lib/auth';
import { T, N, DateBn, MonthYear } from '@/lib/i18n';
import { lang, langStyle } from '@/lib/langs';
import { avatarUrl } from '@/lib/format';
import { Icon } from './ui';

export type PassportData = { since: string | null; stamps: Row[]; classesDone: number };

export async function loadPassport(userId: number): Promise<PassportData> {
  const [u, stamps, done] = await Promise.all([
    one<{ created_at: string }>`SELECT created_at FROM users WHERE id = ${userId}`,
    q`SELECT c.id, c.title_bn, c.title_en, c.type, cat.slug AS cat_slug, cat.name_bn AS cat_name, cat.name_en AS cat_name_en,
             e.progress_pct, e.enrolled_at, e.completed_at
      FROM enrollments e JOIN courses c ON c.id = e.course_id JOIN categories cat ON cat.id = c.category_id
      WHERE e.user_id = ${userId} AND e.status = 'active' ORDER BY e.enrolled_at`,
    one<{ n: number }>`SELECT count(*)::int AS n FROM lesson_progress lp JOIN enrollments e ON e.id = lp.enrollment_id
      WHERE e.user_id = ${userId} AND lp.completed`,
  ]);
  return { since: u?.created_at ?? null, stamps, classesDone: done?.n ?? 0 };
}

/** Passport-style machine-readable zone: two 44-character lines (Latin letters only). */
function mrz(user: User, courses: number, langs: number) {
  const latin = user.name.normalize('NFKD').toUpperCase().replace(/[^A-Z ]/g, '').trim().split(/\s+/).filter(Boolean);
  const name = latin.length ? `${latin.at(-1)}<<${latin.slice(0, -1).join('<')}` : 'LEARNER';
  const id = `LHS${String(user.id).padStart(6, '0')}`;
  const line1 = `P<BGD${name}`.padEnd(44, '<').slice(0, 44);
  const line2 = `${id}<<BGD<<C${String(courses).padStart(2, '0')}<L${String(langs).padStart(2, '0')}`.padEnd(44, '<').slice(0, 44);
  return [line1, line2];
}

/** Small stable tilt for each stamp so the page looks hand-stamped (same every visit). */
const tilt = (id: number) => ((id * 37) % 15) - 7;

export function Passport({ user, data, edit = true }: { user: User; data: PassportData; edit?: boolean }) {
  const langs = [...new Set(data.stamps.map(s => s.cat_slug as string))];
  const done = data.stamps.filter(s => s.completed_at || Number(s.progress_pct) >= 100).length;
  const id = `LH-S${String(user.id).padStart(6, '0')}`;
  const [m1, m2] = mrz(user, data.stamps.length, langs.length);

  return (
    <div className="lh-pp" data-reveal>
      {/* ── Data page ─────────────────────────────────────────────── */}
      <section className="lh-pp-page lh-pp-data" aria-label="Passport details">
        <header className="lh-pp-head">
          <img src="/image/language-hub-logo.png" alt="" width={30} height={30} />
          <div>
            <b>Language Hub</b>
            <small><T bn="ভাষার পাসপোর্ট" en="Language passport" /></small>
          </div>
          {edit && <a href="/profile" className="lh-pp-edit" aria-label="Edit profile"><Icon name="edit" /></a>}
        </header>

        <div className="lh-pp-id">
          <div className="lh-pp-photo">
            <img src={avatarUrl(user.avatar, user.name)} alt="" width={104} height={128} />
            <span className="lh-pp-holo" aria-hidden="true"></span>
          </div>
          <dl className="lh-pp-fields">
            <div className="lh-pp-name"><dt><T bn="নাম" en="Name" /></dt><dd>{user.name}</dd></div>
            <div><dt><T bn="পাসপোর্ট নং" en="Passport no." /></dt><dd className="lh-pp-mono">{id}</dd></div>
            <div><dt><T bn="সদস্য" en="Member since" /></dt><dd><MonthYear date={data.since} /></dd></div>
            <div className="lh-pp-wide"><dt><T bn="যোগাযোগ" en="Contact" /></dt><dd className="lh-pp-contact">{user.email ?? user.phone ?? '—'}</dd></div>
          </dl>
        </div>

        <div className="lh-pp-langs" aria-label="Languages">
          {langs.length ? langs.map(slug => (
            <span key={slug} className="lh-pp-lang" style={langStyle(slug)} title={lang(slug).native}>{lang(slug).glyph}</span>
          )) : <span className="lh-pp-none"><T bn="এখনো কোনো ভাষা নেই" en="No languages yet" /></span>}
          <span className="lh-pp-sign" aria-hidden="true">{user.name}</span>
        </div>

        <dl className="lh-pp-stats" data-countup>
          <div><dt><T bn="কোর্স" en="Courses" /></dt><dd><N v={data.stamps.length} /></dd></div>
          <div><dt><T bn="শেষ করা ক্লাস" en="Classes done" /></dt><dd><N v={data.classesDone} /></dd></div>
          <div><dt><T bn="সম্পন্ন" en="Completed" /></dt><dd><N v={done} /></dd></div>
        </dl>

        <p className="lh-pp-mrz" aria-hidden="true"><span>{m1}</span><span>{m2}</span></p>
      </section>

      {/* ── Visa page ─────────────────────────────────────────────── */}
      <section className="lh-pp-page lh-pp-visas" aria-label="Course stamps">
        <header className="lh-pp-visas-head">
          <h2><T bn="ভিসা স্ট্যাম্প" en="Visa stamps" /></h2>
          <small><T bn="প্রতিটি কোর্স একটি নতুন দেশ" en="Every course is a new country" /></small>
        </header>
        <div className="lh-stamps">
          {data.stamps.map((s, i) => {
            const L = lang(s.cat_slug);
            const p = Math.min(100, Number(s.progress_pct) || 0);
            const complete = !!s.completed_at || p >= 100;
            return (
              <a key={s.id} href={`/learn?course=${s.id}`} className={`lh-visa ${complete ? 'is-done' : ''}`}
                style={{ ...langStyle(s.cat_slug), '--r': `${tilt(s.id)}deg`, '--i': i } as React.CSSProperties}>
                <span className="lh-visa-ring" style={{ '--p': p } as React.CSSProperties} aria-hidden="true"></span>
                <span className="lh-visa-glyph" aria-hidden="true">{L.glyph}</span>
                <span className="lh-visa-country">{L.native || <T bn={s.cat_name} en={s.cat_name_en} />}</span>
                <span className="lh-visa-title"><T bn={s.title_bn} en={s.title_en || s.title_bn} /></span>
                <span className="lh-visa-date"><T bn="প্রবেশ" en="Entry" /> · <DateBn date={s.enrolled_at} /></span>
                <span className="lh-visa-state">{complete ? <T bn="সম্পন্ন" en="Completed" /> : <><N v={p} />%</>}</span>
              </a>
            );
          })}
          <a href="/courses" className="lh-visa lh-visa-new" style={{ '--r': '0deg', '--i': data.stamps.length } as React.CSSProperties}>
            <Icon name="add" />
            <span className="lh-visa-title">{data.stamps.length ? <T bn="নতুন ভিসা নিন" en="Get a new visa" /> : <T bn="প্রথম ভিসা নিন" en="Get your first visa" />}</span>
            <span className="lh-visa-date"><T bn="কোর্স দেখুন" en="Browse courses" /></span>
          </a>
        </div>
      </section>
    </div>
  );
}
