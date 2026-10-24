// Public site enhancements. Every feature works without this file.
import { initLightbox } from './lightbox.js';
import { initCommunity } from './community.js';
import { initRadio } from './radio.js';

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

const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

// Sliders and carousels: a scroll-snap list that works by swiping without
// JavaScript; this adds previous/next buttons, a counter and optional autoplay.
document.querySelectorAll('[data-slider]').forEach((slider) => {
  const track = slider.querySelector('.slider-track');
  const controls = slider.querySelector('.slider-controls');
  if (!track || !controls) return;
  const slides = [...track.children];
  if (slides.length < 2) return;
  controls.hidden = false;
  const count = controls.querySelector('.slider-count');
  const current = () => {
    const left = track.scrollLeft;
    let best = 0;
    slides.forEach((s, i) => { if (Math.abs(s.offsetLeft - track.offsetLeft - left) < Math.abs(slides[best].offsetLeft - track.offsetLeft - left)) best = i; });
    return best;
  };
  const go = (i) => {
    const target = slides[(i + slides.length) % slides.length];
    track.scrollTo({ left: target.offsetLeft - track.offsetLeft, behavior: reducedMotion ? 'auto' : 'smooth' });
  };
  const paint = () => { if (count) count.textContent = `${current() + 1} / ${slides.length}`; };
  controls.querySelectorAll('[data-dir]').forEach((b) => b.addEventListener('click', () => go(current() + Number(b.dataset.dir))));
  let t = null;
  track.addEventListener('scroll', () => { clearTimeout(t); t = setTimeout(paint, 80); }, { passive: true });
  track.addEventListener('keydown', (e) => {
    if (e.key === 'ArrowRight') { e.preventDefault(); go(current() + 1); }
    if (e.key === 'ArrowLeft') { e.preventDefault(); go(current() - 1); }
  });
  paint();
  if (slider.hasAttribute('data-autoplay') && !reducedMotion) {
    let paused = false;
    let timer = setInterval(() => { if (!paused) go(current() + 1); }, 6000);
    const toggle = controls.querySelector('[data-toggle]');
    slider.addEventListener('mouseenter', () => { paused = true; });
    slider.addEventListener('mouseleave', () => { paused = toggle?.getAttribute('aria-pressed') === 'true'; });
    slider.addEventListener('focusin', () => { paused = true; });
    toggle?.addEventListener('click', () => {
      const stop = toggle.getAttribute('aria-pressed') !== 'true';
      toggle.setAttribute('aria-pressed', String(stop));
      toggle.querySelector('.sr-only').textContent = stop ? 'Play slideshow' : 'Pause slideshow';
      paused = stop;
      if (!stop) { clearInterval(timer); timer = setInterval(() => { if (!paused) go(current() + 1); }, 6000); }
    });
  } else {
    controls.querySelector('[data-toggle]')?.remove();
  }
});

// Stat counters count up once when they scroll into view.
const counters = document.querySelectorAll('[data-count]');
if (counters.length && !reducedMotion && 'IntersectionObserver' in window) {
  const io = new IntersectionObserver((entries) => {
    entries.forEach((entry) => {
      if (!entry.isIntersecting) return;
      io.unobserve(entry.target);
      const el = entry.target;
      const end = Number(el.dataset.count);
      if (!Number.isFinite(end) || end <= 0) return;
      const start = performance.now();
      const step = (now) => {
        const p = Math.min(1, (now - start) / 900);
        el.textContent = String(Math.round(end * (1 - (1 - p) ** 3)));
        if (p < 1) requestAnimationFrame(step);
      };
      requestAnimationFrame(step);
    });
  }, { threshold: 0.4 });
  counters.forEach((c) => io.observe(c));
}

initLightbox();
initCommunity();
initRadio();
