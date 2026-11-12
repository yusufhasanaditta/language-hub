import { one, q, type Row } from './db';
import { requireTeacher } from './auth';

/** The signed-in teacher and their teacher_profiles row (created on first visit). */
export async function requireTeacherProfile() {
  const user = await requireTeacher();
  let tp = await one<Row>`SELECT * FROM teacher_profiles WHERE user_id = ${user.id}`;
  if (!tp) tp = await one<Row>`INSERT INTO teacher_profiles (user_id) VALUES (${user.id}) RETURNING *`;
  return { user, tp: tp! as Row & { id: number } };
}

export const myCourses = (tpId: number) => q`
  SELECT c.*, cat.name_en AS cat_name_en, cat.slug AS cat_slug,
    (SELECT count(*)::int FROM enrollments e WHERE e.course_id = c.id AND e.status = 'active') AS students,
    (SELECT count(*)::int FROM lessons l JOIN modules m ON m.id = l.module_id WHERE m.course_id = c.id) AS lessons
  FROM courses c JOIN categories cat ON cat.id = c.category_id
  WHERE c.id IN (SELECT course_id FROM course_teachers WHERE teacher_id = ${tpId}) ORDER BY c.status = 'published' DESC, c.created_at DESC`;
