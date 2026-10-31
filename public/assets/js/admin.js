// Admin enhancements. Every form works without this file; JS adds comfort.
import { pickImage, pickerAvailable } from './media-picker.js';
import { initRichText } from './rt.js';
import { initFocal } from './focal.js';
import { initMessages } from './messages.js';
import { initPayments } from './payments.js';

const csrf = document.querySelector('meta[name="csrf"]')?.content ?? '';
const root = document.documentElement;

function store(key, value) {
  try { localStorage.setItem(key, value); } catch { /* storage blocked */ }
}

// Sidebar groups (B1): each group header is a button that opens/closes its
// links; the open state is remembered per browser (the group of the current
// page always opens). In icon mode (folded sidebar, builder, design) a
// group's links appear in a flyout on hover, focus or click.
const desktop = window.matchMedia('(min-width: 64rem)');
const navGroups = [...document.querySelectorAll('.nav-group')];
const NAV_KEY = 'gather-admin-nav';
const iconMode = () => desktop.matches && (root.classList.contains('sidebar-collapsed') || document.body.classList.contains('builder-mode') || document.body.classList.contains('design-mode'));
const navState = (() => {
  try {
    const v = JSON.parse(localStorage.getItem(NAV_KEY) ?? '{}');
    return v && typeof v === 'object' && !Array.isArray(v) ? v : {};
  } catch { return {}; }
})();
const toggleOf = (group) => group.querySelector('.nav-group-toggle');
const allButton = document.querySelector('[data-nav-all]');

function syncNavAria() {
  navGroups.forEach((g) => {
    const open = iconMode() ? g.classList.contains('flyout-open') : g.classList.contains('is-open');
    toggleOf(g)?.setAttribute('aria-expanded', String(open));
  });
  if (allButton) {
    const allOpen = navGroups.every((g) => g.classList.contains('is-open'));
    allButton.dataset.state = allOpen ? 'expanded' : 'collapsed';
    const label = allButton.querySelector('[data-nav-all-label]');
    if (label) label.textContent = allOpen ? 'Collapse all' : 'Expand all';
  }
}
function setGroupOpen(group, open, remember) {
  group.classList.toggle('is-open', open);
  group.classList.toggle('is-closed', !open);
  if (remember) {
    navState[group.dataset.group] = open;
    store(NAV_KEY, JSON.stringify(navState));
  }
}
function closeFlyout(group) {
  group.classList.remove('flyout-open');
  if (iconMode()) toggleOf(group)?.setAttribute('aria-expanded', 'false');
}
// Keep a flyout inside the window (groups near the bottom open upwards).
function placeFlyout(group) {
  const list = group.querySelector('.nav-items');
  if (!list) return;
  list.style.top = '';
  const r = list.getBoundingClientRect();
  if (r.height > 0 && r.bottom > window.innerHeight - 8) {
    list.style.top = `${Math.max(8 - r.top, window.innerHeight - 8 - r.bottom)}px`;
  }
}
function showFlyout(group) {
  if (!iconMode()) return;
  navGroups.forEach((g) => { if (g !== group) closeFlyout(g); });
  group.classList.remove('flyout-closed');
  group.classList.add('flyout-open');
  toggleOf(group)?.setAttribute('aria-expanded', 'true');
  placeFlyout(group);
}

navGroups.forEach((group) => {
  setGroupOpen(group, group.classList.contains('is-active-parent') || navState[group.dataset.group] === true, false);
  const btn = toggleOf(group);
  btn?.addEventListener('click', () => {
    if (iconMode()) {
      if (group.classList.contains('flyout-open') && !group.classList.contains('flyout-closed')) {
        group.classList.add('flyout-closed');
        closeFlyout(group);
      } else {
        showFlyout(group);
      }
      return;
    }
    setGroupOpen(group, !group.classList.contains('is-open'), true);
    syncNavAria();
  });
  group.addEventListener('mouseenter', () => showFlyout(group));
  group.addEventListener('mouseleave', () => { if (iconMode() && !group.contains(document.activeElement)) closeFlyout(group); });
  group.addEventListener('focusin', () => { if (!group.classList.contains('flyout-closed')) showFlyout(group); });
  group.addEventListener('focusout', (e) => {
    if (!(e.relatedTarget instanceof Node) || !group.contains(e.relatedTarget)) {
      group.classList.remove('flyout-closed');
      closeFlyout(group);
    }
  });
  group.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && iconMode() && group.classList.contains('flyout-open')) {
      e.stopPropagation();
      group.classList.add('flyout-closed');
      closeFlyout(group);
      btn?.focus();
    }
  });
});
if (allButton && navGroups.length > 1) {
  allButton.closest('[data-nav-tools]')?.removeAttribute('hidden');
  allButton.addEventListener('click', () => {
    const open = !navGroups.every((g) => g.classList.contains('is-open'));
    navGroups.forEach((g) => setGroupOpen(g, open, true));
    syncNavAria();
  });
}
syncNavAria();
desktop.addEventListener('change', () => { navGroups.forEach((g) => g.classList.remove('flyout-open', 'flyout-closed')); syncNavAria(); });

// Sidebar: collapse to icons on desktop, slide-over on mobile.
const sidebarToggle = document.querySelector('[data-sidebar-toggle]');
if (sidebarToggle && desktop.matches && root.classList.contains('sidebar-collapsed')) sidebarToggle.setAttribute('aria-expanded', 'false');
document.querySelector('[data-sidebar-toggle]')?.addEventListener('click', (e) => {
  const btn = e.currentTarget;
  if (desktop.matches) {
    const collapsed = root.classList.toggle('sidebar-collapsed');
    store('gather-admin-sidebar', collapsed ? 'collapsed' : 'open');
    btn.setAttribute('aria-expanded', String(!collapsed));
    navGroups.forEach((g) => g.classList.remove('flyout-open', 'flyout-closed'));
    syncNavAria();
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

// Tables that scroll sideways (narrow screens) must be reachable with the
// keyboard: make the scroller focusable and name it after the table's
// heading when it has no focusable content of its own.
const scrollers = [...document.querySelectorAll('.table-wrap')];
const markScrollers = () => {
  scrollers.forEach((wrap) => {
    const scrolls = wrap.scrollWidth > wrap.clientWidth + 1;
    const focusable = wrap.querySelector('a[href], button, input, select, textarea, [tabindex]:not([tabindex="-1"])');
    if (scrolls && !focusable) {
      wrap.tabIndex = 0;
      wrap.setAttribute('role', 'region');
      const heading = wrap.closest('.panel')?.querySelector('h2')?.textContent?.trim();
      wrap.setAttribute('aria-label', heading ? `${heading} (scrolls sideways)` : 'Table (scrolls sideways)');
    } else if (wrap.getAttribute('role') === 'region') {
      wrap.removeAttribute('tabindex');
      wrap.removeAttribute('role');
      wrap.removeAttribute('aria-label');
    }
  });
};
if (scrollers.length) {
  markScrollers();
  window.addEventListener('resize', markScrollers, { passive: true });
}

initFocal();
initMessages();
initPayments();
