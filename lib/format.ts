import { dhaka } from './i18n';
import type { Row } from './db';

export const formatPrice = (n: number | string) =>
  Math.round(Number(n)).toLocaleString('en-US');

/** [current price, original price or null] */
export function priceOf(c: Row): [number, number | null] {
  const price = Number(c.price);
  const sale = c.sale_price != null ? Number(c.sale_price) : null;
  return sale !== null && sale < price ? [sale, price] : [price, null];
}

export const isFreeCourse = (c: Row) => Boolean(c.is_free) || priceOf(c)[0] <= 0;

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
/** "12 Mar 2026" */
export function fmtDate(v: Date | string | null | undefined) {
  if (!v) return '—';
  const d = dhaka(v);
  return `${d.getUTCDate()} ${MONTHS[d.getUTCMonth()]} ${d.getUTCFullYear()}`;
}
/** "12 Mar, 4:30 PM" */
export function fmtDateTime(v: Date | string | null | undefined) {
  if (!v) return '—';
  const d = dhaka(v);
  const h = d.getUTCHours(), m = String(d.getUTCMinutes()).padStart(2, '0');
  return `${d.getUTCDate()} ${MONTHS[d.getUTCMonth()]}, ${h % 12 || 12}:${m} ${h < 12 ? 'AM' : 'PM'}`;
}
/** Value for <input type="datetime-local"> in Dhaka time. */
export function toLocalInput(v: Date | string | null | undefined) {
  if (!v) return '';
  return dhaka(v).toISOString().slice(0, 16);
}
/** Parse <input type="datetime-local"> (Dhaka time) to a Date. */
export function fromLocalInput(s: string) {
  return s ? new Date(s + ':00+06:00') : null;
}

export function timeAgo(v: Date | string) {
  const s = (Date.now() - new Date(v).getTime()) / 1000;
  if (s < 60) return 'just now';
  if (s < 3600) return `${Math.floor(s / 60)} min ago`;
  if (s < 86400) return `${Math.floor(s / 3600)} hr ago`;
  if (s < 172800) return 'yesterday';
  if (s < 604800) return `${Math.floor(s / 86400)} days ago`;
  return fmtDate(v);
}

export function slugify(text: string) {
  return text.toLowerCase().normalize('NFKD').replace(/[^\w\s-]/g, '').trim()
    .replace(/[\s_-]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 200);
}

/** Initials avatar, or the uploaded URL when it is an absolute URL. */
export function avatarUrl(avatar: string | null | undefined, name = 'U') {
  if (avatar && /^https?:\/\//.test(avatar)) return avatar;
  const initials = encodeURIComponent([...name.trim()].slice(0, 2).join('').toUpperCase());
  return `https://placehold.co/400x400/5B21B6/ffffff?text=${initials}`;
}

export function youtubeId(url: string) {
  const m = url.match(/(?:youtu\.be\/|youtube(?:-nocookie)?\.com\/(?:watch\?(?:.*&)?v=|embed\/|shorts\/|live\/|v\/))([\w-]{11})/);
  return m ? m[1] : /^[\w-]{11}$/.test(url) ? url : '';
}

/** Embeddable player URL for YouTube / Vimeo / any https link, else ''. */
export function embedUrl(src: string | null | undefined) {
  const s = (src ?? '').trim();
  if (!s) return '';
  const yt = youtubeId(s);
  if (yt) return `https://www.youtube-nocookie.com/embed/${yt}?rel=0&modestbranding=1&playsinline=1`;
  const vm = s.match(/vimeo\.com\/(?:video\/)?(\d+)/);
  if (vm) return `https://player.vimeo.com/video/${vm[1]}`;
  return s.startsWith('https://') ? s : '';
}

/** Accept only http(s) links from forms (blocks javascript: URLs). */
export function safeUrl(s: string | null | undefined) {
  const v = (s ?? '').trim();
  return /^https?:\/\/[^\s]+$/i.test(v) ? v.slice(0, 500) : null;
}

export const lines = (s: string | null | undefined) =>
  (s ?? '').split(/\r?\n/).map(l => l.trim().replace(/^[-•*]\s*/, '')).filter(Boolean);
