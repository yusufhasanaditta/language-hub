import type { Metadata } from 'next';
import { StaticPage } from '@/components/StaticPage';

export const metadata: Metadata = { title: 'আমাদের সম্পর্কে — About' };

export default function Page() {
  return <StaticPage name="about" />;
}
