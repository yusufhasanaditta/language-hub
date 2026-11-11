import { requireTeacherProfile } from '@/lib/teacher';
import { PageHead } from '@/components/panel';

export const metadata = { title: 'My public profile' };

export default async function TeacherProfile() {
  const { user, tp } = await requireTeacherProfile();
  const t = tp;
  return (
    <>
      <PageHead title="My public profile" sub={user.username ? <>Shown at <a className="pn-link" href={`/t/${user.username}`} target="_blank">/t/{user.username} ↗</a> and on your course pages.</> : 'Choose a username to get a public profile page.'} />
      <div className="pn-card">
        <form method="post" action="/do/teacher/profile" className="pn-grid">
          <div><label>Username (profile link)</label><input name="username" className="form-control" maxLength={80} pattern="[a-z0-9-]{3,80}" defaultValue={user.username ?? ''} placeholder="your-name" /></div>
          <div><label>Designation</label><input name="designation" className="form-control" maxLength={100} defaultValue={t.designation ?? ''} placeholder="Japanese Instructor (JLPT N2)" /></div>
          <div className="pn-full"><label>Headline</label><input name="headline" className="form-control" maxLength={255} defaultValue={t.headline ?? ''} /></div>
          <div className="pn-full"><label>Bio</label><textarea name="bio" className="form-control" maxLength={3000} defaultValue={t.bio ?? ''}></textarea></div>
          <div><label>Expertise</label><input name="expertise" className="form-control" maxLength={500} defaultValue={t.expertise ?? ''} /></div>
          <div><label>Qualifications</label><textarea name="qualifications" className="form-control" maxLength={2000} defaultValue={t.qualifications ?? ''}></textarea></div>
          <div><label>Photo URL</label><input name="avatar" type="url" className="form-control" maxLength={500} defaultValue={user.avatar ?? ''} /></div>
          <div><label>Facebook URL</label><input name="fb_url" type="url" className="form-control" maxLength={300} defaultValue={t.fb_url ?? ''} /></div>
          <div><label>YouTube URL</label><input name="yt_url" type="url" className="form-control" maxLength={300} defaultValue={t.yt_url ?? ''} /></div>
          <div><label>Website</label><input name="website" type="url" className="form-control" maxLength={300} defaultValue={t.website ?? ''} /></div>
          <div className="pn-full"><button className="btn btn-primary" type="submit">Save profile</button></div>
        </form>
      </div>
    </>
  );
}
