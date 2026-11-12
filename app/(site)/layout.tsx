import { SiteHeader, SiteFooter } from '@/components/site';
import MotionFx from '@/components/MotionFx';

export const dynamic = 'force-dynamic';

export default function SiteLayout({ children }: { children: React.ReactNode }) {
  return (
    <>
      <MotionFx progress />
      <SiteHeader />
      <main className="main-content" id="mainContent">{children}</main>
      <SiteFooter />
    </>
  );
}
