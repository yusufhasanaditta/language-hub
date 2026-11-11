// Bangla / English: both are rendered, CSS shows the one matching <html data-lang>.
// Switching is instant and needs no reload (same as the original site).
const BN_DIGITS = ['০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯'];
const BN_MONTHS = ['জানুয়ারি', 'ফেব্রুয়ারি', 'মার্চ', 'এপ্রিল', 'মে', 'জুন', 'জুলাই', 'আগস্ট', 'সেপ্টেম্বর', 'অক্টোবর', 'নভেম্বর', 'ডিসেম্বর'];
const EN_MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

export const bnNum = (s: string | number) => String(s).replace(/\d/g, d => BN_DIGITS[+d]);

export function T({ bn, en }: { bn: React.ReactNode; en?: React.ReactNode }) {
  return (
    <>
      <span className="i18n" lang="bn">{bn}</span>
      <span className="i18n" lang="en">{en ?? bn}</span>
    </>
  );
}

/** A number in Bangla digits / Latin digits. */
export function N({ v }: { v: string | number }) {
  return <T bn={bnNum(v)} en={String(v)} />;
}

/** "12 মার্চ" / "12 Mar" (Dhaka time). */
export function DateBn({ date }: { date: Date | string | null | undefined }) {
  if (!date) return null;
  const d = dhaka(date);
  return <T bn={`${bnNum(d.getUTCDate())} ${BN_MONTHS[d.getUTCMonth()]}`} en={`${d.getUTCDate()} ${EN_MONTHS[d.getUTCMonth()]}`} />;
}

/** Shift a date to Asia/Dhaka (UTC+6, no DST); read the result with getUTC*(). */
export function dhaka(date: Date | string) {
  const d = typeof date === 'string' ? new Date(date) : date;
  return new Date(d.getTime() + 6 * 3600_000);
}
