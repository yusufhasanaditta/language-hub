import type { Metadata } from 'next';
import { notFound } from 'next/navigation';
import { one } from '@/lib/db';
import { CourseList, type ListParams } from '@/components/CourseList';

type Props = { params: Promise<{ slug: string }>; searchParams: Promise<ListParams> };

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const cat = await one`SELECT name_bn, name_en FROM categories WHERE slug = ${(await params).slug}`;
  return { title: cat ? `${cat.name_bn} শিখুন — Learn ${cat.name_en}` : 'Courses' };
}

export default async function CategoryPage({ params, searchParams }: Props) {
  const { slug } = await params;
  if (!(await one`SELECT id FROM categories WHERE slug = ${slug}`)) notFound();
  return <CourseList params={{ ...(await searchParams), category: slug }} basePath={`/category/${slug}`} />;
}
