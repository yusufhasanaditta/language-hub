// Course teaching team (many teachers per course) and extra course details.
// course_teachers is the single source of "who teaches what"; courses.teacher_id marks the lead,
// who is always a row in course_teachers too (schema backfill, course create, team-add).
import { one, q, type Row } from './db';

/** Role ids that may teach (teacher, admin) — same rule as isTeacher() in auth.ts. */
export const TEACHER_ROLE_IDS = [2, 6];

/** Suggested team roles; the admin can also type their own. [English, Bangla] */
export const TEAM_ROLES: [string, string][] = [
  ['Lead instructor', 'প্রধান শিক্ষক'],
  ['Instructor', 'শিক্ষক'],
  ['Assistant teacher', 'সহকারী শিক্ষক'],
  ['Guest teacher', 'অতিথি শিক্ষক'],
  ['Speaking partner', 'স্পিকিং পার্টনার'],
  ['Mentor', 'মেন্টর'],
];
export const roleBn = (role: string) => TEAM_ROLES.find(r => r[0].toLowerCase() === role.toLowerCase())?.[1] ?? role;

/** Everyone on a course's team, lead first. withStats adds the counts the public crew ticket shows. */
export const courseTeam = (courseId: number, { catSlug = '', withStats = false } = {}) => q`
  SELECT u.id AS user_id, u.name, u.avatar, u.username, u.email, tp.id AS tp_id, tp.designation, tp.headline,
         ct.role, ct.sort_order, (tp.id = c.teacher_id) AS is_lead, ${catSlug}::text AS cat_slug,
         CASE WHEN ${withStats} THEN (SELECT count(*)::int FROM course_teachers xt JOIN courses x ON x.id = xt.course_id
            WHERE xt.teacher_id = tp.id AND x.status = 'published') END AS courses,
         CASE WHEN ${withStats} THEN (SELECT count(DISTINCT e.user_id)::int FROM course_teachers xt JOIN enrollments e ON e.course_id = xt.course_id
            WHERE xt.teacher_id = tp.id AND e.status = 'active') END AS students
  FROM course_teachers ct
  JOIN courses c ON c.id = ct.course_id
  JOIN teacher_profiles tp ON tp.id = ct.teacher_id
  JOIN users u ON u.id = tp.user_id
  WHERE ct.course_id = ${courseId} AND u.is_active AND u.role_id = ANY(${TEACHER_ROLE_IDS})
  ORDER BY (tp.id = c.teacher_id) DESC, ct.sort_order, ct.created_at`;

/** True when this teacher profile is on the course's team (lead or member). */
export async function teachesCourse(tpId: number, courseId: number) {
  return !!(await one`SELECT 1 FROM course_teachers WHERE course_id = ${courseId} AND teacher_id = ${tpId}`);
}

/** Same check by user id (for pages that only have the signed-in user). */
export async function userTeachesCourse(userId: number, courseId: number) {
  return !!(await one`SELECT 1 FROM course_teachers ct JOIN teacher_profiles tp ON tp.id = ct.teacher_id
    WHERE ct.course_id = ${courseId} AND tp.user_id = ${userId}`);
}

/**
 * Makes tpId the course's lead (null = the first remaining team member) and keeps the
 * role labels honest: the new lead reads "Lead instructor", a previous lead becomes "Instructor".
 */
export async function setLead(courseId: number, tpId: number | null) {
  await q`UPDATE courses SET updated_at = now(), teacher_id = COALESCE(${tpId}::int,
            (SELECT teacher_id FROM course_teachers WHERE course_id = ${courseId} ORDER BY sort_order, created_at LIMIT 1))
          WHERE id = ${courseId}`;
  await q`UPDATE course_teachers ct SET role = CASE
            WHEN ct.teacher_id = c.teacher_id THEN 'Lead instructor'
            WHEN ct.role = 'Lead instructor' THEN 'Instructor' ELSE ct.role END
          FROM courses c WHERE c.id = ct.course_id AND ct.course_id = ${courseId}`;
}

/** Per-kind wording for the editor and the default icon for the course page. */
export const DETAIL_KINDS = {
  fact: {
    label: 'At a glance', add: 'Add a fact', icon: 'info', title: 'Label', body: 'Value', rows: 1,
    hint: 'Short label + value, e.g. “Class days” → “Sat · Mon · Wed, 8 pm”.',
    titleEg: 'e.g. Class days', bodyEg: 'e.g. Sat · Mon · Wed, 8 pm',
  },
  section: {
    label: 'Info section', add: 'Add an info section', icon: 'article', title: 'Title', body: 'Text', rows: 4,
    hint: 'A titled card. Lines starting with “-” become a tick list.',
    titleEg: 'e.g. Study materials', bodyEg: '- PDF workbook\n- Weekly quiz',
  },
  faq: {
    label: 'FAQ', add: 'Add a question', icon: 'help', title: 'Question', body: 'Answer', rows: 4,
    hint: 'A question and its answer.',
    titleEg: 'e.g. Do I get a certificate?', bodyEg: '',
  },
} as const;
export type DetailKind = keyof typeof DETAIL_KINDS;
export const isDetailKind = (k: string): k is DetailKind => Object.hasOwn(DETAIL_KINDS, k);

/** Material icon names the editor offers. */
export const DETAIL_ICONS = [
  'info', 'schedule', 'event', 'calendar_month', 'timer', 'menu_book', 'auto_stories', 'workspace_premium', 'verified',
  'school', 'quiz', 'record_voice_over', 'headset_mic', 'support_agent', 'laptop', 'devices', 'groups', 'forum',
  'translate', 'language', 'payments', 'card_giftcard', 'flight_takeoff', 'emoji_events', 'download', 'checklist',
];

export const courseDetails = (courseId: number) =>
  q`SELECT * FROM course_details WHERE course_id = ${courseId} ORDER BY sort_order, id`;

export const byKind = (rows: Row[], kind: DetailKind) => rows.filter(r => r.kind === kind);
