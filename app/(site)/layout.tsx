import { SiteHeader, SiteFooter } from '@/components/site';

export const dynamic = 'force-dynamic';

export default function SiteLayout({ children }: { children: React.ReactNode }) {
  return (
    <>
      <SiteHeader />
      <main className="main-content" id="mainContent">{children}</main>
      <SiteFooter />
    </>
  );
}
