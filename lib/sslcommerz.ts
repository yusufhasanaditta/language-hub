// SSLCommerz (bKash, Nagad, Rocket, cards). Configured with SSLCZ_STORE_ID / SSLCZ_STORE_PASS.
export const sslczConfigured = () => Boolean(process.env.SSLCZ_STORE_ID && process.env.SSLCZ_STORE_PASS);
const base = () => (process.env.SSLCZ_LIVE === '1' ? 'https://securepay.sslcommerz.com' : 'https://sandbox.sslcommerz.com');

/** Starts a payment and returns the gateway page URL (null on failure). */
export async function startPayment(p: {
  orderId: number; amount: number; product: string; siteUrl: string;
  name: string; email: string | null; phone: string | null;
}) {
  const body = new URLSearchParams({
    store_id: process.env.SSLCZ_STORE_ID!, store_passwd: process.env.SSLCZ_STORE_PASS!,
    total_amount: p.amount.toFixed(2), currency: 'BDT',
    tran_id: `LH_${Date.now().toString(36)}_${p.orderId}`,
    success_url: `${p.siteUrl}/api/payment/callback`, fail_url: `${p.siteUrl}/api/payment/callback`,
    cancel_url: `${p.siteUrl}/api/payment/callback`,
    cus_name: p.name || 'Student', cus_email: p.email || 'student@example.com', cus_phone: p.phone || '01700000000',
    cus_add1: 'Dhaka', cus_city: 'Dhaka', cus_country: 'Bangladesh',
    shipping_method: 'NO', product_name: p.product.slice(0, 250), product_category: 'Education',
    product_profile: 'non-physical-goods', value_a: String(p.orderId),
  });
  try {
    const r = await fetch(`${base()}/gwprocess/v4/api.php`, { method: 'POST', body, signal: AbortSignal.timeout(30_000) });
    const j = await r.json();
    return typeof j?.GatewayPageURL === 'string' && j.GatewayPageURL ? (j.GatewayPageURL as string) : null;
  } catch {
    return null;
  }
}

export type Validation = { status: string; tran_id: string; amount: string; currency: string; value_a: string };

/** Server-side check of a callback's val_id (never trust the browser POST). */
export async function validatePayment(valId: string): Promise<Validation | null> {
  const u = new URL(`${base()}/validator/api/validationserverAPI.php`);
  u.search = new URLSearchParams({
    val_id: valId, store_id: process.env.SSLCZ_STORE_ID!, store_passwd: process.env.SSLCZ_STORE_PASS!, v: '1', format: 'json',
  }).toString();
  try {
    const r = await fetch(u, { signal: AbortSignal.timeout(30_000) });
    return (await r.json()) as Validation;
  } catch {
    return null;
  }
}
