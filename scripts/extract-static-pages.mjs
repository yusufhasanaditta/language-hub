// One-time: copies the static pages' content from the running PHP site (XAMPP)
// into content/*.html. Usage: node scripts/extract-static-pages.mjs
import { writeFileSync, mkdirSync } from 'node:fs';

const BASE = process.env.LEGACY_URL || 'http://localhost/language-hub/legacy-php';
const pages = ['about', 'terms', 'privacy', 'refund', 'faq', 'careers'];
mkdirSync(new URL('../content/', import.meta.url), { recursive: true });

for (const p of pages) {
  const html = await (await fetch(`${BASE}/${p}.php`)).text();
  const start = html.indexOf('<main class="main-content" id="mainContent">');
  const end = html.indexOf('</main><!-- /main-content -->');
  if (start < 0 || end < 0) throw new Error(`markers not found in ${p}`);
  let body = html.slice(start + '<main class="main-content" id="mainContent">'.length, end);
  body = body
    .replace(/<\?php[\s\S]*?\?>/g, '')
    .replace(new RegExp(BASE.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'g'), '')
    .replace(/href="\/([\w-]+)\.php(#[\w-]*)?"/g, (_, f, h = '') => `href="/${f === 'index' ? '' : f}${h}"`)
    .replace(/href="\/submit_testimonial"/g, 'href="/contact"')
    .replace(/href="\/help"/g, 'href="/contact"')
    .trim();
  writeFileSync(new URL(`../content/${p}.html`, import.meta.url), body + '\n');
  console.log(p, body.length);
}
