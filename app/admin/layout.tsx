import { requireAdmin } from '@/lib/auth';
import { PanelShell, ConfirmScript } from '@/components/panel';

export const dynamic = 'force-dynamic';
export const metadata = { title: { default: 'Admin', template: '%s — Admin — Language Hub' }, robots: { index: false } };

export default async function AdminLayout({ children }: { children: React.ReactNode }) {
  const user = await requireAdmin();
  return <PanelShell user={user} kind="admin">{children}<ConfirmScript /></PanelShell>;
}
