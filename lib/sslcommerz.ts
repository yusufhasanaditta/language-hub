// SSLCommerz online payments (bKash, Nagad, Rocket, cards, internet banking).
// Credentials come from Admin → Settings → Online payment, or from the server environment
// (SSLCZ_STORE_ID / SSLCZ_STORE_PASS / SSLCZ_LIVE=1), which wins when set.
import { cache } from 'react';
import { q } from './db';
import { markOrderPaid } from './orders';

export type SslczConfig = { storeId: string; storePass: string; live: boolean; source: 'env' | 'admin' };

/** Settings keys (the password is never returned by getSettings()). */
export const SSLCZ_KEYS = { id: 'sslcz_store_id', pass: 'sslcz_store_pass', mode: 'sslcz_mode' } as const;

/** Current credentials, or null when online payment is not set up. Cached per request. */
export const sslczConfig = cache(async (): Promise<SslczConfig | null> => {
  const envId = process.env.SSLCZ_STORE_ID?.trim(), envPass = process.env.SSLCZ_STORE_PASS?.trim();
  if (envId && envPass) return { storeId: envId, storePass: envPass, live: process.env.SSLCZ_LIVE === '1', source: 'env' };
  const rows = await q<{ setting_key: string; setting_value: string | null }>`
    SELECT setting_key, setting_value FROM settings WHERE setting_key IN (${SSLCZ_KEYS.id}, ${SSLCZ_KEYS.pass}, ${SSLCZ_KEYS.mode})`;
  const s = Object.fromEntries(rows.map(r => [r.setting_key, (r.setting_value ?? '').trim()]));
  if (!s[SSLCZ_KEYS.id] || !s[SSLCZ_KEYS.pass]) return null;
  return { storeId: s[SSLCZ_KEYS.id], storePass: s[SSLCZ_KEYS.pass], live: s[SSLCZ_KEYS.mode] === 'live', source: 'admin' };
});

export const sslczConfigured = async () => (await sslczConfig()) !== null;

const base = (c: SslczConfig) => (c.live ? 'https://securepay.sslcommerz.com' : 'https://sandbox.sslcommerz.com');

type Start = {
  orderId: number; amount: number; product: string; siteUrl: string;
  name: string; email: string | null; phone: string | null;
};

/** Opens a gateway session. Returns the payment page URL, or the gateway's reason for refusing. */
export async function openSession(p: Start, cfg?: SslczConfig | null): Promise<{ url: string } | { error: string }> {
  const c = cfg ?? (await sslczConfig());
  if (!c) return { error: 'SSLCommerz is not set up.' };
  const body = new URLSearchParams({
    store_id: c.storeId, store_passwd: c.storePass,
    total_amount: p.amount.toFixed(2), currency: 'BDT',
    tran_id: `LH_${Date.now().toString(36)}_${p.orderId}`,
    success_url: `${p.siteUrl}/api/payment/callback`, fail_url: `${p.siteUrl}/api/payment/callback`,
    cancel_url: `${p.siteUrl}/api/payment/callback`, ipn_url: `${p.siteUrl}/api/payment/ipn`,
    cus_name: p.name || 'Student', cus_email: p.email || 'student@example.com', cus_phone: p.phone || '01700000000',
    cus_add1: 'Dhaka', cus_city: 'Dhaka', cus_country: 'Bangladesh',
    shipping_method: 'NO', product_name: p.product.slice(0, 250), product_category: 'Education',
    product_profile: 'non-physical-goods', value_a: String(p.orderId),
  });
  try {
    const r = await fetch(`${base(c)}/gwprocess/v4/api.php`, { method: 'POST', body, signal: AbortSignal.timeout(30_000) });
    const j = await r.json();
    if (typeof j?.GatewayPageURL === 'string' && j.GatewayPageURL) return { url: j.GatewayPageURL };
    return { error: String(j?.failedreason || j?.status || `HTTP ${r.status}`) };
  } catch (e) {
    return { error: e instanceof Error ? e.message : 'No answer from SSLCommerz' };
  }
}

/** Starts a payment and returns the gateway page URL (null on failure). */
export async function startPayment(p: Start) {
  const r = await openSession(p);
  return 'url' in r ? r.url : null;
}

export type Validation = { status: string; tran_id: string; amount: string; currency: string; value_a: string };

/** Server-side check of a val_id (never trust the browser or IPN POST body). */
export async function validatePayment(valId: string): Promise<Validation | null> {
  const c = await sslczConfig();
  if (!c || !/^[\w-]{6,80}$/.test(valId)) return null;
  const u = new URL(`${base(c)}/validator/api/validationserverAPI.php`);
  u.search = new URLSearchParams({ val_id: valId, store_id: c.storeId, store_passwd: c.storePass, v: '1', format: 'json' }).toString();
  try {
    const r = await fetch(u, { signal: AbortSignal.timeout(30_000) });
    return (await r.json()) as Validation;
  } catch {
    return null;
  }
}

/**
 * Confirms a gateway notification (browser return or IPN) and pays the order.
 * 'paid' = paid now or earlier; 'failed' = the gateway did not confirm it.
 */
export async function settleFromGateway(orderId: number, status: string, valId: string): Promise<'paid' | 'failed' | 'unknown'> {
  const order = (await q<{ id: number; total: string; status: string; pay_method: string | null }>`
    SELECT id, total, status, pay_method FROM orders WHERE id = ${orderId}`)[0];
  if (!order) return 'unknown';
  if (order.status === 'paid') return 'paid';
  if (status !== 'VALID' && status !== 'VALIDATED') {
    // FAILED / CANCELLED / UNATTEMPTED / EXPIRED: close the online attempt (a manual TrxID order is left alone).
    if (order.status === 'pending' && order.pay_method === 'sslcommerz') await q`UPDATE orders SET status = 'failed' WHERE id = ${orderId} AND status = 'pending'`;
    return 'failed';
  }
  const v = valId ? await validatePayment(valId) : null;
  const ok = v && (v.status === 'VALID' || v.status === 'VALIDATED')
    && v.currency === 'BDT'
    && Math.abs(Number(v.amount) - Number(order.total)) < 0.01
    && Number(v.value_a) === orderId
    && typeof v.tran_id === 'string' && v.tran_id.endsWith(`_${orderId}`);
  if (!ok) return 'failed';
  await markOrderPaid(orderId, 'sslcommerz', v!.tran_id);
  return 'paid';
}
