// Photo picker for forms (needs encType="multipart/form-data"). lh.js shrinks the chosen photo
// in the browser (max 800px JPEG) and shows a preview; lib/media.ts photoFromForm() reads it.
import { avatarUrl } from '@/lib/format';
import { T } from '@/lib/i18n';

export function PhotoInput({ current, name = 'Photo', prefix = 'photo', round = true }: {
  current: string | null | undefined; name?: string; prefix?: string; round?: boolean;
}) {
  const link = current && /^https?:\/\//.test(current) ? current : '';
  return (
    <div className="lh-photo-in">
      <img src={avatarUrl(current, name)} alt="" width={96} height={96} className={round ? 'is-round' : ''} data-photo-preview />
      <div className="lh-photo-in-body">
        <label className="btn btn-outline btn-sm lh-photo-pick">
          <span className="material-icons-round" aria-hidden="true">photo_camera</span>
          <T bn="ছবি বাছাই করুন" en="Choose photo" />
          <input type="file" name={`${prefix}_file`} accept="image/jpeg,image/png,image/webp,image/gif" data-img-resize />
        </label>
        <small className="lh-photo-hint"><T bn="JPG / PNG — নিজে থেকেই ছোট হয়ে যাবে" en="JPG / PNG — resized automatically" /></small>
        {current && (
          <label className="lh-photo-remove"><input type="checkbox" name={`${prefix}_remove`} value="1" /> <T bn="ছবি মুছে ফেলুন" en="Remove photo" /></label>
        )}
        <details className="lh-photo-link">
          <summary><T bn="অথবা ছবির লিংক দিন" en="or paste an image link" /></summary>
          <input type="url" name={`${prefix}_url`} className="form-control lh-input" maxLength={500} defaultValue={link} placeholder="https://…" />
        </details>
      </div>
    </div>
  );
}
