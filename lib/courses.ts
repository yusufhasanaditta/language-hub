// Course queries shared by the public pages, the student area and the panels.
import { raw, type Row } from './db';

const LESSON_COUNT = `(SELECT count(*) FROM lessons l JOIN modules m ON m.id = l.module_id
                       WHERE m.course_id = c.id AND l.is_published)::int`;
const STUDENT_COUNT = `(SELECT count(*) FROM enrollments e WHERE e.course_id = c.id AND e.status = 'active')::int`;

export const COURSE_SELECT = `
  SELECT c.*, cat.slug AS cat_slug, cat.name_bn AS cat_name, cat.name_en AS cat_name_en,
         tp.id AS tp_id, tp.designation, u.name AS teacher_name, u.username AS teacher_username, u.avatar AS teacher_avatar,
         to_char(c.batch_start, 'YYYY-MM-DD') AS batch_date,
         GREATEST(${LESSON_COUNT}, c.total_lessons) AS lesson_count,
         ${STUDENT_COUNT} AS student_count
  FROM courses c
  JOIN categories cat ON cat.id = c.category_id
  LEFT JOIN teacher_profiles tp ON tp.id = c.teacher_id
  LEFT JOIN users u ON u.id = tp.user_id`;

const DEFAULT_ORDER = `c.is_featured DESC, (c.type = 'live') DESC, c.created_at DESC`;

/** Published courses. `where` is extra SQL using $1… placeholders from `params`. */
export function publishedCourses(where = '', params: unknown[] = [], order = DEFAULT_ORDER) {
  return raw(`${COURSE_SELECT} WHERE c.status = 'published' ${where} ORDER BY ${order}`, params);
}

export async function courseBy(field: 'id' | 'slug', value: string | number, publishedOnly = true) {
  const rows = await raw(`${COURSE_SELECT} WHERE c.${field} = $1 ${publishedOnly ? `AND c.status = 'published'` : ''}`, [value]);
  return rows[0] ?? null;
}

export type Module = Row & { lessons: Row[] };

/** Modules with their lessons, in order. */
export async function curriculum(courseId: number, includeUnpublished = false): Promise<Module[]> {
  const modules = await raw(`SELECT * FROM modules WHERE course_id = $1 ORDER BY sort_order, id`, [courseId]);
  const lessons = await raw(
    `SELECT l.* FROM lessons l JOIN modules m ON m.id = l.module_id
     WHERE m.course_id = $1 ${includeUnpublished ? '' : 'AND l.is_published'} ORDER BY l.sort_order, l.id`, [courseId]);
  return modules.map(m => ({ ...m, lessons: lessons.filter(l => l.module_id === m.id) }));
}

export const courseUrl = (c: Row) => `/course/${encodeURIComponent(c.slug)}`;

/** Seats still open (null = no limit). Every course is capped at max_students (default 20). */
export const seatsLeft = (c: Row): number | null =>
  Number(c.max_students) > 0 ? Math.max(0, Number(c.max_students) - Number(c.student_count ?? 0)) : null;
export const isFull = (c: Row) => seatsLeft(c) === 0;

export const teacherUrl = (t: Row) => {
  const u = t.teacher_username ?? t.username;
  return u ? `/t/${encodeURIComponent(u)}` : '/teachers';
};

/** Recalculates a student's progress % for one enrolment. */
export async function refreshProgress(enrollmentId: number) {
  await raw(
    `UPDATE enrollments e SET
       progress_pct = COALESCE((
         SELECT round(100.0 * count(*) FILTER (WHERE lp.completed) / NULLIF(count(l.id), 0))
         FROM lessons l JOIN modules m ON m.id = l.module_id
         LEFT JOIN lesson_progress lp ON lp.lesson_id = l.id AND lp.enrollment_id = e.id
         WHERE m.course_id = e.course_id AND l.is_published), 0),
       completed_at = CASE WHEN e.completed_at IS NULL AND (
         SELECT bool_and(COALESCE(lp.completed, false)) FROM lessons l JOIN modules m ON m.id = l.module_id
         LEFT JOIN lesson_progress lp ON lp.lesson_id = l.id AND lp.enrollment_id = e.id
         WHERE m.course_id = e.course_id AND l.is_published) THEN now() ELSE e.completed_at END
     WHERE e.id = $1`, [enrollmentId]);
}
