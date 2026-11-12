import { one, q, val } from '@/lib/db';
import { requireUser, ROLE } from '@/lib/auth';
import { courseBy, courseUrl, isFull } from '@/lib/courses';
import { findCoupon, couponDiscount, markOrderPaid, notify } from '@/lib/orders';
import { sslczConfigured, startPayment } from '@/lib/sslcommerz';
import { formAction, fail, flash, rateLimit, int, str, seeOther } from '@/lib/http';
import { priceOf, isFreeCourse } from '@/lib/format';

export const POST = formAction(async (f, req) => {
  const user = await requireUser();
  await rateLimit('checkout', 10);
  const course = await courseBy('id', int(f, 'course_id'));
  if (!course) fail('কোর্সটি পাওয়া যায়নি।', 'Course not found.');
  const c = course!;
  if (await one`SELECT 1 FROM enrollments WHERE user_id = ${user.id} AND course_id = ${c.id} AND status = 'active'`) return `/learn?course=${c.id}`;
  if (isFreeCourse(c)) return courseUrl(c);
  if (isFull(c)) fail('এই কোর্সের সব সিট পূর্ণ।', 'This course is full.');

  const price = priceOf(c)[0];
  const code = str(f, 'coupon', 50);
  const coupon = code ? await findCoupon(code, price) : null;
  if (code && !coupon) fail('কুপনটি সঠিক নয় বা মেয়াদ শেষ।', 'This coupon is invalid or expired.');
  const discount = couponDiscount(coupon, price);
  const total = Math.max(0, price - discount);
  const method = str(f, 'method', 10);

  const createOrder = async (payMethod: string, payRef: string | null, phone: string | null) => {
    const o = await one<{ id: number }>`
      INSERT INTO orders (user_id, subtotal, discount, total, coupon_code, status, pay_method, pay_ref, billing_name, billing_email, billing_phone)
      VALUES (${user.id}, ${price}, ${discount}, ${total}, ${coupon?.code ?? null}, 'pending', ${payMethod}, ${payRef}, ${user.name}, ${user.email}, ${phone ?? user.phone})
      RETURNING id`;
    await q`INSERT INTO order_items (order_id, course_id, title, price) VALUES (${o!.id}, ${c.id}, ${c.title_bn}, ${total})`;
    return o!.id;
  };

  // A coupon that covers the whole fee: enrol right away.
  if (total <= 0) {
    const id = await createOrder('coupon', coupon?.code ?? null, null);
    await markOrderPaid(id, 'coupon', coupon?.code ?? null);
    await flash('success', 'কুপনে ভর্তি সম্পন্ন। স্বাগতম!', 'Enrolled with your coupon. Welcome!');
    return `/learn?course=${c.id}&welcome=1`;
  }

  if (method === 'online') {
    if (!sslczConfigured()) fail('অনলাইন পেমেন্ট এখন চালু নেই — বিকাশ/নগদে পেমেন্ট করুন।', 'Online payment is not available right now — please pay with bKash/Nagad.');
    const orderId = await createOrder('sslcommerz', null, null);
    const site = `${req.headers.get('x-forwarded-proto') ?? new URL(req.url).protocol.replace(':', '')}://${req.headers.get('x-forwarded-host') ?? req.headers.get('host')}`;
    const gateway = await startPayment({ orderId, amount: total, product: c.title_en || c.title_bn, siteUrl: site, name: user.name, email: user.email, phone: user.phone });
    if (!gateway) {
      await q`UPDATE orders SET status = 'failed' WHERE id = ${orderId}`;
      fail('পেমেন্ট গেটওয়েতে যাওয়া যায়নি। আবার চেষ্টা করুন।', 'Could not reach the payment gateway. Please try again.');
    }
    return seeOther(req, gateway!);
  }

  // Manual bKash / Nagad: the student sends money and gives the TrxID; an admin approves.
  const payRef = str(f, 'pay_ref', 40).toUpperCase().replace(/\s+/g, '');
  const payFrom = str(f, 'pay_from', 20).replace(/[^\d+]/g, '');
  if (!/^[A-Z0-9]{6,40}$/.test(payRef)) fail('সঠিক TrxID দিন।', 'Please enter a valid TrxID.');
  if (payFrom.length < 10) fail('যে নম্বর থেকে পাঠিয়েছেন সেটি দিন।', 'Please enter the number you paid from.');
  if (await one`SELECT 1 FROM orders WHERE upper(pay_ref) = ${payRef} AND status IN ('pending', 'paid')`) {
    fail('এই TrxID আগেই জমা দেওয়া হয়েছে।', 'This TrxID has already been submitted.');
  }
  const existing = await val<number>`SELECT o.id FROM orders o JOIN order_items i ON i.order_id = o.id
    WHERE o.user_id = ${user.id} AND i.course_id = ${c.id} AND o.status = 'pending' ORDER BY o.id DESC LIMIT 1`;
  if (existing) {
    await q`UPDATE orders SET pay_method = 'manual', pay_ref = ${payRef}, billing_phone = ${payFrom},
              subtotal = ${price}, discount = ${discount}, total = ${total}, coupon_code = ${coupon?.code ?? null} WHERE id = ${existing}`;
  } else {
    await createOrder('manual', payRef, payFrom);
  }
  const admins = await q<{ id: number }>`SELECT id FROM users WHERE role_id IN (${ROLE.ADMIN}, ${ROLE.OPS}) AND is_active`;
  for (const a of admins) await notify(a.id, 'order', `Payment to verify: ৳${total} — ${user.name}`, `TrxID ${payRef} from ${payFrom}`, '/admin/orders?status=pending');
  await flash('success', 'পেমেন্ট জমা হয়েছে। যাচাই হলেই কোর্স চালু হবে।', 'Payment submitted. Your course opens as soon as we confirm it.');
  return '/orders';
});
