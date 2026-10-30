// Direct messages (Addendum C3). Every page works without this file (forms
// and reloads); this adds: unread badges refreshed every 60 s, live
// recipient search, sending without a reload, polling the open thread every
// ~10 s (slower when the tab is hidden or the server fails) and read marks.
// Server text is only ever placed with textContent.

const csrf = () => document.querySelector('meta[name="csrf"]')?.content ?? '';

async function getJson(url) {
  const res = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin', cache: 'no-store' });
  const data = await res.json().catch(() => null);
  if (!res.ok || !data) throw new Error(data?.error ?? `Request failed (${res.status})`);
  return data;
}

async function postJson(url, body) {
  const res = await fetch(url, { method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-Token': csrf() }, body, credentials: 'same-origin' });
  const data = await res.json().catch(() => null);
  if (!res.ok || !data) throw new Error(data?.error ?? `Request failed (${res.status})`);
  return data;
}

// ---- Badges ---------------------------------------------------------------------

function setBadge(count) {
  document.querySelectorAll('[data-dm-badge]').forEach((badge) => {
    badge.textContent = String(count);
    badge.hidden = count <= 0;
  });
  document.querySelectorAll('[data-dm-badge-label]').forEach((label) => {
    const text = count > 0 ? `${count} unread message${count === 1 ? '' : 's'}` : '';
    // The account nav label reads ", 3 unread messages" after the link text.
    label.textContent = label.closest('.account-nav') ? (text ? `, ${text}` : '') : (text || 'Messages');
  });
}

function initBadges() {
  const badge = document.querySelector('[data-dm-badge][data-dm-src]');
  if (!badge) return;
  const src = badge.dataset.dmSrc;
  const refresh = async () => {
    if (document.hidden) return;
    try {
      const data = await getJson(src);
      if (typeof data.unread === 'number') setBadge(data.unread);
    } catch { /* keep the last count */ }
  };
  setInterval(refresh, 60000);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
}

// ---- Thread ----------------------------------------------------------------------

function buildMessage(root, m) {
  const base = root.dataset.base;
  const conv = root.dataset.conv;
  if (m.note) {
    const li = document.getElementById('dm-note-tpl').content.firstElementChild.cloneNode(true);
    li.querySelector('.dm-note-text').textContent = m.body;
    const t = li.querySelector('time');
    t.dateTime = m.created_iso;
    t.textContent = m.time_label;
    li.id = `m-${m.id}`;
    li.dataset.id = String(m.id);
    return li;
  }
  const li = document.getElementById('dm-msg-tpl').content.firstElementChild.cloneNode(true);
  li.id = `m-${m.id}`;
  li.dataset.id = String(m.id);
  if (m.mine) li.classList.add('is-mine');
  li.querySelector('.dm-sender').textContent = m.mine ? 'You' : m.sender;
  li.querySelector('.dm-role').hidden = !m.staff;
  const t = li.querySelector('time');
  t.dateTime = m.created_iso;
  t.textContent = m.time_label;
  li.querySelector('.dm-edited').hidden = !m.edited;
  const body = li.querySelector('.dm-body');
  if (m.deleted) {
    body.classList.add('dm-deleted');
    const em = document.createElement('em');
    em.textContent = m.deleted_label;
    body.append(em);
  } else if (m.parts.length) {
    m.parts.forEach(([type, value]) => {
      if (type === 'br') body.append(document.createElement('br'));
      else if (type === 'link') {
        const a = document.createElement('a');
        a.href = /^https?:\/\//i.test(value) ? value : `https://${value}`;
        a.rel = 'nofollow noopener noreferrer ugc';
        a.target = '_blank';
        a.textContent = value;
        body.append(a);
      } else body.append(document.createTextNode(value));
    });
  } else {
    body.remove();
  }
  if (m.attachment) {
    const p = li.querySelector('.dm-att');
    const a = p.querySelector('a');
    a.href = m.attachment.url;
    if (m.attachment.thumb) {
      const img = document.createElement('img');
      img.src = m.attachment.thumb;
      img.alt = `Photo: ${m.attachment.name} (opens in a new tab)`;
      img.width = 200;
      img.loading = 'lazy';
      a.append(img);
    } else {
      a.textContent = m.attachment.name;
      const sr = document.createElement('span');
      sr.className = 'sr-only';
      sr.textContent = ' (opens in a new tab)';
      a.append(sr);
    }
    p.hidden = false;
  }
  const actions = li.querySelector('.dm-actions');
  let any = false;
  [['edit', m.can_edit], ['delete', m.can_delete], ['report', m.can_report]].forEach(([act, allowed]) => {
    const a = actions.querySelector(`[data-act="${act}"]`);
    if (!allowed) { a.remove(); return; }
    a.hidden = false;
    a.href = `${base}/${conv}?act=${act}&m=${m.id}#m-${m.id}`;
    const sr = document.createElement('span');
    sr.className = 'sr-only';
    sr.textContent = act === 'report' ? ` message from ${m.sender}, ${m.time_label}` : ` your message from ${m.time_label}`;
    a.append(sr);
    any = true;
  });
  actions.hidden = !any;
  return li;
}

function initThread(root) {
  const list = root.querySelector('[data-dm-list]');
  const status = root.querySelector('[data-dm-status]');
  const readonly = root.dataset.readonly === '1';
  let last = Number(root.dataset.last) || 0;
  let since = root.dataset.since ?? '';
  let failures = 0;
  let hiddenRounds = 0;
  let timer = null;
  let reading = false;

  const atBottom = () => window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 160;

  const upsert = (m, fresh) => {
    const existing = document.getElementById(`m-${m.id}`);
    // Keep an inline edit/report form the person is using.
    if (existing && existing.querySelector('form')) return;
    const node = buildMessage(root, m);
    if (existing) existing.replaceWith(node);
    else {
      if (fresh) node.classList.add('is-new');
      list.append(node);
      root.querySelector('[data-dm-empty]')?.remove();
    }
  };

  const markRead = async () => {
    if (readonly || reading || document.hidden) return;
    reading = true;
    try {
      const body = new FormData();
      body.append('up_to', String(last));
      const data = await postJson(root.dataset.read, body);
      if (typeof data.unread === 'number') setBadge(data.unread);
      root.querySelector('[data-dm-read-form]')?.remove();
    } catch { /* the button still works */ } finally {
      reading = false;
    }
  };

  const schedule = () => {
    clearTimeout(timer);
    let delay = 10000;
    if (document.hidden) delay = Math.min(120000, 30000 * 2 ** Math.min(2, hiddenRounds));
    if (failures) delay = Math.min(120000, 10000 * 2 ** Math.min(4, failures));
    timer = setTimeout(poll, delay);
  };

  const poll = async () => {
    try {
      const url = `${root.dataset.poll}?after=${last}&since=${encodeURIComponent(since)}`;
      const data = await getJson(url);
      failures = 0;
      const follow = atBottom();
      (data.changed ?? []).forEach((m) => upsert(m, false));
      (data.messages ?? []).forEach((m) => upsert(m, true));
      if (data.last_id > last) last = data.last_id;
      if (data.since) since = data.since;
      if ((data.messages ?? []).some((m) => !m.mine)) {
        if (follow) list.lastElementChild?.scrollIntoView({ block: 'end', behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
        markRead();
      }
      if (typeof data.unread === 'number' && !(data.messages ?? []).length) setBadge(data.unread);
    } catch {
      failures += 1;
    }
    hiddenRounds = document.hidden ? hiddenRounds + 1 : 0;
    schedule();
  };

  document.addEventListener('visibilitychange', () => {
    if (!document.hidden) { hiddenRounds = 0; clearTimeout(timer); poll(); }
  });

  // Opening the thread marks it read (the "Mark as read" button without JavaScript).
  if (root.querySelector('[data-dm-read-form]')) markRead();
  schedule();

  const composer = root.querySelector('[data-dm-composer]');
  if (composer) {
    const textarea = composer.querySelector('textarea');
    const send = composer.querySelector('[data-dm-send]');
    textarea.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); composer.requestSubmit(); }
    });
    composer.addEventListener('submit', async (e) => {
      e.preventDefault();
      if (send.disabled) return;
      send.disabled = true;
      status.textContent = 'Sending…';
      try {
        const data = await postJson(composer.action, new FormData(composer));
        upsert(data.message, true);
        if (data.message.id > last) last = data.message.id;
        textarea.value = '';
        const file = composer.querySelector('input[type="file"]');
        if (file) file.value = '';
        status.textContent = 'Sent.';
        setTimeout(() => { if (status.textContent === 'Sent.') status.textContent = ''; }, 3000);
        list.lastElementChild?.scrollIntoView({ block: 'end' });
        textarea.focus();
      } catch (err) {
        status.textContent = err.message || 'The message could not be sent. Please try again.';
      } finally {
        send.disabled = false;
      }
    });
  }

  // Links from the conversation list end in #latest: show the newest message.
  if (location.hash === '#latest') list.lastElementChild?.scrollIntoView({ block: 'end' });
}

