// Classroom "Mark as done" (called by lh.js).
import { NextResponse, type NextRequest } from 'next/server';
import { one, q } from '@/lib/db';
import { currentUser } from '@/lib/auth';
import { refreshProgress } from '@/lib/courses';
import { sameOrigin } from '@/lib/http';

export async function POST(req: NextRequest) {
  if (!sameOrigin(req)) return NextResponse.json({ success: false }, { status: 403 });
  const user = await currentUser();
  if (!user) return NextResponse.json({ success: false, message: 'Login required' }, { status: 401 });
  const body = await req.json().catch(() => ({}));
  const lessonId = Number(body.lesson_id) || 0;
  // The lesson must belong to a course this student is enrolled in.
  const e = await one<{ id: number }>`
    SELECT e.id FROM lessons l JOIN modules m ON m.id = l.module_id
    JOIN enrollments e ON e.course_id = m.course_id AND e.user_id = ${user.id} AND e.status = 'active'
    WHERE l.id = ${lessonId}`;
  if (!e) return NextResponse.json({ success: false, message: 'Not enrolled' }, { status: 403 });
  if (body.completed !== false) {
    await q`INSERT INTO lesson_progress (enrollment_id, lesson_id, completed, completed_at) VALUES (${e.id}, ${lessonId}, true, now())
            ON CONFLICT (enrollment_id, lesson_id) DO UPDATE SET completed = true, completed_at = COALESCE(lesson_progress.completed_at, now())`;
    await refreshProgress(e.id);
  }
  const pct = await one<{ progress_pct: number }>`SELECT progress_pct FROM enrollments WHERE id = ${e.id}`;
  return NextResponse.json({ success: true, data: { progress: pct?.progress_pct ?? 0 } });
}
