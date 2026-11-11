import { q } from '@/lib/db';
import { requireUser } from '@/lib/auth';
import { formAction } from '@/lib/http';

export const POST = formAction(async () => {
  const user = await requireUser();
  await q`UPDATE notifications SET read_at = now() WHERE user_id = ${user.id} AND read_at IS NULL`;
  return '/notifications';
});
