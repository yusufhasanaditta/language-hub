// Enrolment and payment: the only places that grant course access.
import { one, q, val } from './db';

export async function notify(userId: number, kind: string, title: string, body: string | null = null, url: string | null = null) {
  await q`INSERT INTO notifications (user_id, kind, title, body, action_url) VALUES (${userId}, ${kind}, ${title}, ${body}, ${url})`;
}

/** Gives a user active access to a course (idempotent). */
export async function enroll(userId: number, courseId: number, orderId: number | null = null) {
  await q`INSERT INTO enrollments (user_id, course_id, order_id, status)
          VALUES (${userId}, ${courseId}, ${orderId}, 'active')
          ON CONFLICT (user_id, course_id) DO UPDATE
          SET status = 'active', order_id = COALESCE(EXCLUDED.order_id, enrollments.order_id)`;
}

/**
 * Marks an order paid and enrols the buyer. The pending→paid flip is atomic,
 * so a repeated gateway callback or a double click can never enrol twice.
 * Returns false when the order was already paid (or does not exist).
 */
export async function markOrderPaid(orderId: number, method: string, ref: string | null) {
  const order = await one<{ id: number; user_id: number; coupon_code: string | null }>`
    UPDATE orders o SET status = 'paid', paid_at = now(), pay_method = ${method}, pay_ref = COALESCE(${ref}, o.pay_ref),
           invoice_no = COALESCE(o.invoice_no, 'LH-' || to_char(now(), 'YYYY') || '-' || lpad(o.id::text, 6, '0')),
           billing_name = COALESCE(o.billing_name, u.name), billing_email = COALESCE(o.billing_email, u.email),
           billing_phone = COALESCE(o.billing_phone, u.phone)
    FROM users u
    WHERE o.id = ${orderId} AND o.status <> 'paid' AND u.id = o.user_id
    RETURNING o.id, o.user_id, o.coupon_code`;
  if (!order) return false;

  const items = await q<{ course_id: number }>`SELECT course_id FROM order_items WHERE order_id = ${orderId} AND course_id IS NOT NULL`;
  for (const it of items) await enroll(order.user_id, it.course_id, orderId);
  if (order.coupon_code) await q`UPDATE coupons SET used_count = used_count + 1 WHERE upper(code) = upper(${order.coupon_code})`;
  const invoice = await val<string>`SELECT invoice_no FROM orders WHERE id = ${orderId}`;
  await notify(order.user_id, 'payment', `Payment received — invoice ${invoice}`,
    'Your enrolment is active. Tap to view the invoice.', `/invoice/${orderId}`);
  return true;
}

export type Coupon = { id: number; code: string; type: 'percent' | 'flat'; value: string };

/** A coupon that is valid right now for this amount, or null. */
export async function findCoupon(code: string, amount: number) {
  if (!code) return null;
  return one<Coupon>`
    SELECT id, code, type, value FROM coupons
    WHERE upper(code) = upper(${code}) AND is_active
      AND (expires_at IS NULL OR expires_at >= (now() AT TIME ZONE 'Asia/Dhaka')::date)
      AND (max_uses = 0 OR used_count < max_uses)
      AND min_order <= ${amount}`;
}

export function couponDiscount(c: Coupon | null, price: number) {
  if (!c) return 0;
  const v = Number(c.value);
  return Math.min(price, c.type === 'percent' ? Math.round(price * v) / 100 : v);
}
