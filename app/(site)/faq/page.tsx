import type { Metadata } from 'next';
import { StaticPage } from '@/components/StaticPage';

export const metadata: Metadata = { title: 'প্রশ্নোত্তর — FAQ' };

export default function Page() {
  return <StaticPage name="faq" />;
}