// ---- New message: live recipient search --------------------------------------------

function initPicker(form) {
  const q = form.querySelector('[data-dm-q]');
  const results = form.querySelector('[data-dm-results]');
  const selectedBox = form.querySelector('[data-dm-selected-box]');
  const selected = form.querySelector('[data-dm-selected]');
  const announce = form.querySelector('[data-dm-results-status]');
  const team = form.querySelector('[data-dm-team]');
  const titleField = form.querySelector('[data-dm-title-field]');
  if (!q || !results) return;

  const chosen = () => [...selected.querySelectorAll('input[name="to[]"]:checked')];
  const updateTitle = () => {
    if (!titleField) return;
    const many = chosen().length > 1 || form.querySelector('input[name="announcement"]')?.checked;
    titleField.hidden = !many && !(titleField.querySelector('input').value);
  };
  const row = (r) => {
    const label = document.createElement('label');
    label.className = 'check-line';
    const input = document.createElement('input');
    input.type = 'checkbox';
    input.name = 'to[]';
    input.value = String(r.id);
    const span = document.createElement('span');
    span.textContent = r.name;
    if (r.number) {
      const n = document.createElement('span');
      n.className = 'muted';
      n.textContent = ` ${r.number}`;
      span.append(n);
    }
    if (r.kind === 'staff') {
      const b = document.createElement('span');
      b.className = 'dm-role';
      b.textContent = 'Admin';
      span.append(' ', b);
    }
    label.append(input, ' ', span);
    return label;
  };
  const move = (e) => {
    const input = e.target;
    if (!(input instanceof HTMLInputElement) || input.name !== 'to[]') return;
    const label = input.closest('label');
    if (input.checked && results.contains(label)) {
      selected.append(label);
      selectedBox.hidden = false;
      if (team) team.checked = false;
      announce.textContent = `${label.textContent.trim()} added.`;
    } else if (!input.checked && selected.contains(label)) {
      label.remove();
      selectedBox.hidden = chosen().length === 0;
      announce.textContent = `${label.textContent.trim()} removed.`;
    }
    updateTitle();
  };
  results.addEventListener('change', move);
  selected.addEventListener('change', move);
  form.querySelector('input[name="announcement"]')?.addEventListener('change', updateTitle);
  team?.addEventListener('change', () => {
    if (team.checked) { selected.replaceChildren(); selectedBox.hidden = true; updateTitle(); }
  });

  let seq = 0;
  let debounce = null;
  const search = async () => {
    const term = q.value.trim();
    const mine = ++seq;
    try {
      const data = await getJson(`${form.dataset.recipients}?q=${encodeURIComponent(term)}`);
      if (mine !== seq) return;
      const have = new Set(chosen().map((i) => i.value));
      const list = (data.results ?? []).filter((r) => !have.has(String(r.id)));
      results.replaceChildren();
      if (list.length) {
        const head = document.createElement('p');
        head.className = 'dm-sub';
        head.textContent = term ? 'Results' : 'Admins you can message';
        results.append(head, ...list.map(row));
      } else if (term) {
        const p = document.createElement('p');
        p.className = 'muted';
        p.textContent = 'Nobody found that you can message.';
        results.append(p);
      }
      announce.textContent = term ? `${list.length} ${list.length === 1 ? 'person' : 'people'} found.` : '';
    } catch {
      announce.textContent = 'Search is not available right now. Use the Search button.';
    }
  };
  q.addEventListener('input', () => { clearTimeout(debounce); debounce = setTimeout(search, 250); });
  form.querySelector('[data-dm-find]')?.addEventListener('click', (e) => { e.preventDefault(); search(); });
  q.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); search(); } });
  updateTitle();
}

export function initMessages() {
  initBadges();
  document.querySelectorAll('[data-dm-thread]').forEach(initThread);
  document.querySelectorAll('form[data-dm-new]').forEach(initPicker);
}
