import nodemailer from 'nodemailer';

export const smtpConfigured = () => Boolean(process.env.SMTP_HOST && process.env.SMTP_USER && process.env.SMTP_PASS);

/** Sends an email. Returns false when SMTP is not configured or sending failed. */
export async function sendMail(to: string, subject: string, html: string) {
  if (!smtpConfigured()) return false;
  const port = Number(process.env.SMTP_PORT || 465);
  const transport = nodemailer.createTransport({
    host: process.env.SMTP_HOST, port, secure: port === 465,
    auth: { user: process.env.SMTP_USER, pass: process.env.SMTP_PASS },
  });
  try {
    await transport.sendMail({
      from: `"${process.env.SMTP_FROM_NAME || 'Language Hub'}" <${process.env.SMTP_USER}>`,
      to, subject, html,
    });
    return true;
  } catch (e) {
    console.error('sendMail failed', e);
    return false;
  }
}
