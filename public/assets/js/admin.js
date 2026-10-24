// Admin enhancements. Every form works without this file; JS adds comfort.
import { pickImage, pickerAvailable } from './media-picker.js';
import { initRichText } from './rt.js';

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

// Rich text editors (server sanitises whatever is submitted).
document.querySelectorAll('[data-rt]').forEach((wrap) => initRichText(wrap));

// Media picker for image fields.
document.querySelectorAll('[data-media-field]').forEach((field) => {
  const pick = field.querySelector('[data-media-pick]');
  if (!pick || !pickerAvailable()) return;
  pick.hidden = false;
  pick.addEventListener('click', async () => {
    const item = await pickImage(pick);
    if (!item) return;
    field.querySelector('[data-media-input]').value = String(item.id);
    const img = document.createElement('img');
    img.src = item.thumb ?? '';
    img.alt = '';
    field.querySelector('[data-media-preview]').replaceChildren(img);
  });
});

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

// Header & footer screen: show only the inputs the chosen widget uses.
document.querySelectorAll('[data-footer-col]').forEach((col) => {
  const select = col.querySelector('[data-col-widget]');
  const update = () => {
    col.querySelectorAll('[data-col-show]').forEach((f) => { f.hidden = !(f.dataset.colShow ?? '').split(' ').includes(select.value); });
  };
  select?.addEventListener('change', update);
  update();
});

// Inbox: opening a message marks it read (a POST with the CSRF token; the
// button still works without JavaScript).
document.querySelectorAll('form[data-auto-read]').forEach(async (form) => {
  try {
    const res = await fetch(form.action, { method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-Token': csrf }, body: new FormData(form), credentials: 'same-origin' });
    if (res.ok) form.hidden = true;
  } catch { /* leave the button */ }
});
