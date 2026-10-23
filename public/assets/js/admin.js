// Admin enhancements. Every form works without this file; JS adds comfort.
const csrf = document.querySelector('meta[name="csrf"]')?.content ?? '';
const root = document.documentElement;

function store(key, value) {
  try { localStorage.setItem(key, value); } catch { /* storage blocked */ }
}

// Sidebar: collapse on desktop, slide-over on mobile.
document.querySelector('[data-sidebar-toggle]')?.addEventListener('click', (e) => {
  const btn = e.currentTarget;
  if (window.matchMedia('(min-width: 64rem)').matches) {
    const collapsed = root.classList.toggle('sidebar-collapsed');
    store('gather-admin-sidebar', collapsed ? 'collapsed' : 'open');
    btn.setAttribute('aria-expanded', String(!collapsed));
  } else {
    const open = document.body.classList.toggle('sidebar-open');
    btn.setAttribute('aria-expanded', String(open));
  }
});
document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape' && document.body.classList.contains('sidebar-open')) document.body.classList.remove('sidebar-open');
});

// Light / dark mode toggle (defaults to the system preference).
document.querySelector('[data-theme-toggle]')?.addEventListener('click', () => {
  const systemDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
  const current = root.dataset.theme ?? (systemDark ? 'dark' : 'light');
  const next = current === 'dark' ? 'light' : 'dark';
  root.dataset.theme = next;
  store('gather-admin-theme', next);
});

// Close the profile menu when clicking elsewhere.
document.addEventListener('click', (e) => {
  document.querySelectorAll('details.profile[open]').forEach((d) => {
    if (!(e.target instanceof Node) || !d.contains(e.target)) d.removeAttribute('open');
  });
});

// Confirm destructive actions.
document.querySelectorAll('form[data-confirm]').forEach((form) => {
  form.addEventListener('submit', (e) => {
    if (!window.confirm(form.dataset.confirm ?? 'Are you sure?')) e.preventDefault();
  });
});

// Rich text: a small toolbar over a contenteditable area synced to the textarea.
// The server sanitises whatever is submitted (Core\Sanitizer).
const RT_BUTTONS = [
  ['B', 'bold', 'Bold'], ['I', 'italic', 'Italic'], ['H2', 'h2', 'Heading'], ['H3', 'h3', 'Subheading'],
  ['• List', 'insertUnorderedList', 'Bulleted list'], ['1. List', 'insertOrderedList', 'Numbered list'],
  ['“ ”', 'blockquote', 'Quote'], ['Link', 'link', 'Add link'], ['Unlink', 'unlink', 'Remove link'], ['HTML', 'source', 'Edit HTML'],
];
document.querySelectorAll('[data-rt]').forEach((wrap) => {
  const editor = wrap.querySelector('.rt-editor');
  const textarea = wrap.querySelector('textarea[data-richtext]');
  if (!editor || !textarea) return;
  editor.hidden = false;
  wrap.classList.add('rt-ready');
  let sourceMode = false;
  const toolbar = document.createElement('div');
  toolbar.className = 'rt-toolbar';
  toolbar.setAttribute('role', 'toolbar');
  toolbar.setAttribute('aria-label', 'Formatting');
  const sync = () => { if (!sourceMode) textarea.value = editor.innerHTML; };
  RT_BUTTONS.forEach(([text, cmd, label]) => {
    const b = document.createElement('button');
    b.type = 'button';
    b.textContent = text;
    b.title = label;
    b.setAttribute('aria-label', label);
    if (cmd === 'source') b.setAttribute('aria-pressed', 'false');
    b.addEventListener('click', () => {
      if (cmd === 'source') {
        sourceMode = !sourceMode;
        b.setAttribute('aria-pressed', String(sourceMode));
        if (sourceMode) {
          textarea.value = editor.innerHTML;
          textarea.style.display = 'block';
          editor.hidden = true;
          textarea.focus();
        } else {
          // Back to visual mode: parse the HTML inertly, drop anything active,
          // then show it. The server sanitises again on save.
          editor.replaceChildren(...safeNodes(textarea.value));
          editor.hidden = false;
          textarea.style.display = '';
          editor.focus();
        }
        return;
      }
      if (sourceMode) return;
      editor.focus();
      if (cmd === 'h2' || cmd === 'h3' || cmd === 'blockquote') {
        document.execCommand('formatBlock', false, cmd);
      } else if (cmd === 'link') {
        const url = window.prompt('Link address (https://… or /page)');
        if (url && /^(https?:\/\/|\/(?!\/)|mailto:)/i.test(url.trim())) document.execCommand('createLink', false, url.trim());
      } else {
        document.execCommand(cmd, false);
      }
      sync();
    });
    toolbar.append(b);
  });
  wrap.prepend(toolbar);
  editor.addEventListener('input', sync);
  editor.addEventListener('blur', sync);
  textarea.form?.addEventListener('submit', sync);
});

