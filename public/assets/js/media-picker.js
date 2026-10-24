// Media library picker dialog (needs <template id="media-picker-template">).
// pickImage(opener) resolves with {id, title, thumb} or null when closed.
const csrf = document.querySelector('meta[name="csrf"]')?.content ?? '';

export function pickerAvailable() {
  return Boolean(document.getElementById('media-picker-template'));
}

export function pickImage(opener, kind = 'image') {
  const noun = kind === 'video' ? 'videos' : 'images';
  const tpl = document.getElementById('media-picker-template');
  if (!tpl) return Promise.resolve(null);
  return new Promise((resolve) => {
    const dialog = tpl.content.firstElementChild.cloneNode(true);
    document.body.append(dialog);
    const grid = dialog.querySelector('.picker-grid');
    const status = dialog.querySelector('.picker-status');
    const search = dialog.querySelector('input[type="search"]');
    let chosen = null;
    let timer = null;

    async function load(q = '') {
      status.textContent = 'Loading…';
      grid.replaceChildren();
      try {
        const res = await fetch(`/admin/media/picker?kind=${encodeURIComponent(kind)}&q=${encodeURIComponent(q)}`, {
          headers: { Accept: 'application/json', 'X-CSRF-Token': csrf }, credentials: 'same-origin',
        });
        if (!res.ok) throw new Error(`Request failed (${res.status})`);
        const data = await res.json();
        const items = Array.isArray(data.items) ? data.items : [];
        status.textContent = items.length ? `${items.length} ${noun}` : `No ${noun} found. Upload them in the Media library first.`;
        items.forEach((item) => {
          const li = document.createElement('li');
          const b = document.createElement('button');
          b.type = 'button';
          const img = document.createElement(item.thumb ? 'img' : 'span');
          if (item.thumb) {
            img.src = item.thumb;
            img.alt = '';
            img.loading = 'lazy';
          } else {
            img.className = 'picker-file';
            img.textContent = item.kind;
          }
          const span = document.createElement('span');
          span.textContent = item.title || `Image ${item.id}`;
          b.setAttribute('aria-label', `Choose ${item.title || 'image ' + item.id}`);
          b.append(img, span);
          b.addEventListener('click', () => { chosen = item; dialog.close(); });
          li.append(b);
          grid.append(li);
        });
      } catch (err) {
        status.textContent = err instanceof Error ? err.message : 'Could not load images.';
      }
    }

    search.addEventListener('input', () => {
      clearTimeout(timer);
      timer = setTimeout(() => load(search.value.trim()), 250);
    });
    dialog.querySelector('[data-close]').addEventListener('click', () => dialog.close());
    dialog.addEventListener('close', () => {
      dialog.remove();
      opener?.focus();
      resolve(chosen);
    });
    dialog.showModal();
    search.focus();
    load();
  });
}
