// Full-height hero: crossfading photo slider (Ken Burns in CSS) with dots,
// previous/next, swipe, autoplay that pauses on hover, focus, hidden tabs and
// the pause button; background video that only plays without reduced motion.
// Without JavaScript the first photo shows on its own.

const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

export function initHero() {
  document.querySelectorAll('[data-hero-stage]').forEach((stage) => {
    const section = stage.closest('.blk-hero');
    const video = stage.querySelector('video');
    if (video) {
      if (!reduced) {
        video.play().catch(() => { /* autoplay refused: the poster stays */ });
      }
      return;
    }
    const slides = [...stage.querySelectorAll('.hero-slide')];
    const ui = section?.querySelector('.hero-ui');
    if (slides.length < 2 || !ui) return;
    ui.hidden = false;
    const dots = [...ui.querySelectorAll('[data-go]')];
    const toggle = ui.querySelector('[data-pause]');
    const live = ui.querySelector('[data-live]');
    const delay = Number(stage.dataset.interval || 7000);
    stage.style.setProperty('--slide-ms', `${delay}ms`);
    let index = 0;
    let timer = null;
    let userPaused = reduced || !stage.hasAttribute('data-autoplay');
    let hover = false;

    const show = (i, announce = false) => {
      index = (i + slides.length) % slides.length;
      slides.forEach((s, n) => {
        s.classList.toggle('is-current', n === index);
        s.setAttribute('aria-hidden', String(n !== index));
      });
      dots.forEach((d, n) => d.setAttribute('aria-current', String(n === index)));
      // Restart the dot's progress animation.
      const cur = dots[index];
      if (cur) { cur.style.animation = 'none'; void cur.offsetWidth; cur.style.animation = ''; }
      if (announce && live) live.textContent = `Photo ${index + 1} of ${slides.length}`;
    };
    const running = () => !userPaused && !hover && !document.hidden;
    const schedule = () => {
      clearTimeout(timer);
      stage.classList.toggle('is-playing', running());
      if (running()) timer = setTimeout(() => { show(index + 1); schedule(); }, delay);
    };

    dots.forEach((d) => d.addEventListener('click', () => { show(Number(d.dataset.go), true); schedule(); }));
    ui.querySelector('[data-prev]')?.addEventListener('click', () => { show(index - 1, true); schedule(); });
    ui.querySelector('[data-next]')?.addEventListener('click', () => { show(index + 1, true); schedule(); });
    if (toggle) {
      if (!stage.hasAttribute('data-autoplay') || reduced) toggle.hidden = true;
      toggle.addEventListener('click', () => {
        userPaused = !userPaused;
        toggle.setAttribute('aria-pressed', String(userPaused));
        toggle.querySelector('.sr-only').textContent = userPaused ? 'Play slideshow' : 'Pause slideshow';
        schedule();
      });
    }
    section.addEventListener('mouseenter', () => { hover = true; schedule(); });
    section.addEventListener('mouseleave', () => { hover = false; schedule(); });
    section.addEventListener('focusin', () => { hover = true; schedule(); });
    section.addEventListener('focusout', (e) => { if (!section.contains(e.relatedTarget)) { hover = false; schedule(); } });
    document.addEventListener('visibilitychange', schedule);

    // Swipe (horizontal drags only; vertical scrolling is untouched).
    let x0 = null;
    let y0 = null;
    section.addEventListener('pointerdown', (e) => { if (e.pointerType !== 'mouse') { x0 = e.clientX; y0 = e.clientY; } });
    section.addEventListener('pointerup', (e) => {
      if (x0 === null) return;
      const dx = e.clientX - x0;
      const dy = e.clientY - y0;
      x0 = null;
      if (Math.abs(dx) > 50 && Math.abs(dx) > Math.abs(dy) * 1.5) { show(index + (dx < 0 ? 1 : -1), true); schedule(); }
    });
    section.addEventListener('pointercancel', () => { x0 = null; });

    show(0);
    schedule();
  });
}
