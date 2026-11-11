import { SiteHeader, SiteFooter } from '@/components/site';
import { T } from '@/lib/i18n';
import { Empty } from '@/components/ui';

export default function NotFound() {
  return (
    <>
      <SiteHeader />
      <main className="main-content" id="mainContent">
        <section className="container lh-narrow lh-list-body" style={{ paddingTop: 48 }}>
          <Empty icon="travel_explore" bn="পেজটি পাওয়া যায়নি" en="Page not found">
            <p><T bn="লিংকটি ভুল বা পুরোনো হতে পারে।" en="The link may be wrong or out of date." /></p>
            <a href="/courses" className="btn btn-primary"><T bn="কোর্স দেখুন" en="Browse courses" /></a>
          </Empty>
        </section>
      </main>
      <SiteFooter />
    </>
  );
}
