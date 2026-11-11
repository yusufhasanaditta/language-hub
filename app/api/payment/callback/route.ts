// SSLCommerz posts here (cross-site) after a payment. Access is granted only from
// the gateway's server-side validation and our own database — never from the POST body.
import { NextResponse, type NextRequest } from 'next/server';
import { one } from '@/lib/db';
import { markOrderPaid } from '@/lib/orders';
import { validatePayment, sslczConfigured } from '@/lib/sslcommerz';

const go = (req: NextRequest, path: string) => NextResponse.redirect(new URL(path, req.url), 303);

export async function POST(req: NextRequest) {
  if (!sslczConfigured()) return go(req, '/orders');
  const f = await req.formData();
  const status = String(f.get('status') ?? '');
  const valId = String(f.get('val_id') ?? '');
  const orderId = Number(f.get('value_a')) || 0;
  const order = orderId ? await one<{ id: number; total: string; status: string }>`SELECT id, total, status FROM orders WHERE id = ${orderId}` : null;
  if (!order) return go(req, '/orders?pay=error');
  if (order.status === 'paid') return go(req, '/my-courses');
  if (status !== 'VALID' || !valId) return go(req, '/orders?pay=failed');

  const v = await validatePayment(valId);
  const ok = v && (v.status === 'VALID' || v.status === 'VALIDATED')
    && v.currency === 'BDT'
    && Math.abs(Number(v.amount) - Number(order.total)) < 0.01
    && Number(v.value_a) === orderId
    && v.tran_id.endsWith(`_${orderId}`);
  if (!ok) return go(req, '/orders?pay=failed');

  await markOrderPaid(orderId, 'sslcommerz', v!.tran_id);
  return go(req, '/my-courses?paid=1');
}
