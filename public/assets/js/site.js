// Public site enhancements. Every feature works without this file.
import { initLightbox } from './lightbox.js';
import { initCommunity } from './community.js';
import { initRadio } from './radio.js';
import { initHeader } from './header.js';
import { initReveal, initCounters, initParallax, initSteps } from './motion.js';
import { initHero } from './hero.js';
import { initCarousels } from './carousel.js';
import { initAccordions, initShowcase } from './accordion.js';

initHeader();
initReveal();
initHero();

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

initLightbox();
initCommunity();
initRadio();
initCounters();
initParallax();
initSteps();
initCarousels();
initAccordions();
initShowcase();

// Back to top (footer).
document.querySelectorAll('[data-to-top]').forEach((a) => a.addEventListener('click', (e) => {
  e.preventDefault();
  window.scrollTo({ top: 0, behavior: reducedMotion ? 'auto' : 'smooth' });
  document.getElementById('main')?.focus({ preventScroll: true });
}));
