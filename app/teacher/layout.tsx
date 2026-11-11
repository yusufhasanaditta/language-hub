import { requireTeacher } from '@/lib/auth';
import { PanelShell, ConfirmScript } from '@/components/panel';

export const dynamic = 'force-dynamic';
export const metadata = { title: { default: 'Teacher', template: '%s — Teacher — Language Hub' }, robots: { index: false } };

export default async function TeacherLayout({ children }: { children: React.ReactNode }) {
  const user = await requireTeacher();
  return <PanelShell user={user} kind="teacher">{children}<ConfirmScript /></PanelShell>;
}
