import type { Metadata } from 'next';
import { StaticPage } from '@/components/StaticPage';

export const metadata: Metadata = { title: 'রিফান্ড নীতি — Refund policy' };

export default function Page() {
  return <StaticPage name="refund" />;
}
