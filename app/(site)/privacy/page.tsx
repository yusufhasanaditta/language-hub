import type { Metadata } from 'next';
import { StaticPage } from '@/components/StaticPage';

export const metadata: Metadata = { title: 'গোপনীয়তা নীতি — Privacy' };

export default function Page() {
  return <StaticPage name="privacy" />;
}
