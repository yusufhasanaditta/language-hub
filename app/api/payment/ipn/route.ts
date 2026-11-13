// SSLCommerz IPN: the gateway's server calls this after every payment, even if the buyer
// closed the browser before returning. Same rule as the callback: only a server-side
// validation of val_id can mark an order paid.
import type { NextRequest } from 'next/server';
import { settleFromGateway } from '@/lib/sslcommerz';

export async function POST(req: NextRequest) {
  const f = await req.formData().catch(() => null);
  const orderId = Number(f?.get('value_a')) || 0;
  if (!orderId) return new Response('missing order', { status: 400 });
  const result = await settleFromGateway(orderId, String(f?.get('status') ?? '').toUpperCase(), String(f?.get('val_id') ?? ''));
  return new Response(result, { status: result === 'unknown' ? 404 : 200 });
}
