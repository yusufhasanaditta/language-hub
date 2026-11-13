// SSLCommerz sends the buyer back here (a cross-site POST) after paying, failing or cancelling.
// Access is granted only from the gateway's server-side validation — never from the POST body.
import type { NextRequest } from 'next/server';
import { flash, seeOther } from '@/lib/http';
import { settleFromGateway } from '@/lib/sslcommerz';

export async function POST(req: NextRequest) {
  const f = await req.formData().catch(() => null);
  const status = String(f?.get('status') ?? '').toUpperCase();
  const orderId = Number(f?.get('value_a')) || 0;
  const result = orderId ? await settleFromGateway(orderId, status, String(f?.get('val_id') ?? '')) : 'unknown';

  if (result === 'paid') {
    await flash('success', 'পেমেন্ট সফল! আপনার কোর্স চালু হয়েছে।', 'Payment successful! Your course is now open.');
    return seeOther(req, '/my-courses?paid=1');
  }
  if (status === 'CANCELLED') await flash('info', 'পেমেন্ট বাতিল করা হয়েছে। আবার চেষ্টা করতে পারেন।', 'Payment cancelled. You can try again.');
  else await flash('error', 'পেমেন্ট সম্পন্ন হয়নি। টাকা কেটে থাকলে আমাদের জানান — যাচাই করে কোর্স চালু করে দেব।',
    'The payment did not go through. If money was taken, contact us and we will open the course.');
  return seeOther(req, '/orders');
}

// Some banks return the buyer with a GET.
export const GET = (req: NextRequest) => seeOther(req, '/orders');
