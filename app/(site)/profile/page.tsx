import type { Metadata } from 'next';
import { requireUser } from '@/lib/auth';
import { T } from '@/lib/i18n';
import { ListHead } from '@/components/ui';
import { Passport, loadPassport } from '@/components/Passport';

export const metadata: Metadata = { title: 'প্রোফাইল এডিট' };

export default async function ProfilePage() {
  const user = await requireUser();
  const pp = await loadPassport(user.id);
  return (
    <>
      <ListHead back={['/dashboard', 'প্রোফাইল', 'Profile']} title={<T bn="প্রোফাইল এডিট" en="Edit profile" />} />
      <section className="container lh-list-body">
        <Passport user={user} data={pp} edit={false} />
        <div className="lh-pp-forms">
        <div className="lh-c-card lh-ppage" id="edit-profile" data-page="03" data-reveal>
          <h2><T bn="আমার তথ্য" en="My details" /></h2>
          <form method="post" action="/do/profile" className="lh-form">
            <label htmlFor="name"><T bn="পুরো নাম" en="Full name" /></label>
            <input id="name" name="name" className="lh-input" required maxLength={100} defaultValue={user.name} />
            <label htmlFor="email"><T bn="ইমেইল" en="Email" /></label>
            <input id="email" className="lh-input" value={user.email ?? ''} disabled readOnly />
            <label htmlFor="phone"><T bn="ফোন" en="Phone" /></label>
            <input id="phone" name="phone" className="lh-input" inputMode="tel" maxLength={15} defaultValue={user.phone ?? ''} placeholder="01XXXXXXXXX" />
            <label htmlFor="address"><T bn="ঠিকানা" en="Address" /></label>
            <input id="address" name="address" className="lh-input" maxLength={500} defaultValue={user.address ?? ''} />
            <label htmlFor="avatar"><T bn="ছবির লিংক (ঐচ্ছিক)" en="Photo URL (optional)" /></label>
            <input id="avatar" name="avatar" type="url" className="lh-input" maxLength={500} defaultValue={user.avatar ?? ''} placeholder="https://…" />
            <label htmlFor="bio"><T bn="নিজের সম্পর্কে" en="About me" /></label>
            <textarea id="bio" name="bio" className="lh-input" rows={3} maxLength={1000} defaultValue={user.bio ?? ''}></textarea>
            <button type="submit" className="btn btn-primary lh-btn-block"><T bn="সেভ করুন" en="Save" /></button>
          </form>
        </div>

        <div className="lh-c-card lh-ppage" id="password" data-page="04" data-reveal>
          <h2>{user.has_password ? <T bn="পাসওয়ার্ড বদলান" en="Change password" /> : <T bn="পাসওয়ার্ড সেট করুন" en="Set a password" />}</h2>
          <form method="post" action="/do/password" className="lh-form">
            {user.has_password && <>
              <label htmlFor="current"><T bn="বর্তমান পাসওয়ার্ড" en="Current password" /></label>
              <input id="current" name="current" type="password" className="lh-input" autoComplete="current-password" required />
            </>}
            <label htmlFor="password"><T bn="নতুন পাসওয়ার্ড (কমপক্ষে ৮ অক্ষর)" en="New password (at least 8 characters)" /></label>
            <input id="password" name="password" type="password" className="lh-input" autoComplete="new-password" minLength={8} required />
            <label htmlFor="confirm"><T bn="আবার লিখুন" en="Repeat new password" /></label>
            <input id="confirm" name="confirm" type="password" className="lh-input" autoComplete="new-password" minLength={8} required />
            <button type="submit" className="btn btn-primary lh-btn-block"><T bn="সেভ করুন" en="Save" /></button>
          </form>
        </div>
        </div>
      </section>
    </>
  );
}
