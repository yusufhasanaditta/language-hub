// Modules + lessons editor (admin and the course's teacher), plus live classes.
import type { Row } from '@/lib/db';
import type { Module } from '@/lib/courses';
import { toLocalInput, fmtDateTime } from '@/lib/format';
import { ActionButton, Badge } from './panel';
import { Icon } from './ui';

const KINDS = [['video', 'Video (YouTube / Vimeo link)'], ['pdf', 'PDF link'], ['text', 'Text lesson'], ['live', 'Live class note']];

function LessonFields({ l }: { l?: Row }) {
  return (
    <div className="pn-grid">
      <div className="pn-full"><label>Lesson title</label><input name="title" className="form-control" required maxLength={200} defaultValue={l?.title ?? ''} /></div>
      <div><label>Type</label>
        <select name="kind" className="form-control" defaultValue={l?.kind ?? 'video'}>{KINDS.map(([k, n]) => <option key={k} value={k}>{n}</option>)}</select></div>
      <div><label>Duration (minutes)</label><input name="duration" type="number" min={0} max={1000} className="form-control" defaultValue={l?.duration ?? 0} /></div>
      <div className="pn-full"><label>Video URL</label><input name="video_url" type="url" className="form-control" maxLength={500} defaultValue={l?.video_url ?? ''} placeholder="https://youtu.be/…" /></div>
      <div className="pn-full"><label>PDF URL</label><input name="pdf_url" type="url" className="form-control" maxLength={500} defaultValue={l?.pdf_url ?? ''} /></div>
      <div className="pn-full"><label>Text content</label><textarea name="content" className="form-control" maxLength={20000} defaultValue={l?.content ?? ''}></textarea></div>
      <label className="pn-check"><input type="checkbox" name="is_free" value="1" defaultChecked={l?.is_free ?? false} /> Free preview</label>
      <label className="pn-check"><input type="checkbox" name="is_published" value="1" defaultChecked={l?.is_published ?? true} /> Published</label>
    </div>
  );
}

export function CurriculumEditor({ courseId, modules, base }: { courseId: number; modules: Module[]; base: string }) {
  const act = (a: string) => `${base}/${a}`;
  return (
    <div className="pn-card" id="curriculum">
      <h2>Curriculum</h2>
      {modules.length === 0 && <p className="pn-muted">No modules yet. Add the first one below.</p>}
      {modules.map((m, mi) => (
        <div key={m.id} className="pn-mod">
          <div className="pn-mod-head">
            <form method="post" action={act('module-save')} className="pn-inline" style={{ flex: 1 }}>
              <input type="hidden" name="course_id" value={courseId} />
              <input type="hidden" name="id" value={m.id} />
              <b>{mi + 1}.</b>
              <input name="title" className="form-control" defaultValue={m.title} required maxLength={200} style={{ flex: 1, minWidth: 160 }} />
              <input name="sort_order" type="number" className="form-control" defaultValue={m.sort_order} style={{ width: 70 }} title="Order" />
              <button className="btn btn-outline btn-xs" type="submit">Save</button>
            </form>
            <ActionButton action={act('module-delete')} fields={{ course_id: courseId, id: m.id }} label="Delete" cls="btn btn-outline btn-xs pn-danger" confirm="Delete this module and all its lessons?" />
          </div>
          <ol>
            {m.lessons.map(l => (
              <li key={l.id}>
                <Icon name={l.kind === 'pdf' ? 'description' : l.kind === 'text' ? 'article' : 'play_circle'} />
                <span className="pn-grow">{l.title}{' '}
                  <span className="pn-muted">{l.duration ? `${l.duration}m` : ''}{l.is_free ? ' · free preview' : ''}{!l.is_published ? ' · hidden' : ''}{l.kind === 'video' && !l.video_url ? ' · no video yet' : ''}</span>
                </span>
                <details style={{ width: '100%', border: 0 }}>
                  <summary>Edit lesson</summary>
                  <form method="post" action={act('lesson-save')}>
                    <input type="hidden" name="course_id" value={courseId} />
                    <input type="hidden" name="module_id" value={m.id} />
                    <input type="hidden" name="id" value={l.id} />
                    <input type="hidden" name="sort_order" value={l.sort_order} />
                    <LessonFields l={l} />
                    <div className="pn-actions" style={{ marginTop: 12 }}><button className="btn btn-primary btn-sm" type="submit">Save lesson</button></div>
                  </form>
                  <div style={{ padding: '0 16px 16px' }}><ActionButton action={act('lesson-delete')} fields={{ course_id: courseId, id: l.id }} label="Delete lesson" cls="btn btn-outline btn-xs pn-danger" confirm="Delete this lesson?" /></div>
                </details>
              </li>
            ))}
          </ol>
          <details>
            <summary>+ Add lesson to this module</summary>
            <form method="post" action={act('lesson-save')}>
              <input type="hidden" name="course_id" value={courseId} />
              <input type="hidden" name="module_id" value={m.id} />
              <input type="hidden" name="sort_order" value={(m.lessons.at(-1)?.sort_order ?? 0) + 1} />
              <LessonFields />
              <div className="pn-actions" style={{ marginTop: 12 }}><button className="btn btn-primary btn-sm" type="submit">Add lesson</button></div>
            </form>
          </details>
        </div>
      ))}
      <form method="post" action={act('module-save')} className="pn-toolbar">
        <input type="hidden" name="course_id" value={courseId} />
        <input type="hidden" name="sort_order" value={(modules.at(-1)?.sort_order ?? 0) + 1} />
        <input name="title" className="form-control" placeholder="New module title, e.g. Hiragana" required maxLength={200} />
        <button className="btn btn-primary btn-sm" type="submit"><Icon name="add" /> Add module</button>
      </form>
    </div>
  );
}

