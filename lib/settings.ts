import { cache } from 'react';
import { q } from './db';

const SECRET_KEYS = new Set(['sslcz_store_pass']);

/** All rows of the settings table as { key: value }. Cached per request. */
export const getSettings = cache(async () => {
  const rows = await q<{ setting_key: string; setting_value: string | null }>`SELECT setting_key, setting_value FROM settings`;
  // Secrets (the SSLCommerz store password) are read only by lib/sslcommerz.ts, never handed to pages.
  return Object.fromEntries(rows.filter(r => !SECRET_KEYS.has(r.setting_key)).map(r => [r.setting_key, r.setting_value ?? ''])) as Record<string, string>;
});

export async function setting(key: string, fallback = '') {
  return (await getSettings())[key] || fallback;
}

/** Keys an admin may edit from Admin → Settings: [key, label, input type]. */
export const EDITABLE_SETTINGS = [
  ['site_description', 'Site description (search engines and link previews)', 'textarea'],
  ['contact_phone', 'Contact phone', 'text'],
  ['contact_email', 'Contact email', 'email'],
  ['facebook_url', 'Facebook page URL', 'url'],
  ['youtube_url', 'YouTube channel URL', 'url'],
  ['whatsapp_url', 'WhatsApp link', 'url'],
  ['manual_pay_numbers', 'bKash / Nagad numbers for manual payment (shown at checkout)', 'textarea'],
  ['registration_open', 'Allow new sign-ups (1 = yes, 0 = no)', 'text'],
] as const;
