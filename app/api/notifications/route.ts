// Notification bell: unread count (polled by main.js) and mark-as-read.
import { NextResponse, type NextRequest } from 'next/server';
import { q, val } from '@/lib/db';
import { currentUser } from '@/lib/auth';
import { sameOrigin } from '@/lib/http';

export async function GET() {
  const user = await currentUser();
  if (!user) return NextResponse.json({ success: false }, { status: 401 });
  const unread = await val<number>`SELECT count(*)::int FROM notifications WHERE user_id = ${user.id} AND read_at IS NULL`;
  return NextResponse.json({ success: true, data: { unread } });
}

export async function POST(req: NextRequest) {
  // sendBeacon posts carry no Origin on some browsers; Sec-Fetch-Site covers them.
  if (!sameOrigin(req)) return NextResponse.json({ success: false }, { status: 403 });
  const user = await currentUser();
  if (!user) return NextResponse.json({ success: false }, { status: 401 });
  const body = await req.json().catch(() => ({}));
  if (body.action === 'mark_all_read') {
    await q`UPDATE notifications SET read_at = now() WHERE user_id = ${user.id} AND read_at IS NULL`;
  } else if (body.action === 'mark_read') {
    await q`UPDATE notifications SET read_at = now() WHERE id = ${Number(body.id) || 0} AND user_id = ${user.id} AND read_at IS NULL`;
  }
  return NextResponse.json({ success: true });
}
