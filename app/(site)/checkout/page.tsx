import type { Metadata } from 'next';
import { redirect } from 'next/navigation';
import { one } from '@/lib/db';
import { requireUser } from '@/lib/auth';
import { courseBy, courseUrl } from '@/lib/courses';
import { findCoupon, couponDiscount } from '@/lib/orders';
import { sslczConfigured } from '@/lib/sslcommerz';
import { setting } from '@/lib/settings';
import { T, N, bnNum } from '@/lib/i18n';
import { formatPrice, priceOf, isFreeCourse } from '@/lib/format';
import { Icon } from '@/components/ui';
import { BoardingPass, TicketPrinter } from '@/components/BoardingPass';
import { seatsLeft } from '@/lib/courses';

export const metadata: Metadata = { title: 'Checkout', robots: { index: false } };

type Props = { searchParams: Promise<{ course?: string; coupon?: string }> };

export default async function CheckoutPage({ searchParams }: Props) {
  const sp = await searchParams;
  const user = await requireUser();
  const course = await courseBy('id', Number(sp.course) || 0);
  if (!course) redirect('/courses');
  if (await one`SELECT 1 FROM enrollments WHERE user_id = ${user.id} AND course_id = ${course.id} AND status = 'active'`) redirect(`/learn?course=${course.id}`);
  if (isFreeCourse(course)) redirect(courseUrl(course));

  const price = priceOf(course)[0];
  const code = (sp.coupon ?? '').trim().toUpperCase().slice(0, 50);
  const coupon = code ? await findCoupon(code, price) : null;
  const discount = couponDiscount(coupon, price);
  const total = Math.max(0, price - discount);
  const online = sslczConfigured();
  const manualNumbers = await setting('manual_pay_numbers');
  const pending = await one`SELECT o.id, o.pay_ref FROM orders o JOIN order_items i ON i.order_id = o.id
                             WHERE o.user_id = ${user.id} AND i.course_id = ${course.id} AND o.status = 'pending' AND o.pay_method = 'manual'
                             ORDER BY o.id DESC LIMIT 1`;
  const left = seatsLeft(course);
  const ticket = { course, passenger: user.name, total, seat: left === 0 ? null : Number(course.student_count ?? 0) + 1 };

  return (
    <section className="container lh-co">
      <a href={courseUrl(course)} className="lh-c-back"><Icon name="arrow_back" /><T bn="কোর্সে ফিরুন" en="Back to course" /></a>
      <h1 className="lh-co-title"><T bn="পেমেন্ট" en="Checkout" /></h1>
      <ol className="lh-co-steps" aria-label="Steps">
        <li className="is-done"><span className="lh-co-n">1</span><T bn="কোর্স" en="Course" /></li>
        <li className="is-on"><span className="lh-co-n">2</span><T bn="পেমেন্ট" en="Payment" /></li>
        <li><span className="lh-co-n">3</span><T bn="ক্লাস শুরু" en="Start" /></li>
      </ol>

      {pending && (
        <div className="lh-c-card" role="status" style={{ marginBottom: 16 }}>
          <p style={{ margin: 0 }}><Icon name="hourglass_top" /> <T bn={`আপনার পেমেন্ট (TrxID ${pending.pay_ref ?? '—'}) যাচাই করা হচ্ছে। যাচাই হলেই কোর্স চালু হবে।`}
            en={`Your payment (TrxID ${pending.pay_ref ?? '—'}) is being checked. The course opens as soon as it is approved.`} /> <a href="/orders" className="lh-link"><T bn="অর্ডার দেখুন" en="View orders" /></a></p>
        </div>
      )}

      <div className="lh-co-grid">
        <div className="lh-co-main">
          <div data-reveal><BoardingPass {...ticket} /></div>

          <form method="get" action="/checkout" className="lh-co-coupon" data-reveal>
            <input type="hidden" name="course" value={course.id} />
            <label htmlFor="coupon" className="lh-co-label"><Icon name="sell" /><T bn="কুপন কোড আছে?" en="Have a coupon?" /></label>
            <div className="lh-co-coupon-row">
              <input type="text" id="coupon" name="coupon" className="lh-input" defaultValue={code} autoComplete="off" autoCapitalize="characters" placeholder="LH10" />
              <button type="submit" className="btn btn-outline"><T bn="প্রয়োগ" en="Apply" /></button>
            </div>
            {code && !coupon && <p className="lh-auth-error" role="alert"><T bn="কুপনটি সঠিক নয় বা মেয়াদ শেষ।" en="This coupon is invalid or expired." /></p>}
            {coupon && <p className="lh-c-save"><T bn="কুপন প্রয়োগ হয়েছে!" en="Coupon applied!" /></p>}
          </form>
        </div>

        <aside className="lh-co-side">
          <div className="lh-co-sum" data-reveal="right">
            <div className="lh-co-line"><span><T bn="কোর্স ফি" en="Course fee" /></span><span>৳<N v={formatPrice(price)} /></span></div>
            {discount > 0 && <div className="lh-co-line lh-co-disc"><span><T bn="কুপন" en="Coupon" /> ({coupon!.code})</span><span>−৳<N v={formatPrice(discount)} /></span></div>}
            <div className="lh-co-line lh-co-total"><span><T bn="মোট" en="Total" /></span><span className="lh-price">৳<N v={formatPrice(total)} /></span></div>

            {total <= 0 || online ? (
              <form method="post" action="/do/checkout" data-ticket-buy>
                <input type="hidden" name="course_id" value={course.id} />
                <input type="hidden" name="coupon" value={coupon ? coupon.code : ''} />
                <input type="hidden" name="method" value="online" />
                <button type="submit" className="btn btn-accent lh-btn-block lh-co-pay" data-busy>
                  <Icon name="lock" />
                  {total <= 0 ? <T bn="ফ্রি ভর্তি সম্পন্ন করুন" en="Complete free enrolment" />
                    : <T bn={`৳${bnNum(formatPrice(total))} পেমেন্ট করুন`} en={`Pay ৳${formatPrice(total)}`} />}
                </button>
              </form>
            ) : null}

            {total > 0 && (
              <div className={online ? 'lh-manual-pay' : ''}>
                {online && <p className="lh-co-secure"><Icon name="verified_user" /><T bn="SSLCommerz-এর মাধ্যমে নিরাপদ পেমেন্ট" en="Secure payment by SSLCommerz" /></p>}
                <h3 style={{ fontSize: '1rem', margin: '8px 0' }}><T bn="বিকাশ / নগদে পেমেন্ট" en="Pay with bKash / Nagad" /></h3>
                <p>{manualNumbers
                  ? <T bn={`নিচের নম্বরে ৳${bnNum(formatPrice(total))} Send Money করুন, তারপর TrxID দিন:\n${manualNumbers}`} en={`Send ৳${formatPrice(total)} with Send Money to the number below, then enter the TrxID:\n${manualNumbers}`} />
                  : <T bn="পেমেন্ট নম্বর জানতে আমাদের সাথে যোগাযোগ করুন, তারপর TrxID দিন।" en="Contact us for the payment number, then enter your TrxID here." />}</p>
                <form method="post" action="/do/checkout" className="lh-form" data-ticket-buy="manual">
                  <input type="hidden" name="course_id" value={course.id} />
                  <input type="hidden" name="coupon" value={coupon ? coupon.code : ''} />
                  <input type="hidden" name="method" value="manual" />
                  <label htmlFor="pay_from"><T bn="যে নম্বর থেকে পাঠিয়েছেন" en="Number you paid from" /></label>
                  <input id="pay_from" name="pay_from" className="lh-input" inputMode="tel" maxLength={20} required defaultValue={user.phone ?? ''} placeholder="01XXXXXXXXX" />
                  <label htmlFor="pay_ref">TrxID</label>
                  <input id="pay_ref" name="pay_ref" className="lh-input" maxLength={40} required placeholder="8N7A6D5E4F" autoCapitalize="characters" />
                  <button type="submit" className="btn btn-primary lh-btn-block" data-busy><T bn="পেমেন্ট জমা দিন" en="Submit payment" /></button>
                </form>
              </div>
            )}
            <ul className="lh-c-perks">
              <li><Icon name="bolt" /><T bn="পেমেন্ট যাচাই হলেই ক্লাসরুম খুলে যাবে" en="Classroom opens as soon as payment is confirmed" /></li>
              <li><Icon name="receipt_long" /><T bn="ইনভয়েস আপনার প্রোফাইলে থাকবে" en="Invoice saved in your profile" /></li>
            </ul>
          </div>
        </aside>
      </div>
      <TicketPrinter {...ticket} />
    </section>
  );
}
