import type { Metadata } from 'next';
import { StaticPage } from '@/components/StaticPage';

export const metadata: Metadata = { title: 'শর্তাবলি — Terms' };

export default function Page() {
  return <StaticPage name="terms" />;
}
