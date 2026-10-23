// Public site enhancements. Every feature works without this file.
import { initLightbox } from './lightbox.js';

// Mobile navigation toggle.
const toggle = document.querySelector('.nav-toggle');
const nav = document.getElementById('site-nav');
if (toggle && nav) {
  toggle.addEventListener('click', () => {
    const open = toggle.getAttribute('aria-expanded') !== 'true';
    toggle.setAttribute('aria-expanded', String(open));
    nav.classList.toggle('open', open);
  });
}

// Submenus: disclosure buttons; Escape and outside clicks close them.
const subs = [...document.querySelectorAll('.site-nav .has-sub')];
function closeAll(except) {
  subs.forEach((li) => {
    if (li !== except) {
      li.classList.remove('open');
      li.querySelector('.sub-toggle')?.setAttribute('aria-expanded', 'false');
    }
  });
}
subs.forEach((li) => {
  const btn = li.querySelector('.sub-toggle');
  btn?.addEventListener('click', () => {
    const open = !li.classList.contains('open');
    closeAll(li);
    li.classList.toggle('open', open);
    btn.setAttribute('aria-expanded', String(open));
  });
});
document.addEventListener('click', (e) => {
  if (!(e.target instanceof Element) || !e.target.closest('.has-sub')) closeAll();
});
document.addEventListener('keydown', (e) => {
  if (e.key !== 'Escape') return;
  const openLi = subs.find((li) => li.classList.contains('open'));
  if (openLi) {
    closeAll();
    openLi.querySelector('.sub-toggle')?.focus();
  }
});

// Copy-link share button (shown only when the clipboard API exists).
document.querySelectorAll('[data-copy]').forEach((btn) => {
  if (!navigator.clipboard) return;
  btn.hidden = false;
  const label = btn.querySelector('span');
  btn.addEventListener('click', async () => {
    try {
      await navigator.clipboard.writeText(btn.dataset.copy ?? '');
      if (label) label.textContent = 'Link copied';
    } catch {
      if (label) label.textContent = 'Copy failed';
    }
    setTimeout(() => { if (label) label.textContent = 'Copy link'; }, 2500);
  });
});

// Event countdown.
document.querySelectorAll('[data-countdown]').forEach((box) => {
  const target = Date.parse(box.dataset.countdown ?? '');
  if (Number.isNaN(target)) return;
  const units = Object.fromEntries([...box.querySelectorAll('[data-unit]')].map((el) => [el.dataset.unit, el]));
  const tick = () => {
    let s = Math.max(0, Math.floor((target - Date.now()) / 1000));
    const d = Math.floor(s / 86400); s -= d * 86400;
    const h = Math.floor(s / 3600); s -= h * 3600;
    const m = Math.floor(s / 60); s -= m * 60;
    if (units.d) units.d.textContent = String(d);
    if (units.h) units.h.textContent = String(h);
    if (units.m) units.m.textContent = String(m);
    if (units.s) units.s.textContent = String(s);
  };
  tick();
  setInterval(tick, 1000);
});

initLightbox();
