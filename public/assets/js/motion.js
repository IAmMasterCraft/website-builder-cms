// Motion: scroll reveal with stagger, count-up numbers, parallax-lite on the
// hero, timeline progress. All of it is skipped for reduced motion, and the
// page is complete without it (CSS hides nothing until .reveal-ready is set).

const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const hasIO = 'IntersectionObserver' in window;

export function initReveal() {
  if (reduced || !hasIO) return;
  const items = [...document.querySelectorAll('[data-reveal]')];
  if (!items.length) return;
  // Stagger: children of a [data-reveal-group] get their index as --i.
  document.querySelectorAll('[data-reveal-group]').forEach((group) => {
    [...group.querySelectorAll(':scope > [data-reveal]')].forEach((el, i) => el.style.setProperty('--i', String(Math.min(i, 6))));
  });
  // Anything already on screen is shown at once, without a flash.
  const vh = window.innerHeight;
  document.documentElement.classList.add('reveal-ready');
  const io = new IntersectionObserver((entries) => {
    entries.forEach((entry) => {
      if (!entry.isIntersecting) return;
      entry.target.classList.add('is-in');
      io.unobserve(entry.target);
    });
  }, { rootMargin: '0px 0px -8% 0px', threshold: 0.08 });
  items.forEach((el) => {
    const r = el.getBoundingClientRect();
    if (r.top < vh * 0.92 && r.bottom > 0) {
      requestAnimationFrame(() => el.classList.add('is-in'));
    } else {
      io.observe(el);
    }
  });
}

// "1,200+" -> prefix "", number 1200, suffix "+", grouping kept.
function parseCount(text) {
  const m = /^([^\d-]*)(\d[\d,.\s]*)(.*)$/.exec(text.trim());
  if (!m) return null;
  const raw = m[2].replace(/[\s,]/g, '');
  const n = Number(raw);
  if (!Number.isFinite(n)) return null;
  const decimals = raw.includes('.') ? raw.split('.')[1].length : 0;
  return { prefix: m[1], n, suffix: m[3], decimals, grouped: /,/.test(m[2]) || n >= 10000 };
}

export function initCounters() {
  const counters = document.querySelectorAll('[data-count]');
  if (!counters.length || reduced || !hasIO) return;
  const io = new IntersectionObserver((entries) => {
    entries.forEach((entry) => {
      if (!entry.isIntersecting) return;
      io.unobserve(entry.target);
      const el = entry.target;
      const final = el.textContent ?? '';
      const p = parseCount(final);
      if (!p || p.n <= 0) return;
      const fmt = new Intl.NumberFormat(undefined, { minimumFractionDigits: p.decimals, maximumFractionDigits: p.decimals, useGrouping: p.grouped });
      const start = performance.now();
      const dur = 1400;
      const step = (now) => {
        const t = Math.min(1, (now - start) / dur);
        const eased = 1 - (1 - t) ** 4;
        el.textContent = t < 1 ? `${p.prefix}${fmt.format(p.n * eased)}${p.suffix}` : final;
        if (t < 1) requestAnimationFrame(step);
      };
      requestAnimationFrame(step);
    });
  }, { threshold: 0.5 });
  counters.forEach((c) => io.observe(c));
}

export function initParallax() {
  if (reduced) return;
  const layers = [...document.querySelectorAll('.hero-parallax')];
  if (!layers.length) return;
  let ticking = false;
  const paint = () => {
    ticking = false;
    const y = window.scrollY;
    layers.forEach((el) => {
      const box = el.parentElement?.getBoundingClientRect();
      if (!box || box.bottom < 0) return;
      el.style.transform = `translate3d(0, ${Math.round(y * 0.18)}px, 0)`;
    });
  };
  window.addEventListener('scroll', () => { if (!ticking) { ticking = true; requestAnimationFrame(paint); } }, { passive: true });
  paint();
}

// Process timeline: the accent line fills as the steps scroll past.
export function initSteps() {
  const lists = [...document.querySelectorAll('[data-steps]')];
  if (!lists.length) return;
  if (reduced) {
    lists.forEach((l) => l.querySelectorAll('.step').forEach((s) => s.classList.add('is-done')));
    return;
  }
  let ticking = false;
  const paint = () => {
    ticking = false;
    const mid = window.innerHeight * 0.6;
    lists.forEach((list) => {
      const r = list.getBoundingClientRect();
      const p = Math.max(0, Math.min(1, (mid - r.top) / Math.max(1, r.height)));
      list.style.setProperty('--progress', p.toFixed(3));
      list.querySelectorAll('.step').forEach((s) => s.classList.toggle('is-done', s.getBoundingClientRect().top < mid));
    });
  };
  window.addEventListener('scroll', () => { if (!ticking) { ticking = true; requestAnimationFrame(paint); } }, { passive: true });
  window.addEventListener('resize', paint, { passive: true });
  paint();
}
