// Photos (teacher / student avatars, testimonial pictures) stored in the database, so they work
// the same on every host (Vercel has no permanent disk, cPanel shares the same database).
// Served by app/media/[id]/route.ts at /media/<id>.
import { one, q } from './db';
import { fail } from './http';
import { safeUrl } from './format';

const MAX_BYTES = 3 * 1024 * 1024; // the browser shrinks photos first; this is the hard limit

/** Real image type from the first bytes (never trust the file name or browser type). */
function sniff(b: Buffer): string | null {
  if (b.length > 3 && b[0] === 0xff && b[1] === 0xd8 && b[2] === 0xff) return 'image/jpeg';
  if (b.length > 8 && b.subarray(0, 8).equals(Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]))) return 'image/png';
  if (b.length > 12 && b.toString('ascii', 0, 4) === 'RIFF' && b.toString('ascii', 8, 12) === 'WEBP') return 'image/webp';
  if (b.length > 6 && b.toString('ascii', 0, 3) === 'GIF') return 'image/gif';
  return null;
}

/** Saves an uploaded image and returns its URL (/media/123). */
export async function saveImage(file: File) {
  if (file.size > MAX_BYTES) fail('ছবিটি ৩ MB-এর বেশি বড়।', 'The photo is larger than 3 MB.');
  const buf = Buffer.from(await file.arrayBuffer());
  const mime = sniff(buf);
  if (!mime) fail('শুধু JPG, PNG, WebP বা GIF ছবি দিন।', 'Please upload a JPG, PNG, WebP or GIF image.');
  const row = await one<{ id: number }>`INSERT INTO media (mime, bytes, data) VALUES (${mime}, ${buf.length}, ${buf.toString('base64')}) RETURNING id`;
  return `/media/${row!.id}`;
}

const mediaId = (url: string | null | undefined) => Number(/^\/media\/(\d+)$/.exec(url ?? '')?.[1] ?? 0);

/** Deletes a stored image (no-op for outside links). */
export async function deleteImage(url: string | null | undefined) {
  const id = mediaId(url);
  if (id) await q`DELETE FROM media WHERE id = ${id}`;
}

/**
 * Reads a <PhotoInput> from a form: a new upload, "remove", a pasted https link, or keep the current one.
 * Returns the value to store; the old stored image is deleted when it is replaced.
 */
export async function photoFromForm(f: FormData, current: string | null, prefix = 'photo') {
  const file = f.get(`${prefix}_file`);
  let next: string | null = current;
  if (file instanceof File && file.size > 0) next = await saveImage(file);
  else if (f.get(`${prefix}_remove`) === '1') next = null;
  else {
    const link = String(f.get(`${prefix}_url`) ?? '').trim();
    if (link && link !== current) next = safeUrl(link) ?? fail('ছবির লিংকটি https:// দিয়ে শুরু হতে হবে।', 'The photo link must start with https://');
  }
  if (next !== current) await deleteImage(current);
  return next;
}
