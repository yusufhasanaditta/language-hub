// Protected video player used for every class video on the site (classroom, free classes,
// trailers, live-class recordings). See lh.js "Protected player":
// - nothing playable is in the HTML; pressing play asks /api/player, which checks access first;
// - YouTube runs without its own buttons under a transparent shield, so its title, logo, share,
//   "Watch on YouTube" and right-click menu ("Copy video URL / embed code") can't be reached;
// - the viewer's name, email and serial number drift over the picture (also in full screen).
import type { User } from '@/lib/auth';
import { Icon } from './ui';

/** Watermark text for a viewer: name · email (or phone) and a serial number. */
export function watermarkFor(user: User | null) {
  if (!user) return { mark: 'Language Hub', code: 'languagehubbd.com' };
  return {
    mark: [user.name, user.email || user.phone].filter(Boolean).join(' · '),
    code: `ID #${String(user.id).padStart(6, '0')}`,
  };
}

type Props = { user: User | null; glyph: string; title?: string; lessonId?: number; poster?: string; inline?: boolean };

export function VideoPlayer({ user, glyph, title = '', lessonId, poster }: Props) {
  const { mark, code } = watermarkFor(user);
  return (
    <div className="lh-vp" data-pp data-lesson={lessonId ?? ''} tabIndex={0} aria-label={title ? `Video: ${title}` : 'Video'}>
      <div className="lh-vp-frame" data-pp-frame></div>
      <div className="lh-vp-band" aria-hidden="true"></div>
      <div className="lh-vp-tiles" aria-hidden="true">
        {Array.from({ length: 8 }, (_, i) => <span key={i}>{mark} · {code}</span>)}
      </div>
      <span className="lh-vp-mark" aria-hidden="true">{mark}<small>{code}</small></span>
      <button type="button" className="lh-vp-cover" data-pp-start aria-label="Play">
        {poster ? <img src={poster} alt="" draggable={false} /> : <span className="lh-vp-glyph" aria-hidden="true">{glyph}</span>}
        <span className="lh-vp-big"><span className="material-icons-round" aria-hidden="true">play_arrow</span></span>
      </button>
    </div>
  );
}

/** One pop-up player per page; any element with data-pp-open + data-lesson / data-recording / data-intro opens it. */
export function VideoModal({ user, glyph }: { user: User | null; glyph: string }) {
  return (
    <div className="lh-vp-modal" hidden data-pp-modal role="dialog" aria-modal="true" aria-label="Video">
      <div className="lh-vp-modal-box">
        <button type="button" className="lh-vp-close" data-pp-close aria-label="Close"><Icon name="close" /></button>
        <VideoPlayer user={user} glyph={glyph} />
      </div>
    </div>
  );
}
