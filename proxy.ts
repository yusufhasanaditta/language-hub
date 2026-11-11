// Runs before every page request: passes the current URL to server components
// (used for ?next= after login and for highlighting the active menu item).
import { NextResponse, type NextRequest } from 'next/server';

export function proxy(req: NextRequest) {
  const headers = new Headers(req.headers);
  headers.set('x-url', req.nextUrl.pathname + req.nextUrl.search);
  return NextResponse.next({ request: { headers } });
}

export const config = {
  matcher: ['/((?!_next/|assets/|image/|sw\\.js|favicon).*)'],
};
