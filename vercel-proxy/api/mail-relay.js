import crypto from 'node:crypto';
import nodemailer from 'nodemailer';

function authorized(request) {
  const expected = process.env.MAIL_RELAY_TOKEN || '';
  const supplied = String(request.headers.authorization || '').replace(/^Bearer\s+/i, '');
  if (!expected || supplied.length !== expected.length) return false;
  return crypto.timingSafeEqual(Buffer.from(supplied), Buffer.from(expected));
}

function validEmail(value) {
  return typeof value === 'string' && value.length <= 190 && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
}

export default async function handler(request, response) {
  if (request.method !== 'POST') {
    response.setHeader('Allow', 'POST');
    return response.status(405).json({ ok: false });
  }
  if (!authorized(request)) return response.status(401).json({ ok: false });

  const { to, subject, html, text } = request.body || {};
  if (!validEmail(to) || typeof subject !== 'string' || !subject || subject.length > 255 || typeof html !== 'string' || !html) {
    return response.status(422).json({ ok: false });
  }

  const port = Number(process.env.SMTP_PORT || 465);
  const username = process.env.SMTP_USERNAME || '';
  const password = process.env.SMTP_PASSWORD || '';
  if (!username || !password) return response.status(503).json({ ok: false });

  try {
    const transport = nodemailer.createTransport({
      host: process.env.SMTP_HOST || 'smtp.gmail.com',
      port,
      secure: port === 465,
      auth: { user: username, pass: password },
      connectionTimeout: 10000,
      greetingTimeout: 10000,
      socketTimeout: 15000
    });
    await transport.sendMail({
      from: { name: process.env.SMTP_FROM_NAME || 'TUGON Parish System', address: process.env.SMTP_FROM_ADDRESS || username },
      to,
      subject,
      html,
      text: typeof text === 'string' && text ? text : undefined
    });
    return response.status(200).json({ ok: true });
  } catch (error) {
    console.error('Mail relay delivery failed:', error?.code || error?.message || 'unknown');
    return response.status(502).json({ ok: false });
  }
}
