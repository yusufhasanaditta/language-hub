// Renders a page from content/<name>.html (copy carried over from the PHP site).
import { readFile } from 'node:fs/promises';
import path from 'node:path';
import { getSettings } from '@/lib/settings';

const esc = (s: string) => s.replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]!);

export async function StaticPage({ name }: { name: 'about' | 'terms' | 'privacy' | 'refund' | 'faq' | 'careers' }) {
  const [raw, s] = await Promise.all([
    readFile(path.join(process.cwd(), 'content', `${name}.html`), 'utf8'),
    getSettings(),
  ]);
  // Fill the contact links from Admin → Settings (they were empty on the old site).
  const email = s.contact_email ? `<a href="mailto:${esc(s.contact_email)}">${esc(s.contact_email)}</a>` : '<a href="/contact">our contact form</a>';
  const phone = s.contact_phone ? `<a href="tel:${esc(s.contact_phone.replace(/[^0-9+]/g, ''))}">${esc(s.contact_phone)}</a>` : '<a href="/contact">the contact page</a>';
  const html = raw.replace(/<a href="mailto:"><\/a>/g, email).replace(/<a href="tel:"><\/a>/g, phone);
  return <div dangerouslySetInnerHTML={{ __html: html }} />;
}
