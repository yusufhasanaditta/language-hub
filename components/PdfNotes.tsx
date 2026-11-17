// A class's PDF right under its video: read it on the page, open it full screen, or download it.
// Only rendered inside the classroom, which already checks the student may watch the course.
import { T } from '@/lib/i18n';
import { pdfLinks } from '@/lib/format';
import { Icon } from '@/components/ui';

export function PdfNotes({ url, title, open = true }: { url: string | null | undefined; title: string; open?: boolean }) {
  const p = pdfLinks(url);
  if (!p) return null;
  return (
    <details className="lh-pdf" open={open}>
      <summary>
        <span className="lh-pdf-icon"><Icon name="picture_as_pdf" /></span>
        <span className="lh-pdf-name"><b><T bn="এই ক্লাসের PDF নোট" en="PDF notes for this class" /></b><small>{title}</small></span>
        <span className="lh-pdf-toggle"><Icon name="expand_more" /></span>
      </summary>
      <div className="lh-pdf-actions">
        <a className="btn btn-primary btn-sm" href={p.download} target="_blank" rel="noopener" download><Icon name="download" /><T bn="ডাউনলোড" en="Download" /></a>
        <a className="btn btn-outline btn-sm" href={p.open} target="_blank" rel="noopener"><Icon name="open_in_new" /><T bn="বড় করে পড়ুন" en="Open full screen" /></a>
      </div>
      <div className="lh-pdf-view">
        <iframe src={p.read} title={`PDF — ${title}`} loading="lazy" allow="fullscreen" referrerPolicy="no-referrer"></iframe>
      </div>
    </details>
  );
}