export function LiveEditor({ courseId, sessions, base }: { courseId: number; sessions: Row[]; base: string }) {
  return (
    <div className="pn-card" id="live">
      <h2>Live classes</h2>
      <p className="pn-muted">Students see the next class with a countdown; the Join button opens 10 minutes before the start. Add a recording link after class.</p>
      {sessions.length > 0 && (
        <table className="data-table" style={{ marginBottom: 16 }}>
          <tbody>
            {sessions.map(s => (
              <tr key={s.id}>
                <td colSpan={3}>
                  <details>
                    <summary style={{ cursor: 'pointer' }}><b>{s.title}</b> · {fmtDateTime(s.scheduled_at)} · <Badge s={s.status} />{s.recording_url ? ' · recorded' : ''}</summary>
                    <form method="post" action={`${base}/live-save`} className="pn-grid" style={{ marginTop: 12 }}>
                      <input type="hidden" name="course_id" value={courseId} />
                      <input type="hidden" name="id" value={s.id} />
                      <LiveFields s={s} />
                      <div className="pn-full pn-actions"><button className="btn btn-primary btn-sm" type="submit">Save</button></div>
                    </form>
                    <div style={{ marginTop: 8 }}><ActionButton action={`${base}/live-delete`} fields={{ course_id: courseId, id: s.id }} label="Delete class" cls="btn btn-outline btn-xs pn-danger" confirm="Delete this live class?" /></div>
                  </details>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
      <details>
        <summary className="pn-link" style={{ cursor: 'pointer' }}>+ Schedule a live class</summary>
        <form method="post" action={`${base}/live-save`} className="pn-grid" style={{ marginTop: 12 }}>
          <input type="hidden" name="course_id" value={courseId} />
          <LiveFields />
          <div className="pn-full pn-actions"><button className="btn btn-primary btn-sm" type="submit">Schedule</button></div>
        </form>
      </details>
    </div>
  );
}

function LiveFields({ s }: { s?: Row }) {
  return (
    <>
      <div className="pn-full"><label>Title</label><input name="title" className="form-control" required maxLength={200} defaultValue={s?.title ?? ''} placeholder="Class 3 — Particles は / が" /></div>
      <div><label>Start (Dhaka time)</label><input name="scheduled_at" type="datetime-local" className="form-control" required defaultValue={toLocalInput(s?.scheduled_at)} /></div>
      <div><label>Duration (minutes)</label><input name="duration_minutes" type="number" min={15} max={600} className="form-control" defaultValue={s?.duration_minutes ?? 90} /></div>
      <div><label>Meeting link (Zoom / Meet)</label><input name="meeting_url" type="url" className="form-control" maxLength={500} defaultValue={s?.meeting_url ?? ''} /></div>
      <div><label>Recording link</label><input name="recording_url" type="url" className="form-control" maxLength={500} defaultValue={s?.recording_url ?? ''} /></div>
      <div><label>Status</label>
        <select name="status" className="form-control" defaultValue={s?.status ?? 'scheduled'}>
          {['scheduled', 'live', 'ended', 'cancelled'].map(x => <option key={x} value={x}>{x}</option>)}
        </select></div>
    </>
  );
}
