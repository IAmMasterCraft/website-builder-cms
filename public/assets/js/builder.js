// Page builder (§3.1): section list with drag + keyboard reorder, add from a
// thumbnail picker, duplicate / hide / delete, settings forms generated from
// each section type's schema, autosave of the draft, live iframe preview and
// an optimistic-lock conflict warning. Server text never goes into innerHTML.
import { pickImage } from './media-picker.js';
import { initRichText, safeNodes } from './rt.js';

const root = document.querySelector('[data-builder]');
const csrf = document.querySelector('meta[name="csrf"]')?.content ?? '';
const iconSprite = document.querySelector('svg use')?.getAttribute('href')?.split('#')[0] ?? '/assets/img/icons.svg';

let config = null;
try { config = JSON.parse(document.getElementById('builder-config')?.textContent ?? 'null'); } catch { config = null; }
if (root && config) init();

function init() {
  const $ = (sel) => root.querySelector(sel);
  const state = {
    sections: Array.isArray(config.sections) ? config.sections : [],
    revision: config.page.revision,
    selected: null,
    errors: {},
    dirty: false,
    saving: false,
    queued: false,
    conflict: false,
    timer: null,
    templates: config.templates ?? [],
    media: config.media ?? {},
  };
  const types = config.types;
  const list = $('[data-section-list]');
  const listView = $('[data-list-view]');
  const editView = $('[data-edit-view]');
  const settingsForm = $('[data-settings-form]');
  const styleForm = $('[data-style-form]');
  const frame = $('[data-preview]');
  const saveState = $('[data-save-state]');
  const announcer = $('[data-announce]');
  const picker = $('[data-picker]');

  // ---- small helpers ---------------------------------------------------------
  function h(tag, attrs = {}, ...children) {
    const node = document.createElement(tag);
    Object.entries(attrs).forEach(([k, v]) => {
      if (v === null || v === undefined || v === false) return;
      if (k === 'text') node.textContent = v;
      else if (k === 'class') node.className = v;
      else if (k.startsWith('on')) node.addEventListener(k.slice(2), v);
      else node.setAttribute(k, v === true ? '' : String(v));
    });
    children.flat().forEach((c) => { if (c !== null && c !== undefined) node.append(c); });
    return node;
  }
  function icon(name) {
    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('class', 'icon');
    svg.setAttribute('aria-hidden', 'true');
    svg.setAttribute('focusable', 'false');
    const use = document.createElementNS('http://www.w3.org/2000/svg', 'use');
    use.setAttribute('href', `${iconSprite}#${name}`);
    svg.append(use);
    return svg;
  }
  function iconButton(name, label, onClick, extra = {}) {
    return h('button', { type: 'button', class: 'icon-btn small', onclick: onClick, ...extra }, icon(name), h('span', { class: 'sr-only', text: label }));
  }
  const newId = () => 's' + Array.from(crypto.getRandomValues(new Uint8Array(5)), (b) => b.toString(16).padStart(2, '0')).join('');
  const clone = (v) => JSON.parse(JSON.stringify(v));
  const find = (id) => state.sections.find((s) => s.id === id);
  const indexOf = (id) => state.sections.findIndex((s) => s.id === id);
  const label = (s) => types[s.type]?.label ?? s.type;
  function summary(s) {
    if (s.type === 'spacer') return `${s.settings.size ?? ''}${s.settings.divider && s.settings.divider !== 'none' ? `, ${s.settings.divider}` : ''}`;
    for (const k of ['heading', 'title', 'label']) {
      if (typeof s.settings?.[k] === 'string' && s.settings[k].trim() !== '') return s.settings[k].trim();
    }
    return '';
  }
  function announce(msg) {
    announcer.textContent = '';
    window.setTimeout(() => { announcer.textContent = msg; }, 30);
  }
  function setSaveState(text, kind = '') {
    saveState.textContent = text;
    saveState.dataset.kind = kind;
  }

  // ---- section list ----------------------------------------------------------
  function renderList(focus = null) {
    list.replaceChildren(...state.sections.map((s, i) => listItem(s, i)));
    $('[data-empty]').hidden = state.sections.length > 0;
    if (focus) list.querySelector(`[data-id="${focus.id}"] [data-role="${focus.role}"]`)?.focus();
  }

  function listItem(s, i) {
    const n = state.sections.length;
    const hasErrors = Boolean(state.errors[s.id]);
    const name = label(s);
    const li = h('li', {
      class: `section-item${s.hidden ? ' is-hidden' : ''}${state.selected === s.id ? ' is-selected' : ''}${hasErrors ? ' has-error' : ''}`,
      'data-id': s.id, draggable: 'true',
    });
    const handle = h('button', {
      type: 'button', class: 'drag-handle', 'data-role': 'handle',
      'aria-label': `Move ${name}, position ${i + 1} of ${n}. Use the up and down arrow keys.`,
      onkeydown: (e) => {
        if (e.key === 'ArrowUp' || e.key === 'ArrowDown') {
          e.preventDefault();
          move(s.id, e.key === 'ArrowUp' ? -1 : 1, 'handle');
        }
      },
    }, h('span', { class: 'grip', 'aria-hidden': 'true' }));
    const open = h('button', { type: 'button', class: 'section-open', 'data-role': 'open', onclick: () => select(s.id, true) },
      thumb(s.type),
      h('span', { class: 'section-text' }, h('strong', { text: name }), h('span', { class: 'muted', text: summary(s) || types[s.type]?.description || '' })),
      hasErrors ? h('span', { class: 'badge bad', text: 'Check' }) : null,
      s.hidden ? h('span', { class: 'badge', text: 'Hidden' }) : null);
    const actions = h('div', { class: 'section-actions' },
      iconButton('arrow-up', `Move ${name} up`, () => move(s.id, -1, 'up'), { 'data-role': 'up', disabled: i === 0 }),
      iconButton('arrow-down', `Move ${name} down`, () => move(s.id, 1, 'down'), { 'data-role': 'down', disabled: i === n - 1 }),
      iconButton('layers', `Duplicate ${name}`, () => duplicate(s.id), { 'data-role': 'dup' }),
      iconButton(s.hidden ? 'eye-off' : 'eye', s.hidden ? `Show ${name} to visitors` : `Hide ${name} from visitors`, () => toggleHidden(s.id), { 'data-role': 'hide', 'aria-pressed': String(Boolean(s.hidden)) }),
      iconButton('trash', `Delete ${name}`, () => remove(s.id), { 'data-role': 'del' }));
    li.append(handle, open, actions);
    // Drag and drop (the arrow buttons and keys do the same without a mouse).
    li.addEventListener('dragstart', (e) => {
      li.classList.add('dragging');
      e.dataTransfer.effectAllowed = 'move';
      e.dataTransfer.setData('text/plain', s.id);
    });
    li.addEventListener('dragend', () => { li.classList.remove('dragging'); clearDropMarks(); });
    li.addEventListener('dragover', (e) => {
      e.preventDefault();
      const r = li.getBoundingClientRect();
      clearDropMarks();
      li.classList.add(e.clientY < r.top + r.height / 2 ? 'drop-before' : 'drop-after');
    });
    li.addEventListener('drop', (e) => {
      e.preventDefault();
      const id = e.dataTransfer.getData('text/plain');
      const after = li.classList.contains('drop-after');
      clearDropMarks();
      if (!id || id === s.id) return;
      const from = indexOf(id);
      const [moved] = state.sections.splice(from, 1);
      let to = indexOf(s.id) + (after ? 1 : 0);
      state.sections.splice(to, 0, moved);
      to = indexOf(id);
      renderList();
      announce(`${label(moved)} moved to position ${to + 1} of ${state.sections.length}.`);
      changed();
    });
    return li;
  }
  function clearDropMarks() { list.querySelectorAll('.drop-before, .drop-after').forEach((x) => x.classList.remove('drop-before', 'drop-after')); }
  function thumb(type) {
    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('class', 'section-thumb');
    svg.setAttribute('viewBox', '0 0 120 80');
    svg.setAttribute('aria-hidden', 'true');
    svg.setAttribute('focusable', 'false');
    const use = document.createElementNS('http://www.w3.org/2000/svg', 'use');
    use.setAttribute('href', `${config.urls.thumbs}#sec-${type}`);
    svg.append(use);
    return svg;
  }

  // ---- operations ------------------------------------------------------------
  function move(id, delta, role) {
    const i = indexOf(id);
    const j = i + delta;
    if (i < 0 || j < 0 || j >= state.sections.length) return;
    [state.sections[i], state.sections[j]] = [state.sections[j], state.sections[i]];
    renderList({ id, role: role === 'up' && j === 0 ? 'down' : role === 'down' && j === state.sections.length - 1 ? 'up' : role });
    announce(`${label(state.sections[j])} moved to position ${j + 1} of ${state.sections.length}.`);
    changed();
  }
  function insert(sections, select_ = true) {
    const at = state.selected ? indexOf(state.selected) + 1 : state.sections.length;
    state.sections.splice(at, 0, ...sections);
    renderList();
    changed();
    if (select_ && sections.length === 1) select(sections[0].id, true);
    announce(sections.length === 1 ? `${label(sections[0])} added at position ${at + 1}.` : `${sections.length} sections added.`);
  }
  function add(type) {
    const t = types[type];
    if (!t) return;
    insert([{ id: newId(), type, hidden: false, settings: clone(t.defaults), style: clone(t.style) }]);
  }
  function duplicate(id) {
    const s = find(id);
    if (!s) return;
    const copy = { ...clone(s), id: newId() };
    state.sections.splice(indexOf(id) + 1, 0, copy);
    renderList({ id: copy.id, role: 'open' });
    announce(`${label(s)} duplicated.`);
    changed();
  }
  function toggleHidden(id) {
    const s = find(id);
    if (!s) return;
    s.hidden = !s.hidden;
    renderList({ id, role: 'hide' });
    announce(s.hidden ? `${label(s)} hidden from visitors.` : `${label(s)} shown to visitors.`);
    changed();
  }
  function remove(id) {
    const s = find(id);
    if (!s || !window.confirm(`Delete the ${label(s)} section? You can get it back from History.`)) return;
    const i = indexOf(id);
    state.sections.splice(i, 1);
    delete state.errors[id];
    if (state.selected === id) back(false);
    renderList();
    const next = state.sections[Math.min(i, state.sections.length - 1)];
    if (next) list.querySelector(`[data-id="${next.id}"] [data-role="open"]`)?.focus();
    else $('[data-open-picker]').focus();
    announce(`${label(s)} deleted.`);
    changed();
  }

  // ---- edit view ---------------------------------------------------------------
  function select(id, focusTitle = false) {
    const s = find(id);
    if (!s) return;
    state.selected = id;
    listView.hidden = true;
    editView.hidden = false;
    $('[data-edit-title]').textContent = label(s);
    buildSettings(s);
    buildStyle(s);
    showTab(editView.querySelector('#tab-content'));
    if (focusTitle) $('[data-edit-title]').focus();
    highlightInFrame(true);
    renderList();
  }
  function back(focus = true) {
    const id = state.selected;
    state.selected = null;
    editView.hidden = true;
    listView.hidden = false;
    renderList(focus && id ? { id, role: 'open' } : null);
    highlightInFrame(false);
  }

  function fieldActive(field, values) {
    if (!field.show_if) return true;
    const [other, allowed] = field.show_if;
    const v = values?.[other];
    return allowed.some((a) => (typeof a === 'boolean' ? Boolean(v) === a : String(v ?? '') === String(a)));
  }

  function buildSettings(s) {
    const t = types[s.type];
    settingsForm.replaceChildren(...t.schema.map((f) => field(f, s.settings, f.key, () => refreshConditions(settingsForm, t.schema, s.settings))));
    refreshConditions(settingsForm, t.schema, s.settings);
    showErrors();
  }
  function refreshConditions(container, schema, values) {
    schema.forEach((f) => {
      if (!f.show_if) return;
      const node = container.querySelector(`:scope > [data-key="${f.key}"]`);
      if (node) node.hidden = !fieldActive(f, values);
    });
  }

  function buildStyle(s) {
    const o = config.styleOptions;
    const schema = [
      { key: 'background', type: 'select', label: 'Background', options: Object.entries(o.background).map(([value, l]) => ({ value, label: l })) },
      { key: 'bg_media_id', type: 'media', label: 'Background image', show_if: ['background', ['image']] },
      { key: 'spacing', type: 'select', label: 'Spacing above and below', options: Object.entries(o.spacing).map(([value, l]) => ({ value, label: l })) },
      { key: 'width', type: 'select', label: 'Width', options: Object.entries(o.width).map(([value, l]) => ({ value, label: l })) },
      { key: 'anchor', type: 'text', label: 'Anchor name (for links like /page#name)', max: 40, help: 'Optional. Lowercase letters, numbers and dashes.' },
    ];
    styleForm.replaceChildren(...schema.map((f) => field(f, s.style, `style.${f.key}`, () => refreshConditions(styleForm, schema, s.style))));
    refreshConditions(styleForm, schema, s.style);
  }

  let fieldSeq = 0;
  /** One form control bound to obj[f.key]; path is used for error messages. */
  function field(f, obj, path, onAnyChange) {
    const id = `bf-${++fieldSeq}`;
    const wrap = h('div', { class: 'field', 'data-key': f.key, 'data-path': path });
    const set = (v) => { obj[f.key] = v; onAnyChange?.(); changed(); };
    const describedBy = [];
    const hint = f.help ? h('p', { class: 'hint', id: `${id}-hint`, text: f.help }) : null;
    if (hint) describedBy.push(`${id}-hint`);
    const lbl = () => h('label', { for: id, text: f.label + (f.required ? ' *' : '') });
    const value = obj[f.key];
    switch (f.type) {
      case 'checkbox': {
        const input = h('input', { type: 'checkbox', id, checked: Boolean(value), onchange: (e) => set(e.target.checked) });
        wrap.append(h('label', { class: 'check' }, input, ` ${f.label}`));
        break;
      }
      case 'textarea': {
        wrap.append(lbl(), h('textarea', { id, rows: 3, maxlength: f.max ?? null, oninput: (e) => set(e.target.value) }));
        wrap.querySelector('textarea').value = value ?? '';
        break;
      }
      case 'richtext': {
        const ta = h('textarea', { id, rows: 8, 'data-richtext': true, oninput: (e) => set(e.target.value) });
        ta.value = value ?? '';
        const editor = h('div', { class: 'rt-editor', contenteditable: 'true', role: 'textbox', 'aria-multiline': 'true', 'aria-labelledby': `${id}-lbl`, hidden: true });
        editor.replaceChildren(...safeNodes(value ?? ''));
        const rt = h('div', { class: 'rt', 'data-rt': true }, editor, ta);
        wrap.append(h('span', { class: 'label', id: `${id}-lbl`, text: f.label }), rt);
        initRichText(rt);
        break;
      }
      case 'select': {
        const sel = h('select', { id, onchange: (e) => set(e.target.value) },
          (f.options ?? []).map((o) => h('option', { value: o.value, text: o.label, selected: String(value ?? '') === o.value })));
        wrap.append(lbl(), sel);
        break;
      }
      case 'number': {
        wrap.append(lbl(), h('input', { type: 'number', id, min: f.min ?? null, max: f.max ?? null, value: value ?? '', oninput: (e) => set(e.target.value === '' ? '' : Number(e.target.value)) }));
        break;
      }
      case 'media': {
        const preview = h('div', { class: 'media-preview' });
        const paint = () => {
          const m = obj[f.key] ? state.media[String(obj[f.key])] : null;
          preview.replaceChildren(m ? h('img', { src: m.thumb, alt: '' }) : h('span', { class: 'muted', text: obj[f.key] ? `Image ${obj[f.key]}` : 'No image chosen' }));
          removeBtn.hidden = !obj[f.key];
        };
        const choose = h('button', { type: 'button', class: 'btn small ghost', id }, icon('image'), ' Choose image');
        const removeBtn = h('button', { type: 'button', class: 'btn small ghost', onclick: () => { set(null); paint(); } }, 'Remove');
        choose.addEventListener('click', async () => {
          const item = await pickImage(choose);
          if (!item) return;
          state.media[String(item.id)] = { thumb: item.thumb, title: item.title };
          set(item.id);
          paint();
        });
        wrap.classList.add('media-field');
        wrap.append(h('span', { class: 'label', text: f.label + (f.required ? ' *' : '') }), preview, h('div', { class: 'row-actions' }, choose, removeBtn));
        paint();
        break;
      }
      case 'repeater': {
        if (!Array.isArray(obj[f.key])) obj[f.key] = [];
        const items = obj[f.key];
        const fs = h('fieldset', { class: 'repeater' }, h('legend', { text: f.label }));
        const body = h('div', { class: 'repeater-items' });
        const addBtn = h('button', { type: 'button', class: 'btn small ghost' }, icon('plus'), ` ${f.add_label ?? 'Add item'}`);
        const paintItems = (focusIndex = null) => {
          body.replaceChildren(...items.map((item, i) => {
            const title = (f.item_label && item[f.item_label]) || `Item ${i + 1}`;
            const box = h('fieldset', { class: 'repeater-item' }, h('legend', { text: `${i + 1}. ${title}` }));
            f.fields.forEach((sub) => {
              const node = field(sub, item, `${path}.${i}.${sub.key}`, () => {
                f.fields.forEach((x) => { const n = box.querySelector(`:scope > [data-key="${x.key}"]`); if (n && x.show_if) n.hidden = !fieldActive(x, item); });
                if (sub.key === f.item_label) box.querySelector('legend').textContent = `${i + 1}. ${item[sub.key] || `Item ${i + 1}`}`;
              });
              if (sub.show_if) node.hidden = !fieldActive(sub, item);
              box.append(node);
            });
            box.append(h('div', { class: 'row-actions' },
              iconButton('arrow-up', `Move item ${i + 1} up`, () => { if (i > 0) { [items[i - 1], items[i]] = [items[i], items[i - 1]]; paintItems(i - 1); changed(); } }, { disabled: i === 0 }),
              iconButton('arrow-down', `Move item ${i + 1} down`, () => { if (i < items.length - 1) { [items[i + 1], items[i]] = [items[i], items[i + 1]]; paintItems(i + 1); changed(); } }, { disabled: i === items.length - 1 }),
              iconButton('trash', `Remove item ${i + 1}`, () => { items.splice(i, 1); paintItems(); changed(); announce('Item removed.'); }, { disabled: items.length <= (f.min ?? 0) })));
            return box;
          }));
          addBtn.disabled = f.max !== undefined && items.length >= f.max;
          if (focusIndex !== null) body.children[focusIndex]?.querySelector('input, select, textarea, button')?.focus();
          showErrors();
        };
        addBtn.addEventListener('click', () => {
          const item = {};
          f.fields.forEach((sub) => { item[sub.key] = sub.default ?? (sub.type === 'checkbox' ? false : sub.type === 'media' ? null : sub.type === 'select' ? (sub.options?.[0]?.value ?? '') : ''); });
          items.push(item);
          paintItems(items.length - 1);
          changed();
        });
        fs.append(hint ?? '', body, addBtn);
        wrap.append(fs);
        paintItems();
        return wrap;
      }
      default: {
        wrap.append(lbl(), h('input', { type: 'text', inputmode: f.type === 'url' ? 'url' : null, id, maxlength: f.max ?? (f.type === 'url' ? 500 : null), value: value ?? '', spellcheck: f.type === 'url' ? 'false' : null, oninput: (e) => set(e.target.value) }));
      }
    }
    if (hint) wrap.append(hint);
    const control = wrap.querySelector(`#${id}`);
    if (control && describedBy.length) control.setAttribute('aria-describedby', describedBy.join(' '));
    return wrap;
  }

  function showErrors() {
    root.querySelectorAll('.settings-form .error').forEach((n) => n.remove());
    root.querySelectorAll('.settings-form [aria-invalid]').forEach((n) => n.removeAttribute('aria-invalid'));
    const errs = state.selected ? state.errors[state.selected] : null;
    if (!errs) return;
    Object.entries(errs).forEach(([path, msg]) => {
      const box = root.querySelector(`.settings-form [data-path="${CSS.escape(path)}"]`);
      if (!box) return;
      const id = `err-${path.replace(/[^a-z0-9]/gi, '-')}`;
      const p = h('p', { class: 'error', id, text: msg });
      box.append(p);
      const control = box.querySelector('input, select, textarea, button');
      if (control) {
        control.setAttribute('aria-invalid', 'true');
        control.setAttribute('aria-describedby', `${control.getAttribute('aria-describedby') ?? ''} ${id}`.trim());
      }
    });
  }

  // ---- saving ----------------------------------------------------------------
  function changed() {
    if (state.conflict) return;
    state.dirty = true;
    setSaveState('Unsaved changes', 'dirty');
    window.clearTimeout(state.timer);
    state.timer = window.setTimeout(save, 900);
    if (!editView.hidden && state.selected) {
      const item = list.querySelector(`[data-id="${state.selected}"] .section-text .muted`);
      if (item) item.textContent = summary(find(state.selected)) || '';
    }
  }

  async function save() {
    window.clearTimeout(state.timer);
    if (state.conflict) return false;
    if (state.saving) { state.queued = true; return false; }
    state.saving = true;
    state.dirty = false;
    setSaveState('Saving…', 'saving');
    let ok = false;
    try {
      const res = await fetch(config.urls.save, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': csrf },
        body: JSON.stringify({ revision: state.revision, sections: state.sections }),
      });
      let data = null;
      try { data = await res.json(); } catch { data = null; }
      if (res.status === 409 && data?.conflict) {
        state.conflict = true;
        $('[data-conflict-text]').textContent = data.message;
        $('[data-conflict]').hidden = false;
        setSaveState('Not saved: someone else edited this page', 'error');
        root.classList.add('is-locked');
        return false;
      }
      if (!res.ok || !data) throw new Error(data?.error ?? `Saving failed (${res.status})`);
      state.revision = data.revision;
      root.querySelectorAll('[data-revision-input]').forEach((i) => { i.value = String(data.revision); });
      if (Array.isArray(data.ids)) {
        data.ids.forEach((newIdValue, i) => {
          const s = state.sections[i];
          if (s && s.id !== newIdValue) { if (state.selected === s.id) state.selected = newIdValue; s.id = newIdValue; }
        });
      }
      state.errors = data.errors && typeof data.errors === 'object' ? data.errors : {};
      const n = Object.keys(state.errors).length;
      setSaveState(n ? `Saved at ${data.saved_at}, but ${n} section${n === 1 ? ' needs' : 's need'} attention` : `Saved at ${data.saved_at}`, n ? 'error' : 'saved');
      $('[data-unpublished]').hidden = false;
      renderList();
      showErrors();
      reloadPreview();
      ok = true;
    } catch (err) {
      state.dirty = true;
      setSaveState(`${err instanceof Error ? err.message : 'Could not save'}. Retrying…`, 'error');
      state.timer = window.setTimeout(save, 5000);
    } finally {
      state.saving = false;
      if (state.queued) { state.queued = false; if (state.dirty) save(); }
    }
    return ok;
  }

  // ---- preview iframe --------------------------------------------------------
  let keepScroll = 0;
  function reloadPreview() {
    try { keepScroll = frame.contentWindow?.scrollY ?? 0; } catch { keepScroll = 0; }
    frame.contentWindow?.location.reload();
  }
  function highlightInFrame(scroll) {
    const doc = frame.contentDocument;
    if (!doc) return;
    doc.querySelectorAll('[data-section-id].is-selected').forEach((n) => n.classList.remove('is-selected'));
    if (!state.selected) return;
    const node = doc.querySelector(`[data-section-id="${state.selected}"]`);
    node?.classList.add('is-selected');
    if (scroll && node) node.scrollIntoView({ block: 'start', behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
  }
  frame.addEventListener('load', () => {
    const doc = frame.contentDocument;
    if (!doc) return;
    frame.contentWindow.scrollTo(0, keepScroll);
    doc.addEventListener('click', (e) => {
      if (!(e.target instanceof Element)) return;
      const sec = e.target.closest('[data-section-id]');
      if (e.target.closest('a, button, form, summary, input, select, textarea')) e.preventDefault();
      if (sec) select(sec.dataset.sectionId, false);
    }, true);
    doc.addEventListener('submit', (e) => e.preventDefault(), true);
    highlightInFrame(false);
  });

  // ---- picker ------------------------------------------------------------------
  function renderTemplates() {
    const ul = $('[data-template-list]');
    ul.replaceChildren(...state.templates.map((t) => h('li', {}, h('button', { type: 'button', onclick: () => { picker.close(); insert(clone(t.sections).map((s) => ({ ...s, id: newId() }))); } },
      thumb(t.sections[0]?.type ?? 'rich_text'), h('strong', { text: t.name }), h('span', { text: `${t.sections.length} section${t.sections.length === 1 ? '' : 's'}` })))));
    $('[data-no-templates]').hidden = state.templates.length > 0;
  }
  $('[data-open-picker]').addEventListener('click', () => { renderTemplates(); picker.showModal(); picker.querySelector('[data-add-type]')?.focus(); });
  $('[data-picker-close]').addEventListener('click', () => picker.close());
  picker.addEventListener('close', () => { if (editView.hidden) $('[data-open-picker]').focus(); });
  picker.querySelectorAll('[data-add-type]').forEach((b) => b.addEventListener('click', () => { picker.close(); add(b.dataset.addType); }));
  picker.addEventListener('click', (e) => { if (e.target === picker) picker.close(); });

  // ---- templates -----------------------------------------------------------
  async function saveTemplate(kind, sections) {
    const name = window.prompt(kind === 'page' ? 'Name for this page template' : 'Name for this section template');
    if (!name || !name.trim()) return;
    try {
      const res = await fetch(config.urls.template, {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': csrf },
        body: JSON.stringify({ name: name.trim(), kind, sections }),
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data?.error ?? 'Could not save the template.');
      if (kind === 'section') state.templates.push({ id: data.id, name: data.name, sections: data.sections });
      announce(`Template “${data.name}” saved.`);
      setSaveState(`Template “${data.name}” saved`, 'saved');
    } catch (err) {
      window.alert(err instanceof Error ? err.message : 'Could not save the template.');
    }
  }
  $('[data-save-page-template]').addEventListener('click', () => { if (state.sections.length) saveTemplate('page', state.sections); });
  $('[data-edit-template]').addEventListener('click', () => { const s = find(state.selected); if (s) saveTemplate('section', [s]); });
  $('[data-edit-duplicate]').addEventListener('click', () => { if (state.selected) { duplicate(state.selected); select(state.sections[indexOf(state.selected) + 1].id, true); } });
  $('[data-edit-delete]').addEventListener('click', () => { if (state.selected) remove(state.selected); });
  $('[data-back]').addEventListener('click', () => back(true));

  // ---- tabs, devices, publish, leaving -------------------------------------
  function showTab(tab) {
    const tablist = tab.closest('[role="tablist"]');
    tablist.querySelectorAll('[role="tab"]').forEach((t) => {
      const on = t === tab;
      t.setAttribute('aria-selected', String(on));
      t.tabIndex = on ? 0 : -1;
      const pane = document.getElementById(t.getAttribute('aria-controls'));
      if (pane) pane.hidden = !on;
    });
  }
  root.querySelectorAll('[role="tablist"]').forEach((tl) => {
    tl.addEventListener('click', (e) => { const t = e.target instanceof Element ? e.target.closest('[role="tab"]') : null; if (t) showTab(t); });
    tl.addEventListener('keydown', (e) => {
      if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') return;
      const tabs = [...tl.querySelectorAll('[role="tab"]')];
      const i = tabs.indexOf(document.activeElement);
      const next = tabs[(i + (e.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length];
      showTab(next);
      next.focus();
    });
  });
  root.querySelectorAll('[data-device]').forEach((b) => b.addEventListener('click', () => {
    root.querySelectorAll('[data-device]').forEach((x) => x.setAttribute('aria-pressed', String(x === b)));
    $('[data-canvas]').style.setProperty('--frame-width', b.dataset.device);
  }));
  $('[data-publish]').addEventListener('submit', async (e) => {
    if (!state.dirty && !state.saving) return;
    e.preventDefault();
    const btn = e.target.querySelector('button');
    btn.disabled = true;
    while (state.saving) await new Promise((r) => { window.setTimeout(r, 100); });
    const ok = state.dirty ? await save() : true;
    btn.disabled = false;
    if (ok && !state.conflict) e.target.submit();
  });
  window.addEventListener('beforeunload', (e) => { if (state.dirty || state.saving) { e.preventDefault(); e.returnValue = ''; } });
  root.querySelectorAll('form[data-confirm]').forEach((form) => form.addEventListener('submit', (e) => {
    if (state.dirty) { e.preventDefault(); window.alert('Please wait until your changes are saved, then try again.'); }
  }));

  renderList();
  setSaveState('All changes saved', 'saved');
}
