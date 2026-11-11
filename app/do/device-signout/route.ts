import { q } from '@/lib/db';
import { requireUser } from '@/lib/auth';
import { formAction, flash, int } from '@/lib/http';

export const POST = formAction(async f => {
  const user = await requireUser();
  const id = int(f, 'session_id');
  if (id && id !== user.session_id) {
    await q`DELETE FROM user_sessions WHERE id = ${id} AND user_id = ${user.id}`;
    await flash('success', 'ডিভাইসটি থেকে লগআউট করা হয়েছে।', 'That device has been signed out.');
  }
  return '/dashboard#devices';
});
