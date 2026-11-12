// Free courses: enrolling is a POST from the course page, never a side effect of opening a URL.
import { requireUser } from '@/lib/auth';
import { courseBy, isFull } from '@/lib/courses';
import { enroll, notify } from '@/lib/orders';
import { formAction, fail, flash, int, rateLimit } from '@/lib/http';
import { isFreeCourse } from '@/lib/format';

export const POST = formAction(async f => {
  const user = await requireUser();
  await rateLimit('enroll', 20);
  const c = await courseBy('id', int(f, 'course_id'));
  if (!c) fail('কোর্সটি পাওয়া যায়নি।', 'Course not found.');
  if (!isFreeCourse(c!)) return `/checkout?course=${c!.id}`;
  if (isFull(c!)) fail('এই কোর্সের সব সিট পূর্ণ।', 'This course is full.');
  await enroll(user.id, c!.id);
  await notify(user.id, 'enrollment', `Enrolled in ${c!.title_bn}`, 'You now have full access to this course. Happy learning!', `/learn?course=${c!.id}`);
  await flash('success', 'ভর্তি সম্পন্ন। স্বাগতম!', 'You are enrolled. Welcome!');
  return `/learn?course=${c!.id}&welcome=1`;
});
