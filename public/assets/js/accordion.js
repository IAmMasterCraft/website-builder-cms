// FAQ accordion: native <details> (works without JavaScript) with a smooth
// height animation on open and close. Skipped for reduced motion.

const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

export function initAccordions() {
  if (reduced || !('animate' in Element.prototype)) return;
  document.querySelectorAll('details.faq').forEach((details) => {
    const summary = details.querySelector('summary');
    const body = details.querySelector('.faq-body');
    if (!summary || !body) return;
    let anim = null;
    summary.addEventListener('click', (e) => {
      e.preventDefault();
      anim?.cancel();
      if (details.open) {
        const from = body.offsetHeight;
        anim = body.animate([{ height: `${from}px`, opacity: 1 }, { height: '0px', opacity: 0 }], { duration: 260, easing: 'cubic-bezier(0.65, 0, 0.35, 1)' });
        anim.onfinish = () => { details.open = false; anim = null; };
      } else {
        details.open = true;
        const to = body.offsetHeight;
        anim = body.animate([{ height: '0px', opacity: 0 }, { height: `${to}px`, opacity: 1 }], { duration: 380, easing: 'cubic-bezier(0.16, 1, 0.3, 1)' });
        anim.onfinish = () => { anim = null; };
      }
    });
  });
}

// Showcase: highlight the index entry for the card in view.
export function initShowcase() {
  if (!('IntersectionObserver' in window)) return;
  document.querySelectorAll('[data-showcase]').forEach((root) => {
    const links = [...root.querySelectorAll('.sc-index a')];
    const cards = [...root.querySelectorAll('.sc-card')];
    if (!links.length) return;
    const io = new IntersectionObserver((entries) => {
      entries.forEach((entry) => {
        if (!entry.isIntersecting) return;
        const i = cards.indexOf(entry.target);
        links.forEach((a, n) => a.setAttribute('aria-current', String(n === i)));
      });
    }, { rootMargin: '-35% 0px -55% 0px' });
    cards.forEach((c) => io.observe(c));
  });
}
