import type { Metadata } from 'next';
import { StaticPage } from '@/components/StaticPage';

export const metadata: Metadata = { title: 'ক্যারিয়ার — Careers' };

export default function Page() {
  return <StaticPage name="careers" />;
}
