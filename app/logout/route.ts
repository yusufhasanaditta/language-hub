import type { NextRequest } from 'next/server';
import { endSession } from '@/lib/auth';
import { sameOrigin, seeOther } from '@/lib/http';

// Logout links live in menus, so this is a GET — but only same-site clicks count
// (another website cannot log people out by embedding the link).
export async function GET(req: NextRequest) {
  if (sameOrigin(req)) await endSession();
  return seeOther(req, '/');
}

export const POST = GET;
