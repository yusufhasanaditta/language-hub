import { T } from '@/lib/i18n';
import { Empty } from '@/components/ui';

export const metadata = { title: 'Access denied', robots: { index: false } };

export default function DeniedPage() {
  return (
    <section className="container lh-narrow lh-list-body" style={{ paddingTop: 48 }}>
      <Empty icon="lock" bn="এই পেজটি দেখার অনুমতি নেই" en="You do not have permission to view this page">
        <a href="/" className="btn btn-primary"><T bn="হোমে ফিরুন" en="Back to home" /></a>
      </Empty>
    </section>
  );
}
