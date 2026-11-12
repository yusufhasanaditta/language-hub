// Teacher shown as a boarding-pass "crew card": main part + perforated stub.
import type { Row } from '@/lib/db';
import { T, N } from '@/lib/i18n';
import { lang, langStyle } from '@/lib/langs';
import { avatarUrl } from '@/lib/format';
import { teacherUrl } from '@/lib/courses';
import { Icon } from './ui';

/** t: { name, avatar, username, designation, headline, courses, students, cat_slug, tp_id } */
export function TeacherTicket({ t }: { t: Row }) {
  const L = lang(t.cat_slug);
  const code = `LH-T${String(t.tp_id ?? t.id ?? 0).padStart(3, '0')}`;
  const href = teacherUrl(t);
  return (
    <a className="lh-crew" href={href} style={langStyle(t.cat_slug)}>
      <div className="lh-crew-main">
        <span className="lh-crew-glyph" aria-hidden="true">{L.glyph}</span>
        <p className="lh-crew-tag"><Icon name="badge" /><T bn="শিক্ষক" en="Instructor" /> · <span>{L.native || 'Language Hub'}</span></p>
        <div className="lh-crew-id">
          <img src={avatarUrl(t.avatar, t.name)} alt="" width={56} height={56} loading="lazy" />
          <div>
            <b>{t.name}</b>
            {t.designation && <small>{t.designation}</small>}
          </div>
        </div>
        {t.headline && <p className="lh-crew-note">{t.headline}</p>}
      </div>
      <div className="lh-crew-stub">
        <dl>
          <div><dt><T bn="কোর্স" en="Courses" /></dt><dd><N v={t.courses ?? 0} /></dd></div>
          <div><dt><T bn="শিক্ষার্থী" en="Learners" /></dt><dd><N v={t.students ?? 0} /></dd></div>
        </dl>
        <span className="lh-barcode" aria-hidden="true"></span>
        <span className="lh-crew-code" aria-hidden="true">{code}</span>
        {href !== '/teachers' && <span className="lh-crew-go"><T bn="প্রোফাইল" en="Profile" /> <Icon name="arrow_forward" /></span>}
      </div>
    </a>
  );
}
