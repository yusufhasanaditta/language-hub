'use client';
// Motion (motion.dev) effects layered over the server-rendered pages:
//   scroll progress bar · spring 3D tilt on tickets · press feedback · magnetic CTAs
//   · number count-up · the journey's plane following the scroll.
// (Staggered entrances stay with data-reveal / data-reveal-group in lh.js.)
// Everything is skipped when the visitor asks for reduced motion; nothing is hidden by it.
import { useEffect, useRef } from 'react';
import { animate, inView, press, scroll } from 'motion';

const SPRING = { type: 'spring', stiffness: 260, damping: 22 } as const;
const BN = '০১২৩৪৫৬৭৮৯';

/** Springs a set of CSS custom properties on an element (keeps velocity between moves). */
function springVars(el: HTMLElement, unit: Record<string, string>) {
  const cur: Record<string, number> = {};
  const runs: Record<string, { stop(): void } | undefined> = {};
  return (to: Record<string, number>, done?: () => void) => {
    let pending = Object.keys(to).length;
    for (const [k, v] of Object.entries(to)) {
      runs[k]?.stop();
      runs[k] = animate(cur[k] ?? 0, v, {
        ...SPRING,
        onUpdate: (x: number) => { cur[k] = x; el.style.setProperty(k, x.toFixed(3) + unit[k]); },
        onComplete: () => { if (--pending === 0) done?.(); },
      });
    }
  };
}

/**
 * Follows the pointer over el: onMove gets the pointer position as -0.5…0.5 of the element's box
 * (measured once on enter, not on every move), onLeave runs when it leaves.
 */
function follow(el: HTMLElement, onMove: (px: number, py: number, r: DOMRect) => void, onLeave: () => void) {
  let r = el.getBoundingClientRect();
  const enter = () => { r = el.getBoundingClientRect(); };
  const move = (e: PointerEvent) => onMove((e.clientX - r.left) / r.width - 0.5, (e.clientY - r.top) / r.height - 0.5, r);
  const events: [string, EventListener][] = [['pointerenter', enter], ['pointermove', move as EventListener], ['pointerleave', onLeave]];
  events.forEach(([t, h]) => el.addEventListener(t, h));
  return () => events.forEach(([t, h]) => el.removeEventListener(t, h));
}

function tilt(el: HTMLElement) {
  const set = springVars(el, { '--mrx': 'deg', '--mry': 'deg', '--mlift': 'px' });
  return follow(el, (px, py) => {
    el.classList.add('lh-mtilt');
    set({ '--mry': px * 10, '--mrx': py * -8, '--mlift': -6 });
  }, () => set({ '--mry': 0, '--mrx': 0, '--mlift': 0 }, () => el.classList.remove('lh-mtilt')));
}

function magnetic(el: HTMLElement) {
  const set = springVars(el, { '--mx': 'px', '--my': 'px' });
  el.classList.add('lh-magnetic');
  return follow(el, (px, py, r) => set({ '--mx': px * r.width * 0.18, '--my': py * r.height * 0.3 }),
    () => set({ '--mx': 0, '--my': 0 }));
}

/** Counts every plain number inside `el` up from 0, keeping Bangla digits and commas. */
function countUp(el: HTMLElement) {
  const walker = document.createTreeWalker(el, NodeFilter.SHOW_TEXT);
  const nodes: Text[] = [];
  while (walker.nextNode()) nodes.push(walker.currentNode as Text);
  for (const node of nodes) {
    const orig = node.nodeValue ?? '';
    const m = orig.match(/^(\D*?)([\d০-৯][\d০-৯,]*)(\D*)$/);
    if (!m) continue;
    const bn = /[০-৯]/.test(m[2]), commas = m[2].includes(',');
    const n = Number(m[2].replace(/[০-৯]/g, d => String(BN.indexOf(d))).replace(/,/g, ''));
    if (!Number.isFinite(n) || n < 2) continue;
    const fmt = (x: number) => {
      let s = commas ? Math.round(x).toLocaleString('en-US') : String(Math.round(x));
      if (bn) s = s.replace(/\d/g, d => BN[Number(d)]);
      return m[1] + s + m[3];
    };
    node.nodeValue = fmt(0);
    animate(0, n, { duration: Math.min(1.6, 0.6 + n / 400), ease: [0.16, 1, 0.3, 1], onUpdate: x => { node.nodeValue = fmt(x); },
      onComplete: () => { node.nodeValue = orig; } });
  }
}

export default function MotionFx({ progress = false }: { progress?: boolean }) {
  const bar = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    const fine = matchMedia('(hover: hover) and (pointer: fine)').matches;
    const off: (() => void)[] = [];
    const $$ = (s: string) => Array.from(document.querySelectorAll<HTMLElement>(s));
    /** Runs fn the first time el scrolls into view, then stops watching. */
    const once = (el: Element, fn: () => void, amount: number) => {
      const stop = inView(el, () => { stop(); fn(); }, { amount });
      off.push(stop);
    };

    // Reading progress along the top edge.
    if (bar.current && document.body.scrollHeight > innerHeight * 1.6) {
      const b = bar.current;
      off.push(scroll((p: number) => { b.style.transform = `scaleX(${p})`; }));
    }

    // Tickets lean towards the pointer on a spring.
    if (fine) $$('.lh-ticket, .lh-crew, .lh-stop > a').forEach(el => off.push(tilt(el)));
    if (fine) $$('[data-magnetic], .lh-c-buy .btn, .pn-hero .btn, .lh-buybar .btn').forEach(el => off.push(magnetic(el)));

    // Pressed buttons and cards squash a little and spring back.
    off.push(press('.btn, .lh-ticket, .lh-crew, .lh-stop > a, .admin-nav-item, .lh-glance-item', el => {
      const e = el as HTMLElement;
      animate(1, 0.96, { duration: 0.12, onUpdate: v => { e.style.scale = String(v); } });
      return () => animate(0.96, 1, { ...SPRING, stiffness: 420, onUpdate: v => { e.style.scale = String(v); },
        onComplete: () => { e.style.scale = ''; } });
    }));

    // Numbers count up the first time they scroll into view.
    $$('.stat-card-value, [data-countup]').forEach(el => {
      once(el, () => countUp(el), 0.6);
    });

    // Course journey: the plane flies along the track as you scroll through the classes.
    $$('.lh-journey').forEach(j => {
      const track = j.querySelector<HTMLElement>('.lh-jtrack');
      if (!track) return;
      off.push(scroll((p: number) => { track.style.setProperty('--p', p.toFixed(4)); },
        { target: j, offset: ['start 0.75', 'end 0.55'] }));
    });

    return () => off.forEach(f => f());
  }, []);

  return progress ? <div className="lh-readbar" ref={bar} aria-hidden="true"></div> : null;
}
