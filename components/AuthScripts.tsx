'use client';
// Login screen behaviour: 6 code boxes mirroring one real input (keeps paste and
// email autofill working), resend countdown, busy state on submit, and lh.js
// for the theme / language toggles.
import { useEffect } from 'react';

export default function AuthScripts() {
  useEffect(() => {
    const cleanups: (() => void)[] = [];
    const code = document.getElementById('code') as HTMLInputElement | null;
    if (code) {
      const boxes = Array.from(document.querySelectorAll<HTMLElement>('.lh-code-boxes span'));
      const paint = () => {
        code.value = code.value.replace(/\D/g, '').slice(0, boxes.length);
        boxes.forEach((b, i) => {
          b.textContent = code.value[i] || '';
          b.classList.toggle('is-filled', i < code.value.length);
          b.classList.toggle('is-active', document.activeElement === code && i === Math.min(code.value.length, boxes.length - 1));
        });
        if (code.value.length === boxes.length) (document.getElementById('codeForm') as HTMLFormElement | null)?.requestSubmit();
      };
      for (const ev of ['input', 'focus', 'blur']) code.addEventListener(ev, paint);
      paint();
      cleanups.push(() => { for (const ev of ['input', 'focus', 'blur']) code.removeEventListener(ev, paint); });
    }
    const rb = document.getElementById('resendBtn') as HTMLButtonElement | null;
    if (rb) {
      const label = rb.innerHTML;
      let left = Number(rb.dataset.wait || 30);
      rb.disabled = true;
      const t = setInterval(() => {
        left--;
        rb.textContent = (document.documentElement.lang === 'en' ? 'Resend in ' : 'আবার পাঠান ') + left + 's';
        if (left <= 0) { clearInterval(t); rb.disabled = false; rb.innerHTML = label; }
      }, 1000);
      cleanups.push(() => clearInterval(t));
    }
    document.querySelectorAll('form').forEach(f => f.addEventListener('submit', () => {
      const b = f.querySelector<HTMLButtonElement>('[data-busy]');
      if (b) { b.classList.add('is-busy'); setTimeout(() => { b.disabled = true; }, 0); }
    }));
    const w = window as unknown as Record<string, unknown>;
    if (!w.__lhAuthJs) {
      w.__lhAuthJs = true;
      const s = document.createElement('script');
      s.src = '/assets/js/lh.js?v=2.0.0';
      document.body.appendChild(s);
    }
    return () => cleanups.forEach(c => c());
  }, []);
  return null;
}
