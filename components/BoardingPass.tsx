// Checkout ticket: the course as a boarding pass (passenger, route, seat, date) with a tear-off stub,
// and the "ticket printer" overlay that plays when the buyer presses Pay (see lh.js → data-ticket-buy).
import type { Row } from '@/lib/db';
import { T, N, DateBn } from '@/lib/i18n';
import { lang, langStyle } from '@/lib/langs';
import { formatPrice } from '@/lib/format';
import { Icon } from './ui';

export function BoardingPass({ course, passenger, total, seat }: { course: Row; passenger: string; total: number; seat: number | null }) {
  const L = lang(course.cat_slug);
  const flight = `LH ${String(course.id).padStart(3, '0')}`;
  const live = course.type === 'live';
  return (
    <article className="lh-bp" style={langStyle(course.cat_slug)} data-bp>
      <div className="lh-bp-main">
        <header className="lh-bp-head">
          <span><Icon name="flight_takeoff" /> Language Hub Air</span>
          <span className="lh-bp-flight">{flight}</span>
        </header>
        <div className="lh-bp-route">
          <div><small><T bn="থেকে" en="From" /></small><b>বাংলা</b></div>
          <span className="lh-bp-plane" aria-hidden="true"><Icon name="flight" /></span>
          <div className="lh-bp-to"><small><T bn="গন্তব্য" en="To" /></small><b>{L.native || course.cat_name_en}</b></div>
        </div>
        <h2 className="lh-bp-title"><T bn={course.title_bn} en={course.title_en || course.title_bn} /></h2>
        <dl className="lh-bp-grid">
          <div><dt><T bn="যাত্রী" en="Passenger" /></dt><dd>{passenger}</dd></div>
          <div><dt><T bn="ক্লাস" en="Class" /></dt><dd>{live ? <T bn="লাইভ" en="Live" /> : <T bn="রেকর্ডেড" en="Recorded" />}</dd></div>
          <div><dt><T bn="সিট" en="Seat" /></dt><dd>{seat ? <><N v={seat} />{Number(course.max_students) > 0 && <>/<N v={course.max_students} /></>}</> : '—'}</dd></div>
          <div><dt><T bn="যাত্রা" en="Departs" /></dt><dd>{live && course.batch_date ? <DateBn date={course.batch_date} /> : <T bn="যেকোনো সময়" en="Any time" />}</dd></div>
        </dl>
        {course.teacher_name && <p className="lh-bp-crew"><Icon name="badge" /><T bn="শিক্ষক" en="Teacher" />: {course.teacher_name}</p>}
        <span className="lh-bp-stamp" aria-hidden="true">
          <span className="lh-bp-stamp-ok"><T bn="বুকড" en="Booked" /></span>
          <span className="lh-bp-stamp-wait"><T bn="যাচাই হচ্ছে" en="Checking" /></span>
        </span>
      </div>
      <div className="lh-bp-stub">
        <span className="lh-bp-glyph" aria-hidden="true">{L.glyph}</span>
        <small><T bn="মোট" en="Total" /></small>
        <b>৳<N v={formatPrice(total)} /></b>
        <span className="lh-barcode" aria-hidden="true"></span>
        <span className="lh-bp-code" aria-hidden="true">{flight.replace(' ', '')}-{seat ?? 0}</span>
      </div>
    </article>
  );
}

/** Full-screen "printing your ticket" scene, shown by lh.js while the payment form submits. */
export function TicketPrinter(props: { course: Row; passenger: string; total: number; seat: number | null }) {
  return (
    <div className="lh-print" hidden data-ticket-printer role="status" aria-live="polite">
      <div className="lh-print-box">
        <div className="lh-print-slot" aria-hidden="true"><i></i></div>
        <div className="lh-print-paper"><BoardingPass {...props} /></div>
        <p className="lh-print-msg">
          <span data-step="1"><T bn="আপনার টিকিট প্রিন্ট হচ্ছে…" en="Printing your ticket…" /></span>
          <span data-step="2"><T bn="সিট বুক হলো! বোর্ডিং শুরু…" en="Seat booked! Boarding…" /></span>
          <span data-step="2m"><T bn="পেমেন্ট জমা হলো — যাচাই হলেই সিট নিশ্চিত হবে।" en="Payment sent — your seat is confirmed once we check it." /></span>
        </p>
      </div>
    </div>
  );
}
