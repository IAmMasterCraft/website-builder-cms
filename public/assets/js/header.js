// Header: glass state on scroll, full-screen mobile menu, submenu disclosures.
// Without JavaScript the header stays solid and the menu is a plain list.

export function initHeader() {
  const header = document.querySelector('.site-header');
  if (!header) return;

  // Glass bar once the page scrolls (and always solid again at the very top).
  let ticking = false;
  const onScroll = () => {
    ticking = false;
    header.classList.toggle('is-scrolled', window.scrollY > 12);
  };
  window.addEventListener('scroll', () => {
    if (!ticking) { ticking = true; requestAnimationFrame(onScroll); }
  }, { passive: true });
  onScroll();

  // Mobile menu: full-screen overlay with staggered links.
  const toggle = header.querySelector('.nav-toggle');
  const nav = document.getElementById('site-nav');
  const mobile = window.matchMedia('(max-width: 63.99rem)');
  const label = toggle?.querySelector('.nav-toggle-label');
  const setOpen = (open, focusToggle = false) => {
    if (!toggle || !nav) return;
    toggle.setAttribute('aria-expanded', String(open));
    if (label) label.textContent = open ? 'Close' : 'Menu';
    if (open) nav.style.setProperty('--nav-top', `${Math.max(0, Math.round(header.getBoundingClientRect().bottom))}px`);
    nav.classList.toggle('open', open);
    document.body.classList.toggle('nav-open', open && mobile.matches);
    if (open && mobile.matches) {
      nav.querySelector('a, button')?.focus({ preventScroll: true });
    } else if (focusToggle) {
      toggle.focus();
    }
  };
  if (toggle && nav) {
    toggle.addEventListener('click', () => setOpen(toggle.getAttribute('aria-expanded') !== 'true'));
    nav.addEventListener('click', (e) => {
      if (e.target instanceof Element && e.target.closest('a')) setOpen(false);
    });
    // Keep Tab inside the open overlay (the toggle stays reachable to close it).
    document.addEventListener('keydown', (e) => {
      if (e.key !== 'Tab' || !nav.classList.contains('open') || !mobile.matches) return;
      const items = [toggle, ...nav.querySelectorAll('a[href], button:not([disabled])')].filter((el) => el.offsetParent !== null || el === toggle);
      const first = items[0];
      const last = items[items.length - 1];
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    });
    mobile.addEventListener('change', () => { if (!mobile.matches) setOpen(false); });
  }

  // Submenus: disclosure buttons; Escape and outside clicks close them.
  const subs = [...header.querySelectorAll('.site-nav .has-sub')];
  const closeAll = (except) => {
    subs.forEach((li) => {
      if (li !== except) {
        li.classList.remove('open');
        li.querySelector('.sub-toggle')?.setAttribute('aria-expanded', 'false');
      }
    });
  };
  subs.forEach((li) => {
    const btn = li.querySelector('.sub-toggle');
    let hoverAt = 0;
    btn?.addEventListener('click', () => {
      // A click right after hovering opened it keeps it open instead of closing it.
      if (li.classList.contains('open') && Date.now() - hoverAt < 700) return;
      const open = !li.classList.contains('open');
      closeAll(li);
      li.classList.toggle('open', open);
      btn.setAttribute('aria-expanded', String(open));
    });
    // Desktop: open on hover too, with a short grace period when leaving.
    let leave = null;
    li.addEventListener('pointerenter', (e) => {
      if (e.pointerType !== 'mouse' || mobile.matches) return;
      clearTimeout(leave);
      closeAll(li);
      if (!li.classList.contains('open')) hoverAt = Date.now();
      li.classList.add('open');
      btn?.setAttribute('aria-expanded', 'true');
    });
    li.addEventListener('pointerleave', (e) => {
      if (e.pointerType !== 'mouse' || mobile.matches) return;
      leave = setTimeout(() => { li.classList.remove('open'); btn?.setAttribute('aria-expanded', 'false'); }, 180);
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
    } else if (nav?.classList.contains('open')) {
      setOpen(false, true);
    }
  });
}
