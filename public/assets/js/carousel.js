// Carousels built on CSS scroll snap (testimonials): swipe and scroll work
// without JavaScript; this adds previous/next buttons, dots, the "current"
// highlight, arrow keys and optional autoplay (off for reduced motion).

const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

export function initCarousels() {
  document.querySelectorAll('[data-carousel]').forEach((root) => {
    const track = root.querySelector('[data-track]');
    const ui = root.querySelector('.carousel-ui');
    if (!track || !ui) return;
    const items = [...track.children];
    if (items.length < 2) return;
    ui.hidden = false;
    const dotsList = ui.querySelector('.carousel-dots');
    const prev = ui.querySelector('[data-prev]');
    const next = ui.querySelector('[data-next]');
    const label = root.dataset.itemLabel || 'Item';
    const dots = items.map((_, i) => {
      const li = document.createElement('li');
      const b = document.createElement('button');
      b.type = 'button';
      const sr = document.createElement('span');
      sr.className = 'sr-only';
      sr.textContent = `${label} ${i + 1} of ${items.length}`;
      b.append(sr);
      b.addEventListener('click', () => go(i));
      li.append(b);
      dotsList?.append(li);
      return b;
    });
    let current = 0;
    const centerOf = (el) => el.offsetLeft + el.offsetWidth / 2;
    const go = (i) => {
      const target = items[(i + items.length) % items.length];
      track.scrollTo({ left: centerOf(target) - track.clientWidth / 2, behavior: reduced ? 'auto' : 'smooth' });
    };
    const paint = () => {
      const mid = track.scrollLeft + track.clientWidth / 2;
      let best = 0;
      items.forEach((el, i) => { if (Math.abs(centerOf(el) - mid) < Math.abs(centerOf(items[best]) - mid)) best = i; });
      current = best;
      items.forEach((el, i) => el.classList.toggle('is-current', i === best));
      dots.forEach((d, i) => d.setAttribute('aria-current', String(i === best)));
    };
    prev?.addEventListener('click', () => go(current - 1));
    next?.addEventListener('click', () => go(current + 1));
    let t = null;
    track.addEventListener('scroll', () => { clearTimeout(t); t = setTimeout(paint, 60); }, { passive: true });
    track.addEventListener('keydown', (e) => {
      if (e.key === 'ArrowRight') { e.preventDefault(); go(current + 1); }
      if (e.key === 'ArrowLeft') { e.preventDefault(); go(current - 1); }
    });
    window.addEventListener('resize', () => go(current), { passive: true });
    root.classList.add('is-ready');
    paint();
    if (root.hasAttribute('data-autoplay') && !reduced) {
      let paused = false;
      root.addEventListener('mouseenter', () => { paused = true; });
      root.addEventListener('mouseleave', () => { paused = false; });
      root.addEventListener('focusin', () => { paused = true; });
      root.addEventListener('focusout', (e) => { if (!root.contains(e.relatedTarget)) paused = false; });
      setInterval(() => { if (!paused && !document.hidden) go(current + 1); }, 8000);
    }
  });
}