function safeNodes(html) {
  const doc = new DOMParser().parseFromString(`<body>${html}</body>`, 'text/html');
  doc.querySelectorAll('script,style,iframe,object,embed,form,input,button,textarea,select,link,meta,svg,math').forEach((el) => el.remove());
  doc.body.querySelectorAll('*').forEach((el) => {
    [...el.attributes].forEach((attr) => {
      const name = attr.name.toLowerCase();
      const value = attr.value.trim().toLowerCase();
      if (name.startsWith('on') || name === 'style' || ((name === 'href' || name === 'src') && /^(javascript|data|vbscript):/.test(value))) {
        el.removeAttribute(attr.name);
      }
    });
  });
  return [...doc.body.childNodes].map((n) => document.importNode(n, true));
}

// Media picker for image fields: fetch the library and choose an image.
const pickerTemplate = document.getElementById('media-picker-template');
document.querySelectorAll('[data-media-field]').forEach((field) => {
  const pick = field.querySelector('[data-media-pick]');
  if (!pick || !pickerTemplate) return;
  pick.hidden = false;
  pick.addEventListener('click', () => openPicker(field, pick));
});

function openPicker(field, opener) {
  const dialog = pickerTemplate.content.firstElementChild.cloneNode(true);
  document.body.append(dialog);
  const grid = dialog.querySelector('.picker-grid');
  const status = dialog.querySelector('.picker-status');
  const search = dialog.querySelector('input[type="search"]');
  let timer = null;

  async function load(q = '') {
    status.textContent = 'Loading…';
    grid.replaceChildren();
    try {
      const res = await fetch(`/admin/media/picker?kind=image&q=${encodeURIComponent(q)}`, {
        headers: { Accept: 'application/json', 'X-CSRF-Token': csrf }, credentials: 'same-origin',
      });
      if (!res.ok) throw new Error(`Request failed (${res.status})`);
      const data = await res.json();
      const items = Array.isArray(data.items) ? data.items : [];
      status.textContent = items.length ? `${items.length} images` : 'No images found. Upload one with the file field instead.';
      items.forEach((item) => {
        const li = document.createElement('li');
        const b = document.createElement('button');
        b.type = 'button';
        const img = document.createElement('img');
        img.src = item.thumb ?? '';
        img.alt = '';
        img.loading = 'lazy';
        const span = document.createElement('span');
        span.textContent = item.title || `Image ${item.id}`;
        b.setAttribute('aria-label', `Choose ${item.title || 'image ' + item.id}`);
        b.append(img, span);
        b.addEventListener('click', () => choose(item));
        li.append(b);
        grid.append(li);
      });
    } catch (err) {
      status.textContent = err instanceof Error ? err.message : 'Could not load images.';
    }
  }

  function choose(item) {
    field.querySelector('[data-media-input]').value = String(item.id);
    const preview = field.querySelector('[data-media-preview]');
    const img = document.createElement('img');
    img.src = item.thumb ?? '';
    img.alt = '';
    preview.replaceChildren(img);
    dialog.close();
  }

  search.addEventListener('input', () => {
    clearTimeout(timer);
    timer = setTimeout(() => load(search.value.trim()), 250);
  });
  dialog.querySelector('[data-close]').addEventListener('click', () => dialog.close());
  dialog.addEventListener('close', () => { dialog.remove(); opener.focus(); });
  dialog.showModal();
  search.focus();
  load();
}

// Event tickets: add more rows.
document.querySelectorAll('[data-add-ticket]').forEach((btn) => {
  const rows = btn.closest('fieldset')?.querySelector('[data-ticket-rows]');
  if (!rows) return;
  btn.hidden = false;
  btn.addEventListener('click', () => {
    const last = rows.lastElementChild;
    if (!last) return;
    const clone = last.cloneNode(true);
    const n = rows.children.length;
    clone.querySelectorAll('input').forEach((input) => { input.value = ''; });
    clone.querySelectorAll('[id]').forEach((el) => { el.id = el.id.replace(/\d+$/, String(n)); });
    clone.querySelectorAll('label[for]').forEach((el) => { el.htmlFor = el.htmlFor.replace(/\d+$/, String(n)); });
    rows.append(clone);
    clone.querySelector('input[type="text"]')?.focus();
  });
});

// Menu item form: show only the panel for the chosen link type.
document.querySelectorAll('[data-target-choice]').forEach((set) => {
  const update = () => {
    const checked = set.querySelector('input[name="target_type"]:checked')?.value ?? 'page';
    set.querySelectorAll('[data-target-panel]').forEach((panel) => { panel.hidden = panel.dataset.targetPanel !== checked; });
  };
  set.addEventListener('change', update);
  update();
});

// Event check-in without a page reload.
document.querySelectorAll('form[data-checkin]').forEach((form) => {
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const button = form.querySelector('button');
    button.disabled = true;
    try {
      const res = await fetch(form.action, {
        method: 'POST',
        headers: { Accept: 'application/json', 'X-CSRF-Token': csrf },
        body: new FormData(form),
        credentials: 'same-origin',
      });
      if (!res.ok) throw new Error(String(res.status));
      await res.json();
      window.location.reload();
    } catch {
      form.submit();
    } finally {
      button.disabled = false;
    }
  });
});
