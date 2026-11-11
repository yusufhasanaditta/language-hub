import { one, q, raw } from '@/lib/db';
import { requireUser } from '@/lib/auth';
import { formAction, fail, flash, int, str } from '@/lib/http';

export const POST = formAction(async f => {
  const user = await requireUser();
  const courseId = int(f, 'course_id');
  const rating = int(f, 'rating');
  if (rating < 1 || rating > 5) fail('রেটিং দিন।', 'Please choose a rating.');
  if (!(await one`SELECT 1 FROM enrollments WHERE user_id = ${user.id} AND course_id = ${courseId} AND status = 'active'`)) {
    fail('শুধু ভর্তি হওয়া শিক্ষার্থীরা মতামত দিতে পারেন।', 'Only enrolled students can review this course.');
  }
  await q`INSERT INTO reviews (course_id, user_id, rating, body) VALUES (${courseId}, ${user.id}, ${rating}, ${str(f, 'body', 1000) || null})
          ON CONFLICT (course_id, user_id) DO UPDATE SET rating = EXCLUDED.rating, body = EXCLUDED.body, created_at = now()`;
  await raw(`UPDATE courses SET rating_avg = COALESCE((SELECT avg(rating) FROM reviews WHERE course_id = $1 AND is_approved), 0),
             rating_count = (SELECT count(*) FROM reviews WHERE course_id = $1 AND is_approved) WHERE id = $1`, [courseId]);
  await flash('success', 'ধন্যবাদ! আপনার মতামত যোগ হয়েছে।', 'Thank you! Your review was added.');
});
