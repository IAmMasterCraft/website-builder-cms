// Radio admin: measure a file's duration in this browser at upload (or
// later from the stored file), place schedule items on the week grid, and
// show only the repeat fields that apply. Everything degrades: without
// JavaScript the duration stays unknown and the calendar is a list.
const fmt = (s) => {
  s = Math.round(s);
  const h = Math.floor(s / 3600);
  const m = Math.floor((s % 3600) / 60);
  const sec = String(s % 60).padStart(2, '0');
  return h ? `${h}:${String(m).padStart(2, '0')}:${sec}` : `${m}:${sec}`;
};

/** Read the duration of a media URL with a detached element. */
function measure(url, isVideo) {
  return new Promise((resolve, reject) => {
    const el = document.createElement(isVideo ? 'video' : 'audio');
    el.preload = 'metadata';
    el.muted = true;
    const done = (fn) => { clearTimeout(t); el.removeAttribute('src'); el.load(); fn(); };
    const t = setTimeout(() => done(() => reject(new Error('timeout'))), 20000);
    el.addEventListener('loadedmetadata', () => {
      // Some files report Infinity until the end is read: seek far to force it.
      if (el.duration === Infinity) {
        el.currentTime = 1e7;
        el.addEventListener('durationchange', () => { if (Number.isFinite(el.duration)) done(() => resolve(el.duration)); }, { once: true });
        return;
      }
      done(() => (Number.isFinite(el.duration) && el.duration > 0 ? resolve(el.duration) : reject(new Error('no duration'))));
    }, { once: true });
    el.addEventListener('error', () => done(() => reject(new Error('unreadable'))), { once: true });
    el.src = url;
  });
}

// Upload form: measure the chosen file before it is sent.
document.querySelectorAll('[data-radio-upload]').forEach((form) => {
  const file = form.querySelector('[data-measure-file]');
  const input = form.querySelector('[data-duration-input]');
  const out = form.querySelector('[data-duration-out]');
  const submit = form.querySelector('[type="submit"]');
  file?.addEventListener('change', async () => {
    input.value = '';
    const f = file.files?.[0];
    if (!f) return;
    const title = form.querySelector('input[name="title"]');
    if (title && !title.value) title.value = f.name.replace(/\.[^.]+$/, '').replace(/[-_]+/g, ' ');
    out.textContent = 'Measuring the length…';
    submit.disabled = true;
    const url = URL.createObjectURL(f);
    try {
      const d = await measure(url, f.type.startsWith('video/'));
      input.value = d.toFixed(3);
      out.textContent = `Length: ${fmt(d)} (measured by your browser).`;
    } catch {
      out.textContent = 'This browser could not read the length. You can upload it and measure it later, but it cannot be scheduled until then.';
    } finally {
      URL.revokeObjectURL(url);
      submit.disabled = false;
    }
  });
});

// Edit page: measure the stored file.
document.querySelectorAll('[data-measure-button]').forEach((btn) => {
  const panel = btn.closest('.panel-body');
  const media = panel.querySelector('[data-measure-src]');
  const input = panel.querySelector('[data-duration-input]');
  const out = panel.querySelector('[data-duration-out]');
  btn.hidden = false;
  btn.addEventListener('click', async () => {
    btn.disabled = true;
    out.textContent = 'Measuring…';
    try {
      const d = await measure(media.currentSrc || media.src, media.tagName === 'VIDEO');
      input.value = d.toFixed(3);
      out.textContent = `Measured ${fmt(d)}. Press Save to keep it.`;
    } catch {
      out.textContent = 'The length could not be read from this file.';
    } finally {
      btn.disabled = false;
    }
  });
});

// Repeat fields: days only for weekly, "until" only when repeating.
document.querySelectorAll('[data-repeat]').forEach((set) => {
  const update = () => {
    const rule = set.querySelector('input[name="repeat_rule"]:checked')?.value ?? 'none';
    set.querySelector('[data-repeat-days]').hidden = rule !== 'weekly';
    set.querySelector('[data-repeat-until]').hidden = rule === 'none';
  };
  set.addEventListener('change', update);
  update();
});

// Week calendar: position items by time of day (CSSOM; no inline style attributes).
document.querySelectorAll('[data-week-cal]').forEach((cal) => {
  cal.classList.add('is-grid');
  cal.querySelectorAll('.week-item').forEach((item) => {
    const start = Number(item.dataset.startMin);
    const len = Number(item.dataset.lenMin);
    item.style.top = `${(start / 1440) * 100}%`;
    item.style.height = `${Math.max((len / 1440) * 100, 1.6)}%`;
    if (len < 45) item.classList.add('is-short');
  });
  cal.querySelectorAll('[data-now-min]').forEach((col) => {
    const line = document.createElement('li');
    line.className = 'week-now';
    line.setAttribute('aria-hidden', 'true');
    line.style.top = `${(Number(col.dataset.nowMin) / 1440) * 100}%`;
    col.append(line);
  });
});
