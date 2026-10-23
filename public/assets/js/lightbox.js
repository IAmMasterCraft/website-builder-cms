// Accessible lightbox for [data-lightbox] photo grids, built on <dialog>
// (focus is contained by the browser; Escape closes; arrows navigate).
export function initLightbox() {
  const grids = document.querySelectorAll('[data-lightbox]');
  if (!grids.length || typeof HTMLDialogElement !== 'function') return;

  const dialog = document.createElement('dialog');
  dialog.className = 'lightbox';
  dialog.setAttribute('aria-label', 'Photo viewer');
  const bar = document.createElement('div');
  bar.className = 'lightbox-bar';
  const counter = document.createElement('span');
  counter.setAttribute('aria-live', 'polite');
  const close = button('close', 'Close photo viewer');
  bar.append(counter, close);
  const stage = document.createElement('div');
  stage.className = 'lightbox-stage';
  const img = document.createElement('img');
  img.alt = '';
  const prev = button('chevron-left', 'Previous photo', 'prev');
  const next = button('chevron-right', 'Next photo', 'next');
  stage.append(prev, img, next);
  const caption = document.createElement('p');
  caption.className = 'lightbox-caption';
  dialog.append(bar, stage, caption);
  document.body.append(dialog);

  let links = [];
  let index = 0;
  let opener = null;

  function button(iconName, label, cls = '') {
    const b = document.createElement('button');
    b.type = 'button';
    if (cls) b.className = cls;
    b.setAttribute('aria-label', label);
    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('class', 'icon');
    svg.setAttribute('aria-hidden', 'true');
    const use = document.createElementNS('http://www.w3.org/2000/svg', 'use');
    const sprite = document.querySelector('svg.icon use')?.getAttribute('href')?.split('#')[0] ?? '/assets/img/icons.svg';
    use.setAttribute('href', `${sprite}#${iconName}`);
    svg.append(use);
    b.append(svg);
    return b;
  }

  function show(i) {
    index = (i + links.length) % links.length;
    const a = links[index];
    const thumb = a.querySelector('img');
    img.src = a.href;
    img.alt = thumb?.alt ?? '';
    caption.textContent = a.dataset.caption ?? '';
    counter.textContent = `${index + 1} / ${links.length}`;
    prev.hidden = next.hidden = links.length < 2;
  }

  grids.forEach((grid) => {
    grid.addEventListener('click', (e) => {
      const a = e.target instanceof Element ? e.target.closest('a') : null;
      if (!a || e.metaKey || e.ctrlKey || e.shiftKey) return;
      e.preventDefault();
      links = [...grid.querySelectorAll('a')];
      opener = a;
      show(links.indexOf(a));
      dialog.showModal();
      close.focus();
    });
  });
  prev.addEventListener('click', () => show(index - 1));
  next.addEventListener('click', () => show(index + 1));
  close.addEventListener('click', () => dialog.close());
  dialog.addEventListener('keydown', (e) => {
    if (e.key === 'ArrowLeft') show(index - 1);
    if (e.key === 'ArrowRight') show(index + 1);
  });
  dialog.addEventListener('click', (e) => { if (e.target === stage) dialog.close(); });
  dialog.addEventListener('close', () => { img.removeAttribute('src'); opener?.focus(); });
}
