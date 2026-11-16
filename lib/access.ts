// Who may watch a course's classes. Used by the classroom page and the player API, so both
// always apply the same rule.
import { one } from './db';
import type { Row } from './db';
import { isAdmin, isTeacher, type User } from './auth';
import { isFreeCourse } from './format';
import { userTeachesCourse } from './team';

/** Active enrolment, admin, the course's own teacher, or a published free course. */
export async function canWatchCourse(user: User, course: Row) {
  if (isAdmin(user)) return true;
  if (isFreeCourse(course) && course.status === 'published') return true;
  if (await one`SELECT 1 FROM enrollments WHERE user_id = ${user.id} AND course_id = ${course.id} AND status = 'active'`) return true;
  return isTeacher(user) && (await userTeachesCourse(user.id, course.id));
}
