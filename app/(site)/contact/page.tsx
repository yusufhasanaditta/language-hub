import type { Metadata } from 'next';
import { getSettings } from '@/lib/settings';
import { currentUser } from '@/lib/auth';
import { T } from '@/lib/i18n';
import { Icon, ListHead } from '@/components/ui';

export const metadata: Metadata = { title: 'যোগাযোগ — Contact' };

export default async function ContactPage() {
  const [s, user] = await Promise.all([getSettings(), currentUser()]);
  return (
    <>
      <ListHead title={<T bn="যোগাযোগ করুন" en="Contact us" />} />
      <section className="container lh-narrow lh-list-body">
        <div className="lh-c-card" data-reveal>
          <p><T bn="প্রশ্ন, ভর্তি বা পেমেন্ট নিয়ে কিছু জানতে চাইলে লিখুন — আমরা দ্রুত উত্তর দেব।" en="Questions about courses, admission or payment? Write to us and we will reply soon." /></p>
          {s.contact_phone && <p className="footer-contact"><Icon name="call" /> <a href={`tel:${s.contact_phone.replace(/[^0-9+]/g, '')}`}>{s.contact_phone}</a></p>}
          {s.contact_email && <p className="footer-contact"><Icon name="mail" /> <a href={`mailto:${s.contact_email}`}>{s.contact_email}</a></p>}
          <form method="post" action="/do/contact" className="lh-form">
            <label htmlFor="name"><T bn="নাম" en="Name" /></label>
            <input id="name" name="name" className="lh-input" required maxLength={150} defaultValue={user?.name ?? ''} />
            <label htmlFor="email"><T bn="ইমেইল" en="Email" /></label>
            <input id="email" name="email" type="email" className="lh-input" required maxLength={190} defaultValue={user?.email ?? ''} />
            <label htmlFor="phone"><T bn="ফোন (ঐচ্ছিক)" en="Phone (optional)" /></label>
            <input id="phone" name="phone" className="lh-input" maxLength={30} inputMode="tel" />
            <label htmlFor="subject"><T bn="বিষয়" en="Subject" /></label>
            <input id="subject" name="subject" className="lh-input" maxLength={200} />
            <label htmlFor="message"><T bn="বার্তা" en="Message" /></label>
            <textarea id="message" name="message" className="lh-input" rows={5} required maxLength={5000}></textarea>
            {/* Bots fill every field; people never see this one. */}
            <input type="text" name="website" tabIndex={-1} autoComplete="off" style={{ position: 'absolute', left: '-9999px' }} aria-hidden="true" />
            <button type="submit" className="btn btn-primary lh-btn-block"><T bn="পাঠান" en="Send" /> <Icon name="send" /></button>
          </form>
        </div>
      </section>
    </>
  );
}
