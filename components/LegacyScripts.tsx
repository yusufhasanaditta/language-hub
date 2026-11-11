'use client';
// Loads the site's original plain-JS files AFTER React has hydrated the page.
// Those scripts add elements (carousel dots, flip-board letters …); running them
// before hydration would make React see a mismatch and re-render the page.
import { useEffect } from 'react';

export default function LegacyScripts({ srcs, globals }: { srcs: string[]; globals: Record<string, unknown> }) {
  useEffect(() => {
    const w = window as unknown as Record<string, unknown>;
    if (w.__lhScriptsLoaded) return; // dev StrictMode runs effects twice
    w.__lhScriptsLoaded = true;
    Object.assign(w, globals);
    for (const src of srcs) {
      const s = document.createElement('script');
      s.src = src;
      s.async = false; // keep order: main.js → app.js → lh.js
      document.body.appendChild(s);
    }
  }, [srcs, globals]);
  return null;
}
