import type { Metadata } from 'next';
import { CourseList, type ListParams } from '@/components/CourseList';

export const metadata: Metadata = { title: 'সব কোর্স' };

export default async function CoursesPage({ searchParams }: { searchParams: Promise<ListParams> }) {
  const p = await searchParams;
  // Old links used ?category=slug on /courses.
  return <CourseList params={p} basePath={p.category ? `/category/${p.category}` : '/courses'} />;
}
